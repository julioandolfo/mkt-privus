<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('social_accounts', 'source')) {
                $table->string('source')->default('direct')->after('platform')->index();
            }
            if (!Schema::hasColumn('social_accounts', 'postiz_integration_id')) {
                $table->string('postiz_integration_id')->nullable()->after('source')->index();
            }
        });

        // Desativa contas de plataformas cuja integração direta foi removida
        // (LinkedIn, TikTok, Pinterest). Usuários precisam reconectar via Postiz.
        $deprecatedPlatforms = ['linkedin', 'tiktok', 'pinterest'];

        $rows = DB::table('social_accounts')
            ->whereIn('platform', $deprecatedPlatforms)
            ->where('source', 'direct')
            ->where('is_active', true)
            ->get();

        foreach ($rows as $account) {
            $metadata = json_decode($account->metadata ?? '{}', true) ?: [];
            if (($metadata['deprecated_reason'] ?? null) !== 'direct_integration_removed_reconnect_via_postiz') {
                $metadata['deprecated_reason'] = 'direct_integration_removed_reconnect_via_postiz';
                $metadata['deprecated_at'] = now()->toIso8601String();
            }

            DB::table('social_accounts')
                ->where('id', $account->id)
                ->update([
                    'is_active' => false,
                    'metadata' => json_encode($metadata),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('social_accounts', 'source')) {
                $table->dropIndex(['source']);
                $table->dropColumn('source');
            }
            if (Schema::hasColumn('social_accounts', 'postiz_integration_id')) {
                $table->dropIndex(['postiz_integration_id']);
                $table->dropColumn('postiz_integration_id');
            }
        });
    }
};
