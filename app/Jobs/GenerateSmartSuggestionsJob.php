<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\Social\ContentEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job que roda 1x por dia (7h da manha) via Scheduler.
 * Para cada marca ativa, gera sugestoes inteligentes variadas
 * baseadas no contexto e posts anteriores.
 */
class GenerateSmartSuggestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // tries=1: geração é cara e não-idempotente; um retry após timeout do Redis
    // re-geraria (e recobraria) sugestões para marcas já processadas.
    public $tries = 1;
    public $timeout = 1800;

    /**
     * @param int|null $brandId Quando informado, processa só essa marca (ex.:
     *   "executar agora" de UMA marca). Null = todas as marcas (run agendado).
     */
    public function __construct(public ?int $brandId = null)
    {
        // Usa a queue default — a queue dedicada 'content-engine' exigia worker separado
        // que nao estava ativo, e os jobs ficavam presos sem processamento.
    }

    public function handle(ContentEngineService $engine): void
    {
        $brands = Brand::where('is_active', true)
            ->has('users')
            ->when($this->brandId, fn ($q) => $q->whereKey($this->brandId))
            ->get();

        if ($brands->isEmpty()) {
            return;
        }

        Log::info("ContentEngine Smart: Gerando sugestões para {$brands->count()} marcas");

        foreach ($brands as $brand) {
            try {
                $suggestions = $engine->generateSmartSuggestions($brand, 3);

                Log::info("ContentEngine Smart: {$brand->name} — " . count($suggestions) . " sugestões geradas");
            } catch (\Exception $e) {
                Log::error("ContentEngine Smart: Erro para marca #{$brand->id}", [
                    'brand' => $brand->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
