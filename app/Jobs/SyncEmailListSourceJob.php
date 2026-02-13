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

class SyncEmailListSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(
        public int $sourceId,
    ) {}

    public function handle(EmailListSyncService $service): void
    {
        $source = EmailListSource::find($this->sourceId);
        if (!$source) return;

        $service->syncSource($source);
    }
}
