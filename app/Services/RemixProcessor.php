<?php
namespace App\Services;

use App\Models\RemixJob;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class RemixProcessor
{
    public function process(RemixJob $job): void
    {
        // Atomic claim also protects against duplicate delivery.
        if (!RemixJob::whereKey($job->id)->where('status', 'pending')->update(['status' => 'running', 'progress' => 1, 'updated_at' => now()])) return;
        $job->refresh();
        try {
            $media = $job->media;
            if (!$media || $media->project_id !== $job->project_id || !Storage::exists($media->path)) {
                throw new \RuntimeException('Source unavailable');
            }
            Storage::makeDirectory($job->directory());
            $process = new Process([
                config('remix.python_path'), base_path('processing/football_remix.py'),
                '--input', Storage::path($media->path), '--output', Storage::path($job->resultPath()),
                '--target', (string) $job->target_duration,
                '--variant', $job->variant,
                '--ffmpeg', config('video.ffmpeg_path'), '--ffprobe', config('video.ffprobe_path'),
            ], base_path());
            $process->setTimeout(config('remix.timeout'));
            $buffer = '';
            $process->mustRun(function ($type, $chunk) use ($job, &$buffer) {
                if ($type !== Process::OUT) return;
                $buffer .= $chunk;
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $event = json_decode($line, true);
                    if (isset($event['progress']) && is_numeric($event['progress'])) {
                        $job->update(['progress' => max($job->progress, min(99, (int) $event['progress']))]);
                    }
                }
            });
            $resultPath = Storage::path($job->resultPath());
            if (PHP_OS_FAMILY === 'Windows') {
                // FFmpeg-created files may inherit a restricted token ACL instead of the
                // private storage directory's ACL. Restore directory inheritance so PHP
                // can stream and later remove the completed export.
                (new Process(['icacls', $resultPath, '/inheritance:e']))->setTimeout(10)->mustRun();
                clearstatcache(true, $resultPath);
            }
            if (!is_readable($resultPath)) throw new \RuntimeException('Rendered output is not readable by the web process');
            if (!Storage::exists($job->resultPath()) || Storage::size($job->resultPath()) === 0) throw new \RuntimeException('No output');
            $analysis = json_decode(Storage::get($job->directory().'/highlights.json'), true, 512, JSON_THROW_ON_ERROR);
            $job->update(['status' => 'completed', 'progress' => 100, 'output_path' => $job->resultPath(), 'analysis' => $analysis, 'error' => null]);
        } catch (\Throwable $e) {
            // Fixed public error; never return subprocess stderr or filesystem paths.
            $job->update(['status' => 'failed', 'output_path' => null, 'error' => 'Processing failed. Check Python, FFmpeg and the source video, then try again.']);
            Storage::deleteDirectory($job->directory());
        }
    }
}
