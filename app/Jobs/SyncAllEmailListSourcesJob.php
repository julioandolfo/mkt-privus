<?php

namespace App\Jobs;

use App\Models\EmailListSource;
use App\Services\Email\EmailListSyncService;
use App\Models\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAllEmailListSourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1200;

    public function handle(EmailListSyncService $service): void
    {
        $sources = EmailListSource::needingSync()->get();
        $synced = 0;
        $errors = 0;

        foreach ($sources as $source) {
            try {
                $result = $service->syncSource($source);
                if ($result['success']) {
                    $synced++;
                } else {
                    $errors++;
                }
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        SystemLog::info('email', 'sources.sync_all', "Sincronização: {$synced} fontes OK, {$errors} erros", [
            'total' => $sources->count(),
            'synced' => $synced,
            'errors' => $errors,
        ]);
    }
}
