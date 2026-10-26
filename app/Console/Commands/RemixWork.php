<?php
namespace App\Console\Commands;

use App\Models\RemixJob;
use App\Services\RemixProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RemixWork extends Command
{
    protected $signature = 'remix:work {--once : Process one pending job and exit}';
    protected $description = 'Process local football remix jobs serially, outside HTTP requests';

    public function handle(RemixProcessor $processor): int
    {
        $lock = fopen(config('remix.lock_path'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('A remix worker is already running.');
            return self::FAILURE;
        }
        try {
            $this->info('Remix worker ready. One CPU render at a time.');
            do {
                // Recheck during idle polling too, not only at startup. A restarted worker
                // waits out a possibly orphaned renderer before taking another job.
                foreach (RemixJob::where('status', 'running')->where('updated_at', '<', now()->subSeconds(config('remix.timeout') + 60))->get() as $stale) {
                    $stale->update(['status' => 'failed', 'error' => 'The worker was interrupted. Generate a new remix to retry.']);
                    Storage::deleteDirectory($stale->directory());
                }
                $job = RemixJob::where('status', 'running')->exists() ? null : RemixJob::where('status', 'pending')->oldest('id')->first();
                if ($job) $processor->process($job);
                if ($this->option('once')) break;
                if (!$job) sleep(2);
            } while (true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return self::SUCCESS;
    }
}
