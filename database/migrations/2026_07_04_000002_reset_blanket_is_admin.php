<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correção de dados: a migração 2026_06_11_000005 marcou TODOS os usuários
 * existentes como is_admin=true (era um sistema interno de organização única).
 * No modelo SaaS, is_admin dá acesso ao back-office da plataforma e não deve
 * ser concedido em massa.
 *
 * Esta migração zera is_admin para todos, PRESERVANDO apenas:
 *   - os e-mails listados em SUPER_ADMIN_EMAIL (separados por vírgula); ou
 *   - se nenhum casar (ou a env estiver vazia), o usuário mais antigo — para
 *     nunca deixar a plataforma sem nenhum administrador (evita lockout).
 *
 * Admins adicionais devem ser promovidos explicitamente via
 * `php artisan admin:create` ou pela tela de usuários (admin-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'is_admin')) {
            return;
        }

        $keepEmails = collect(explode(',', (string) env('SUPER_ADMIN_EMAIL', '')))
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter()
            ->values();

        // IDs que devem permanecer admin.
        $keepIds = $keepEmails->isNotEmpty()
            ? DB::table('users')->whereIn(DB::raw('LOWER(email)'), $keepEmails->all())->pluck('id')
            : collect();

        // Fallback anti-lockout: se nenhum e-mail casou, mantém o usuário mais antigo.
        if ($keepIds->isEmpty()) {
            $oldest = DB::table('users')->orderBy('id')->value('id');
            if ($oldest) {
                $keepIds = collect([$oldest]);
            }
        }

        $reset = DB::table('users')
            ->where('is_admin', true)
            ->when($keepIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $keepIds->all()))
            ->update(['is_admin' => false]);

        if (Schema::hasTable('system_logs')) {
            DB::table('system_logs')->insert([
                'channel' => 'security',
                'action' => 'admin.blanket_reset',
                'level' => 'warning',
                'message' => "is_admin removido em massa: {$reset} usuário(s); mantidos: " . $keepIds->implode(','),
                'context' => json_encode(['reset' => $reset, 'kept_ids' => $keepIds->all()]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sem rollback: reconceder is_admin em massa reintroduziria o problema.
    }
};
