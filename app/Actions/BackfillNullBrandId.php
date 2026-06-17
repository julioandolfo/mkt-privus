<?php

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Atribui uma marca aos registros legados que ficaram com brand_id = NULL.
 *
 * Contexto: antes da introdução do escopo de marca, o app nunca gravava
 * session('current_brand_id'), então os controllers criavam dados com
 * brand_id = NULL e não filtravam por marca (comportamento single-tenant).
 * Com o escopo estrito ativo, esses registros sumiriam das telas — este
 * backfill restaura a vinculação correta.
 *
 * Estratégia (sempre apenas em linhas com brand_id NULL — idempotente):
 *  - tabelas com user_id: usa users.current_brand_id do dono;
 *  - email_contacts: deriva da marca de uma lista à qual pertence (pivot);
 *  - social_insights: deriva da conta social vinculada;
 *  - blog_categories / blog_autopilots: se existir exatamente UMA marca no
 *    sistema, usa-a (caso single-tenant); senão, ignora e registra.
 */
class BackfillNullBrandId
{
    /** Tabelas que possuem user_id e brand_id nulável */
    private const USER_OWNED_TABLES = [
        'email_campaigns',
        'email_lists',
        'email_templates',
        'email_providers',
        'email_saved_blocks',
        'email_assets',
        'email_ai_suggestions',
        'sms_campaigns',
        'sms_templates',
        'blog_articles',
        'blog_calendar_items',
        'campaigns_generated',
        'link_pages',
    ];

    /** @return array<string,int> tabela => linhas atualizadas */
    public function handle(): array
    {
        $results = [];

        foreach (self::USER_OWNED_TABLES as $table) {
            $results[$table] = $this->backfillFromOwner($table);
        }

        $results['email_contacts'] = $this->backfillEmailContacts();
        $results['social_insights'] = $this->backfillFromForeign(
            'social_insights',
            'social_account_id',
            'social_accounts'
        );

        $results['blog_categories'] = $this->backfillSingleBrand('blog_categories');
        $results['blog_autopilots'] = $this->backfillSingleBrand('blog_autopilots');

        return array_filter($results, fn ($count) => $count > 0);
    }

    /**
     * brand_id = marca ativa (users.current_brand_id) do dono da linha.
     */
    private function backfillFromOwner(string $table): int
    {
        if (!$this->ready($table) || !Schema::hasColumn($table, 'user_id')) {
            return 0;
        }

        $sub = "(SELECT current_brand_id FROM users WHERE users.id = {$table}.user_id)";

        return $this->safeUpdate(
            $table,
            "UPDATE {$table} SET brand_id = {$sub}
             WHERE brand_id IS NULL
               AND user_id IS NOT NULL
               AND {$sub} IS NOT NULL"
        );
    }

    /**
     * brand_id = marca de uma lista à qual o contato pertence (pivot).
     */
    private function backfillEmailContacts(): int
    {
        if (!$this->ready('email_contacts') || !Schema::hasTable('email_list_contact')) {
            return 0;
        }

        $derived = "(SELECT el.brand_id FROM email_list_contact elc
                       JOIN email_lists el ON el.id = elc.email_list_id
                      WHERE elc.email_contact_id = email_contacts.id
                        AND el.brand_id IS NOT NULL
                      LIMIT 1)";

        // unique(brand_id, email) pode conflitar; falha não deve abortar o backfill
        return $this->safeUpdate(
            'email_contacts',
            "UPDATE email_contacts SET brand_id = {$derived}
             WHERE brand_id IS NULL AND {$derived} IS NOT NULL"
        );
    }

    /**
     * brand_id = brand_id da tabela estrangeira (ex.: social_accounts).
     */
    private function backfillFromForeign(string $table, string $fk, string $foreign): int
    {
        if (!$this->ready($table) || !Schema::hasColumn($table, $fk) || !Schema::hasTable($foreign)) {
            return 0;
        }

        $sub = "(SELECT brand_id FROM {$foreign} WHERE {$foreign}.id = {$table}.{$fk})";

        return $this->safeUpdate(
            $table,
            "UPDATE {$table} SET brand_id = {$sub}
             WHERE brand_id IS NULL
               AND {$fk} IS NOT NULL
               AND {$sub} IS NOT NULL"
        );
    }

    /**
     * Caso single-tenant: havendo exatamente uma marca, usa-a.
     */
    private function backfillSingleBrand(string $table): int
    {
        if (!$this->ready($table)) {
            return 0;
        }

        $brands = DB::table('brands')->whereNull('deleted_at')->pluck('id');

        if ($brands->count() !== 1) {
            return 0;
        }

        return DB::table($table)->whereNull('brand_id')->update(['brand_id' => $brands->first()]);
    }

    private function ready(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'brand_id');
    }

    private function safeUpdate(string $table, string $sql): int
    {
        try {
            return DB::affectingStatement($sql);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                "[BackfillNullBrandId] Falha ao atualizar {$table}: {$e->getMessage()}"
            );

            return 0;
        }
    }
}
