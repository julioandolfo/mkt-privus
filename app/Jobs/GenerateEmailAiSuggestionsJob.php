<?php

namespace App\Jobs;

use App\Services\Email\EmailAiSuggestionService;
use App\Models\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateEmailAiSuggestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600; // 10 minutos (múltiplas marcas)

    public function handle(EmailAiSuggestionService $service): void
    {
        $total = $service->generateForAllBrands();

        SystemLog::info('email', 'ai_suggestions.daily', "Job diário: {$total} sugestões geradas para todas as marcas", [
            'total_suggestions' => $total,
        ]);
    }
}
