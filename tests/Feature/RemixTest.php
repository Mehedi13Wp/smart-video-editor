<?php
namespace Tests\Feature;

use App\Models\Media;
use App\Models\Project;
use App\Models\RemixJob;
use App\Services\RemixProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RemixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp(); $this->withoutVite(); Storage::fake('local');
        config(['remix.lock_path' => Storage::path('remix-worker.lock')]);
    }

    private function media(?Project $project = null): Media {
        $project ??= Project::create(['name' => 'Football']);
        $media = $project->media()->create(['original_name' => 'match.mp4', 'path' => 'projects/'.$project->id.'/source.mp4',
            'mime_type' => 'video/mp4', 'size' => 100, 'duration' => 2, 'width' => 64, 'height' => 64,
            'codec' => 'h264', 'trim_start' => 0, 'trim_end' => 2]);
        Storage::put($media->path, 'placeholder');
        return $media;
    }

    private function url(Media $media): string { return "/projects/{$media->project_id}/media/{$media->id}/remixes"; }

    public function test_creation_validation_and_duplicate_active_job(): void {
        $media = $this->media(); $url = $this->url($media);
        foreach ([0, 901, -1, 1.5, 'bad', null] as $duration) {
            $this->postJson($url, ['target_duration' => $duration, 'authorized_footage' => true])->assertUnprocessable();
        }
        $this->postJson($url, ['target_duration' => 30, 'authorized_footage' => false])->assertUnprocessable();
        $job = $this->postJson($url, ['target_duration' => 30, 'authorized_footage' => true])
            ->assertStatus(202)->assertJsonPath('status', 'pending')->assertJsonPath('progress', 0)->assertJsonPath('variant', 'a')
            ->assertJsonMissingPath('output_path')->json();
        $this->assertDatabaseHas('remix_jobs', ['id' => $job['id'], 'project_id' => $media->project_id, 'media_id' => $media->id]);
        $this->postJson($url, ['target_duration' => 60, 'authorized_footage' => true])->assertJsonPath('id', $job['id']);
        $variantB = $this->postJson($url, ['target_duration' => 900, 'variant' => 'b', 'authorized_footage' => true])
            ->assertStatus(202)->assertJsonPath('variant', 'b')->assertJsonPath('target_duration', 900)->json();
        $this->assertNotSame($job['id'], $variantB['id']);
        $this->assertDatabaseCount('remix_jobs', 2);
        $this->getJson($url.'/'.$job['id'])->assertOk()->assertJsonPath('status', 'pending');
        $this->getJson($url)->assertOk()->assertJsonCount(2);
        $this->postJson($url, ['target_duration' => 20, 'variant' => 'c', 'authorized_footage' => true])->assertUnprocessable();
        $this->get($url.'/'.$job['id'].'/stream')->assertNotFound();
        Storage::delete($media->path);
        $this->postJson($url, ['target_duration' => 5, 'authorized_footage' => true])->assertNotFound();
    }

    public function test_cross_project_and_cross_media_ownership_is_enforced_on_every_endpoint(): void {
        $media = $this->media(); $other = $this->media();
        $job = RemixJob::create(['project_id' => $media->project_id, 'media_id' => $media->id, 'target_duration' => 1]);
        $wrong = "/projects/{$other->project_id}/media/{$media->id}/remixes";
        $this->postJson($wrong, ['target_duration' => 1, 'authorized_footage' => true])->assertNotFound();
        foreach ([$wrong, $wrong.'/'.$job->id, $wrong.'/'.$job->id.'/stream', $this->url($other).'/'.$job->id,
            $this->url($other).'/'.$job->id.'/stream?download=1'] as $url) $this->getJson($url)->assertNotFound();
        $sameProject = $this->media($media->project);
        $this->getJson($this->url($sameProject).'/'.$job->id)->assertNotFound();
    }

    public function test_worker_renders_real_mp4_and_serves_range_and_download(): void {
        $media = $this->media();
        (new Process([config('video.ffmpeg_path'), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=green:s=64x64:r=25',
            '-f', 'lavfi', '-i', 'sine=frequency=800:sample_rate=48000', '-t', '2', '-c:v', 'libx264', '-c:a', 'aac', Storage::path($media->path)]))->mustRun();
        $original = hash_file('sha256', Storage::path($media->path));
        $job = RemixJob::create(['project_id' => $media->project_id, 'media_id' => $media->id, 'target_duration' => 1]);
        $transitions = [];
        RemixJob::updated(function (RemixJob $changed) use (&$transitions) { $transitions[] = [$changed->status, $changed->progress]; });
        $this->artisan('remix:work', ['--once' => true])->assertExitCode(0);
        $job->refresh();
        $this->assertSame('completed', $job->status, $job->error ?? '');
        $this->assertSame(100, $job->progress);
        $this->assertTrue(collect($transitions)->contains(fn ($value) => $value[0] === 'running' && $value[1] < 100));
        $this->assertSame($original, hash_file('sha256', Storage::path($media->path)));
        $result = Storage::path($job->resultPath());
        $probe = new Process([config('video.ffprobe_path'), '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $result]);
        $probe->mustRun(); $data = json_decode($probe->getOutput(), true);
        $this->assertEqualsCanonicalizing(['h264', 'aac'], array_column($data['streams'], 'codec_name'));
        $this->assertEqualsWithDelta(1, (float) $data['format']['duration'], .15);
        // Decode the complete result, rather than only trusting its container header.
        (new Process([config('video.ffmpeg_path'), '-v', 'error', '-i', $result, '-f', 'null', '-']))->mustRun();
        $url = $this->url($media).'/'.$job->id;
        $this->getJson($url)->assertJsonPath('status', 'completed')->assertJsonMissingPath('output_path');
        $this->get($url.'/stream', ['Range' => 'bytes=0-9'])->assertStatus(206)->assertHeader('Content-Length', '10');
        $this->get($url.'/stream?download=1')->assertOk()->assertDownload('football-highlights-version-a-'.$job->id.'.mp4');
        app(RemixProcessor::class)->process($job); // Completed jobs must not render again.
        $this->assertSame('completed', $job->fresh()->status);
        $this->delete('/projects/'.$media->project_id.'/media/'.$media->id)->assertRedirect();
        $this->assertDatabaseCount('remix_jobs', 0);
        Storage::assertMissing($job->resultPath());
    }

    public function test_failed_render_returns_safe_error_and_can_be_retried(): void {
        $media = $this->media();
        $job = RemixJob::create(['project_id' => $media->project_id, 'media_id' => $media->id, 'target_duration' => 1]);
        app(RemixProcessor::class)->process($job);
        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertNull($job->output_path);
        $this->assertStringNotContainsString(base_path(), $job->error);
        Storage::assertMissing($job->resultPath());
        $this->postJson($this->url($media), ['target_duration' => 2, 'authorized_footage' => true])->assertStatus(202)->assertJsonPath('status', 'pending');
        $this->assertDatabaseCount('remix_jobs', 2);
    }

    public function test_running_job_blocks_deletion_and_pending_job_is_removed_with_project(): void {
        $media = $this->media();
        $job = RemixJob::create(['project_id' => $media->project_id, 'media_id' => $media->id, 'target_duration' => 1, 'status' => 'running']);
        $this->deleteJson('/projects/'.$media->project_id.'/media/'.$media->id)->assertUnprocessable();
        $this->deleteJson('/projects/'.$media->project_id)->assertUnprocessable();
        Storage::assertExists($media->path);
        $job->update(['status' => 'pending']);
        $this->delete('/projects/'.$media->project_id)->assertRedirect();
        $this->assertDatabaseCount('remix_jobs', 0);
    }

    public function test_interrupted_job_is_marked_failed_when_worker_restarts(): void {
        $media = $this->media();
        $job = RemixJob::create(['project_id' => $media->project_id, 'media_id' => $media->id, 'target_duration' => 1, 'status' => 'running']);
        $job->update(['updated_at' => now()->subSeconds(config('remix.timeout') + 120)]);
        $this->artisan('remix:work', ['--once' => true])->assertExitCode(0);
        $this->assertSame('failed', $job->fresh()->status);
    }
}
