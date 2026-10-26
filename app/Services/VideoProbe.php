<?php
namespace App\Services;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
class VideoProbe
{
    public function inspect(string $path): array
    {
        $binary = config('video.ffprobe_path');
        if (!is_file($binary)) {
            throw ValidationException::withMessages(['video' => 'FFprobe is unavailable. Check FFPROBE_PATH in .env.']);
        }
        // No shell interpolation; refuse network protocols even inside uploaded playlists.
        $process = new Process([$binary, '-v', 'error', '-protocol_whitelist', 'file,pipe',
            '-show_format', '-show_streams', '-of', 'json', $path]);
        $process->setTimeout(30);
        try { $process->mustRun(); $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) {
            throw ValidationException::withMessages(['video' => 'This file could not be read as a valid video.']);
        }
        $stream = collect($data['streams'] ?? [])->first(fn ($s) => ($s['codec_type'] ?? '') === 'video' && empty($s['disposition']['attached_pic']));
        $durations = array_filter([$data['format']['duration'] ?? null, $stream['duration'] ?? null], 'is_numeric');
        $duration = $durations ? max(array_map('floatval', $durations)) : 0;
        if (!$stream || !is_finite($duration) || $duration <= 0 || empty($stream['width']) || empty($stream['height'])) {
            throw ValidationException::withMessages(['video' => 'A playable video stream with a known duration is required.']);
        }
        if ($duration > config('video.max_duration')) {
            throw ValidationException::withMessages(['video' => 'Source videos must be 60 minutes (3600 seconds) or shorter.']);
        }
        return ['duration' => $duration, 'width' => (int) $stream['width'],
            'height' => (int) $stream['height'], 'codec' => $stream['codec_name'] ?? 'unknown'];
    }
}
