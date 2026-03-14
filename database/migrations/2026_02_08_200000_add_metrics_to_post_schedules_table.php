<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('likes')->default(0)->after('platform_post_url');
            $table->unsignedBigInteger('comments')->default(0)->after('likes');
            $table->unsignedBigInteger('shares')->default(0)->after('comments');
            $table->unsignedBigInteger('saves')->default(0)->after('shares');
            $table->unsignedBigInteger('reach')->default(0)->after('saves');
            $table->unsignedBigInteger('impressions')->default(0)->after('reach');
            $table->unsignedBigInteger('video_views')->default(0)->after('impressions');
            $table->decimal('engagement_rate', 8, 2)->default(0)->after('video_views');
            $table->timestamp('metrics_synced_at')->nullable()->after('engagement_rate');
        });
    }

    public function down(): void
    {
        Schema::table('post_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'likes', 'comments', 'shares', 'saves',
                'reach', 'impressions', 'video_views',
                'engagement_rate', 'metrics_synced_at',
            ]);
        });
    }
};
