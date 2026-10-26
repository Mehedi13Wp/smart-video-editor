<?php
namespace App\Services;

use App\Models\RemixJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RemixFiles
{
    // Call inside the same DB transaction as deletion, so a queued job cannot be claimed midway.
    public static function removeForMedia(array $ids): void {
        $jobs = RemixJob::whereIn('media_id', $ids)->get();
        if ($jobs->contains('status', 'running')) {
            throw ValidationException::withMessages(['remix' => 'A highlights render is running. Wait for it to finish before deleting this footage.']);
        }
        foreach ($jobs as $job) Storage::deleteDirectory($job->directory());
    }
}
