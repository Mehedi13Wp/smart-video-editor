<?php
namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Project;
use App\Models\RemixJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RemixController
{
    private function ownership(Project $project, Media $media, ?RemixJob $remix = null): void {
        abort_unless($media->project_id === $project->id, 404);
        if ($remix) abort_unless($remix->media_id === $media->id && $remix->project_id === $project->id, 404);
    }

    public function store(Request $request, Project $project, Media $media) {
        $this->ownership($project, $media);
        $values = $request->validate(['target_duration' => 'required|integer|min:1|max:900', 'variant' => 'nullable|in:a,b', 'authorized_footage' => 'required|accepted']);
        $variant = $values['variant'] ?? 'a';
        abort_unless(Storage::exists($media->path), 404);
        // SQLite transaction serializes creation; double clicks cannot enqueue duplicate active jobs.
        $job = DB::transaction(function () use ($project, $media, $values, $variant) {
            $existing = RemixJob::where('media_id', $media->id)->where('variant', $variant)->whereIn('status', ['pending', 'running'])->first();
            if ($existing) return $existing;
            return RemixJob::create(['project_id' => $project->id, 'media_id' => $media->id, 'variant' => $variant, 'target_duration' => $values['target_duration']]);
        });
        return response()->json($job->fresh(), 202);
    }

    public function index(Project $project, Media $media) {
        $this->ownership($project, $media);
        return response()->json(RemixJob::where('media_id', $media->id)->latest('id')->limit(10)->get());
    }

    public function show(Project $project, Media $media, RemixJob $remix) {
        $this->ownership($project, $media, $remix);
        return response()->json($remix);
    }

    public function stream(Request $request, Project $project, Media $media, RemixJob $remix) {
        $this->ownership($project, $media, $remix);
        $path = realpath(Storage::path($remix->resultPath()));
        abort_unless($remix->status === 'completed' && $remix->output_path === $remix->resultPath()
            && $path !== false && is_file($path) && is_readable($path), 404);
        $headers = ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff'];
        if ($request->boolean('download')) return response()->download($path, 'football-highlights-version-'.$remix->variant.'-'.$remix->id.'.mp4', $headers);
        return response()->file($path, $headers);
    }
}
