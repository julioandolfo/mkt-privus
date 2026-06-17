<?php

use App\Actions\BackfillNullBrandId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Restaura a vinculação de marca dos registros legados que ficaram com
 * brand_id = NULL (criados antes do escopo de marca, quando o app não
 * gravava session('current_brand_id')). Sem isto, esses dados sumiriam das
 * telas sob o escopo estrito de marca.
 *
 * Idempotente: só toca em linhas com brand_id NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $results = app(BackfillNullBrandId::class)->handle();

        foreach ($results as $table => $count) {
            Log::info("[migration] backfill_null_brand_ids: {$table} => {$count} linha(s) vinculada(s).");
        }
    }

    public function down(): void
    {
        // Sem reversão: não há como distinguir os NULLs originais com segurança.
    }
};
