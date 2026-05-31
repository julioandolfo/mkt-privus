<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_autopilots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->string('name');
            $table->json('keywords')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('posts_per_week')->default(2);
            $table->foreignId('connection_id')->nullable()->constrained('analytics_connections')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->boolean('require_approval')->default(true);
            $table->boolean('auto_approve')->default(false);
            $table->string('tone', 100)->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('cover_width')->default(1750);
            $table->unsignedSmallInteger('cover_height')->default(650);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'enabled']);
        });

        // Backfill: para cada marca que já tinha config de autopilot no JSON da
        // brand, cria uma linha "Padrão" preservando os valores.
        foreach (DB::table('brands')->get(['id', 'content_engine_config']) as $brand) {
            $cfg = $brand->content_engine_config
                ? json_decode($brand->content_engine_config, true)
                : null;

            if (!is_array($cfg)) {
                continue;
            }

            $hasAutopilotConfig = array_key_exists('blog_autopilot_enabled', $cfg)
                || array_key_exists('blog_posts_per_week', $cfg)
                || array_key_exists('blog_connection_id', $cfg)
                || array_key_exists('blog_category_id', $cfg)
                || array_key_exists('blog_tone', $cfg)
                || array_key_exists('blog_instructions', $cfg);

            if (!$hasAutopilotConfig) {
                continue;
            }

            $connectionId = !empty($cfg['blog_connection_id']) ? (int) $cfg['blog_connection_id'] : null;
            if ($connectionId && !DB::table('analytics_connections')->where('id', $connectionId)->exists()) {
                $connectionId = null;
            }

            $categoryId = !empty($cfg['blog_category_id']) ? (int) $cfg['blog_category_id'] : null;
            if ($categoryId && !DB::table('blog_categories')->where('id', $categoryId)->exists()) {
                $categoryId = null;
            }

            DB::table('blog_autopilots')->insert([
                'brand_id'         => $brand->id,
                'name'             => 'Padrão',
                'keywords'         => null,
                'enabled'          => (bool) ($cfg['blog_autopilot_enabled'] ?? false),
                'posts_per_week'   => (int)  ($cfg['blog_posts_per_week']   ?? 2),
                'connection_id'    => $connectionId,
                'category_id'      => $categoryId,
                'require_approval' => (bool) ($cfg['blog_require_approval'] ?? true),
                'auto_approve'     => (bool) ($cfg['blog_auto_approve']     ?? false),
                'tone'             => $cfg['blog_tone']         ?? null,
                'instructions'     => $cfg['blog_instructions'] ?? null,
                'cover_width'      => (int) ($cfg['blog_cover_width']  ?? 1750),
                'cover_height'     => (int) ($cfg['blog_cover_height'] ?? 650),
                'last_run_at'      => null,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_autopilots');
    }
};
