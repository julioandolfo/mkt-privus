<?php

namespace App\Jobs;

use App\Models\ContentRule;
use App\Services\Social\ContentEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que roda a cada hora via Scheduler.
 * Busca pautas configuradas que estao prontas para geracao
 * e chama o ContentEngineService para gerar conteudo.
 */
class GenerateScheduledContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        // Usa a queue default — a queue dedicada 'content-engine' exigia worker separado
        // que nao estava ativo, e os jobs ficavam presos sem processamento.
    }

    public function handle(ContentEngineService $engine): void
    {
        $rules = ContentRule::dueForGeneration()
            ->with('brand')
            ->limit(20)
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        Log::info("ContentEngine: {$rules->count()} pautas prontas para geração");

        foreach ($rules as $rule) {
            try {
                $suggestion = $engine->generateFromRule($rule);

                if ($suggestion) {
                    $rule->markAsGenerated();
                    Log::info("ContentEngine: Pauta #{$rule->id} '{$rule->name}' gerada com sucesso");
                } else {
                    Log::warning("ContentEngine: Pauta #{$rule->id} não gerou sugestão");
                }
            } catch (\Exception $e) {
                Log::error("ContentEngine: Erro na pauta #{$rule->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
