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
            $table->string('source')->default('direct')->after('platform')->index();
            $table->string('postiz_integration_id')->nullable()->after('source')->index();
        });

        // Desativa contas de plataformas cuja integração direta foi removida
        // (LinkedIn, TikTok, Pinterest). Usuários precisam reconectar via Postiz.
        $deprecatedPlatforms = ['linkedin', 'tiktok', 'pinterest'];

        $affected = DB::table('social_accounts')
            ->whereIn('platform', $deprecatedPlatforms)
            ->where('source', 'direct')
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        if ($affected > 0) {
            DB::table('social_accounts')
                ->whereIn('platform', $deprecatedPlatforms)
                ->where('source', 'direct')
                ->get()
                ->each(function ($account) {
                    $metadata = json_decode($account->metadata ?? '{}', true) ?: [];
                    $metadata['deprecated_reason'] = 'direct_integration_removed_reconnect_via_postiz';
                    $metadata['deprecated_at'] = now()->toIso8601String();

                    DB::table('social_accounts')
                        ->where('id', $account->id)
                        ->update(['metadata' => json_encode($metadata)]);
                });
        }
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['postiz_integration_id']);
            $table->dropColumn(['source', 'postiz_integration_id']);
        });
    }
};
