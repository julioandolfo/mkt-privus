<?php

namespace App\Console\Commands;

use App\Actions\BackfillNullBrandId;
use Illuminate\Console\Command;

class BackfillNullBrandIdCommand extends Command
{
    protected $signature = 'brand:backfill-null';

    protected $description = 'Atribui marca aos registros legados com brand_id NULL (restaura visibilidade sob o escopo de marca)';

    public function handle(BackfillNullBrandId $action): int
    {
        $this->info('Atribuindo marca aos registros com brand_id NULL...');

        $results = $action->handle();

        if (empty($results)) {
            $this->info('Nada a fazer: nenhum registro com brand_id NULL encontrado.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tabela', 'Linhas atualizadas'],
            collect($results)->map(fn ($count, $table) => [$table, $count])->values()->all()
        );

        $this->info('Backfill concluído.');

        return self::SUCCESS;
    }
}
