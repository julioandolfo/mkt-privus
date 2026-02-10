<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            // Remover constraint antiga (platform + platform_user_id)
            $table->dropUnique(['platform', 'platform_user_id']);

            // Criar nova constraint incluindo brand_id
            $table->unique(['brand_id', 'platform', 'platform_user_id'], 'social_accounts_brand_platform_uid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique('social_accounts_brand_platform_uid_unique');
            $table->unique(['platform', 'platform_user_id']);
        });
    }
};
