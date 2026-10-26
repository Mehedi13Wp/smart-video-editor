<?php
namespace Tests\Feature;
use App\Models\Project;
use App\Services\VideoProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
class StudioTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->withoutVite(); Storage::fake('local'); }
    private function fixture(int $seconds = 2, bool $audioOnly = false): string {
        $path = Storage::path('fixture-'.uniqid().($audioOnly ? '.wav' : '.mp4'));
        $command = $audioOnly ? ['-f','lavfi','-i','sine=frequency=1000','-t',(string)$seconds] :
            ['-f','lavfi','-i','color=c=green:s=64x64:r=1','-t',(string)$seconds,'-c:v','libx264','-pix_fmt','yuv420p'];
        (new Process([config('video.ffmpeg_path'),'-hide_banner','-loglevel','error','-y',...$command,$path]))->setTimeout(60)->mustRun();
        return $path;
    }
    public function test_project_creation_update_and_delete(): void {
        $this->post('/projects', ['name'=>'First cut','description'=>'A local project'])->assertRedirect();
        $project = Project::firstOrFail();
        $this->get('/')->assertOk();
        $this->get('/projects/'.$project->id)->assertOk();
        $this->patch('/projects/'.$project->id, ['name'=>'Updated','description'=>'Notes'])->assertRedirect();
        $this->assertDatabaseHas('projects',['name'=>'Updated']);
        $this->delete('/projects/'.$project->id)->assertRedirect('/');
        $this->assertDatabaseCount('projects',0);
    }
    public function test_upload_persists_real_metadata_stream_supports_ranges_and_trim_is_saved(): void {
        $project = Project::create(['name'=>'Test']);
        $file = new UploadedFile($this->fixture(), 'clip.mp4', 'video/mp4', null, true);
        $this->post('/projects/'.$project->id.'/media',['video'=>$file])->assertSessionHasNoErrors()->assertRedirect();
        $media = $project->media()->firstOrFail();
        $this->assertEquals(2, $media->duration);
        Storage::assertExists($media->path);
        $url = '/projects/'.$project->id.'/media/'.$media->id;
        $this->get($url.'/stream',['Range'=>'bytes=0-9'])->assertStatus(206)->assertHeader('Content-Length','10');
        $this->patch($url,['trim_start'=>0.5,'trim_end'=>1.5])->assertSessionHasNoErrors();
        $this->assertEquals(0.5,$media->fresh()->trim_start);
        $this->patch($url,['trim_start'=>1.5,'trim_end'=>1])->assertSessionHasErrors();
        $this->patch($url,['trim_start'=>-1,'trim_end'=>1])->assertSessionHasErrors();
        $this->patch($url,['trim_start'=>0,'trim_end'=>3])->assertSessionHasErrors();
        $other = Project::create(['name'=>'Other']);
        $this->get('/projects/'.$other->id.'/media/'.$media->id.'/stream')->assertNotFound();
        $this->patch('/projects/'.$other->id.'/media/'.$media->id,['trim_start'=>0,'trim_end'=>1])->assertNotFound();
        $this->delete('/projects/'.$other->id.'/media/'.$media->id)->assertNotFound();
        $path = $media->path;
        $this->delete('/projects/'.$project->id)->assertRedirect('/');
        $this->assertDatabaseCount('media',0);
        Storage::assertMissing($path);
    }
    public function test_duration_boundary_and_rejection_of_audio_and_corrupt_files(): void {
        $probe = app(VideoProbe::class);
        $this->assertEquals(3600, $probe->inspect($this->fixture(3600))['duration']);
        $corrupt = Storage::path('corrupt.mp4'); file_put_contents($corrupt,'not a video');
        foreach ([$this->fixture(3601), $this->fixture(2,true), $corrupt] as $path) {
            try { $probe->inspect($path); $this->fail('Invalid video was accepted.'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('video',$e->errors()); }
        }
    }
    public function test_rejected_upload_leaves_no_media_record(): void {
        $project = Project::create(['name'=>'Test']);
        $file = new UploadedFile($this->fixture(3601), 'long.mp4', 'video/mp4', null, true);
        $this->post('/projects/'.$project->id.'/media',['video'=>$file])->assertSessionHasErrors('video');
        $this->assertDatabaseCount('media',0);
        $this->assertSame([], Storage::allFiles('projects'));
    }
    public function test_local_folder_video_is_imported_without_upload_and_original_is_preserved(): void {
        $project = Project::create(['name'=>'Local folder import']);
        Storage::makeDirectory('incoming');
        $inboxPath = 'incoming/match-copy.mp4';
        copy($this->fixture(), Storage::path($inboxPath));
        $this->getJson('/projects/'.$project->id.'/local-imports')->assertOk()->assertJsonPath('0.name','match-copy.mp4')->assertJsonPath('0.already_added',false);
        $this->post('/projects/'.$project->id.'/local-imports',['filename'=>'match-copy.mp4'])->assertRedirect()->assertSessionHasNoErrors();
        $media = $project->media()->firstOrFail();
        $this->assertSame($inboxPath,$media->path);
        Storage::assertExists($inboxPath);
        $this->get('/projects/'.$project->id.'/media/'.$media->id.'/stream')->assertOk();
        $this->getJson('/projects/'.$project->id.'/local-imports')->assertJsonPath('0.already_added',true);
        $this->delete('/projects/'.$project->id.'/media/'.$media->id)->assertRedirect();
        Storage::assertExists($inboxPath);
        $this->assertDatabaseCount('media',0);
    }
}
