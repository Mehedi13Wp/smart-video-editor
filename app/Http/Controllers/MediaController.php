<?php
namespace App\Http\Controllers;
use App\Models\Media;
use App\Models\Project;
use App\Services\VideoProbe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class MediaController
{
    private const VIDEO_EXTENSIONS = ['mp4','mov','webm','mkv','avi','mpeg','mpg','ogg'];

    public function localImports(Project $project) {
        $root = Storage::path('incoming');
        if (!is_dir($root)) @mkdir($root, 0775, true);
        $files = [];
        foreach (Storage::files('incoming') as $path) {
            $name = basename($path);
            $absolute = Storage::path($path);
            $real = realpath($absolute);
            if (!$real || is_link($absolute) || dirname($real) !== realpath($root) || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true)) continue;
            $size = filesize($real);
            if ($size > config('video.max_upload_kb') * 1024) continue;
            $files[] = ['name' => $name, 'size' => $size, 'already_added' => $project->media()->where('path', $path)->exists()];
        }
        usort($files, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return response()->json($files);
    }

    public function importLocal(Request $request, Project $project, VideoProbe $probe) {
        $name = $request->validate(['filename' => ['required', 'string', 'max:255']])['filename'];
        abort_unless($name === basename($name) && !str_contains($name, '/') && !str_contains($name, '\\') &&
            in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true), 422);
        $path = 'incoming/'.$name;
        $absolute = Storage::path($path);
        $root = realpath(Storage::path('incoming'));
        $real = realpath($absolute);
        abort_unless($root && $real && !is_link($absolute) && dirname($real) === $root && is_file($real), 404);
        if (filesize($real) > config('video.max_upload_kb') * 1024) {
            throw \Illuminate\Validation\ValidationException::withMessages(['filename' => 'Local videos must be 2 GB or smaller.']);
        }
        if ($project->media()->where('path', $path)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['filename' => 'This file is already added to the project.']);
        }
        $metadata = $probe->inspect($real);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($real) ?: 'application/octet-stream';
        $project->media()->create([...$metadata, 'original_name' => mb_substr($name, 0, 255), 'path' => $path,
            'mime_type' => $mime, 'size' => filesize($real), 'trim_start' => 0, 'trim_end' => $metadata['duration']]);
        $project->touch();
        return back()->with('success', 'Video added from the local folder. The inbox original stays in place.');
    }

    public function store(Request $request, Project $project, VideoProbe $probe) {
        $request->validate(['video' => ['required', 'file', 'max:'.config('video.max_upload_kb'), 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska,video/x-msvideo,video/mpeg,video/ogg']]);
        $file = $request->file('video');
        $metadata = $probe->inspect($file->getRealPath());
        $path = $file->store('projects/'.$project->id);
        try {
            $project->media()->create([...$metadata, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
                'trim_start' => 0, 'trim_end' => $metadata['duration']]);
        } catch (\Throwable $e) { Storage::delete($path); throw $e; }
        $project->touch();
        return back()->with('success', 'Video uploaded and validated.');
    }
    public function stream(Project $project, Media $media) {
        $this->checkProject($project, $media);
        abort_unless(Storage::exists($media->path), 404);
        // BinaryFileResponse supports HTTP byte ranges for seeking in HTML5 video.
        return response()->file(Storage::path($media->path), ['Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
    public function update(Request $request, Project $project, Media $media) {
        $this->checkProject($project, $media);
        $values = $request->validate(['trim_start' => ['required', 'numeric', 'min:0', 'lt:trim_end'],
            'trim_end' => ['required', 'numeric', 'gt:trim_start', 'max:'.$media->duration]]);
        $media->update($values); $project->touch();
        return back()->with('success', 'Trim saved. Original video preserved.');
    }
    public function destroy(Project $project, Media $media) {
        $this->checkProject($project, $media);
        \Illuminate\Support\Facades\DB::transaction(function () use ($project, $media) {
            \App\Services\RemixFiles::removeForMedia([$media->id]);
            if (!$media->isInboxSource()) Storage::delete($media->path);
            $media->delete(); $project->touch();
        });
        return back()->with('success', 'Video removed.');
    }
    private function checkProject(Project $project, Media $media): void { abort_unless($media->project_id === $project->id, 404); }
}
