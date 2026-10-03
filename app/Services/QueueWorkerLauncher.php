<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Starts a short-lived queue worker so handed-off bulk jobs can finish without Supervisor.
 */
class QueueWorkerLauncher
{
    public function ensureRunning(): void
    {
        $lock = Cache::lock('mailin-queue-worker-spawn', 15);

        if (! $lock->get()) {
            return;
        }

        try {
            $php = PHP_BINARY;
            $artisan = base_path('artisan');
            $flags = 'queue:work --stop-when-empty --sleep=1 --tries=3 --timeout=120';

            if (PHP_OS_FAMILY === 'Windows') {
                $command = 'cmd /c start /B "" '.escapeshellarg($php).' '.escapeshellarg($artisan).' '.$flags;
                pclose(popen($command, 'r'));

                return;
            }

            $command = sprintf(
                'nohup %s %s %s > /dev/null 2>&1 &',
                escapeshellarg($php),
                escapeshellarg($artisan),
                $flags,
            );
            exec($command);
        } finally {
            $lock->release();
        }
    }
}
