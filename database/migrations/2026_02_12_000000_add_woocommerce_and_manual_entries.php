<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Colunas WooCommerce + investimentos manuais no daily summary
        Schema::table('analytics_daily_summaries', function (Blueprint $table) {
            // E-commerce (WooCommerce)
            $table->integer('wc_orders')->default(0)->after('social_posts');
            $table->decimal('wc_revenue', 12, 2)->default(0)->after('wc_orders');
            $table->decimal('wc_avg_order_value', 10, 2)->default(0)->after('wc_revenue');
            $table->integer('wc_items_sold')->default(0)->after('wc_avg_order_value');
            $table->decimal('wc_refunds', 10, 2)->default(0)->after('wc_items_sold');
            $table->decimal('wc_shipping', 10, 2)->default(0)->after('wc_refunds');
            $table->decimal('wc_tax', 10, 2)->default(0)->after('wc_shipping');
            $table->integer('wc_new_customers')->default(0)->after('wc_tax');
            $table->integer('wc_coupons_used')->default(0)->after('wc_new_customers');

            // Investimentos manuais e ROAS real
            $table->decimal('manual_ad_spend', 12, 2)->default(0)->after('wc_coupons_used');
            $table->decimal('total_spend', 12, 2)->default(0)->after('manual_ad_spend');
            $table->decimal('real_roas', 8, 2)->default(0)->after('total_spend');
        });

        // Tabela de investimentos manuais
        Schema::create('manual_ad_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 50); // google_ads, meta_ads, tiktok_ads, linkedin_ads, pinterest_ads, other
            $table->string('platform_label')->nullable(); // nome customizado quando platform=other
            $table->date('date_start');
            $table->date('date_end');
            $table->decimal('amount', 12, 2);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'date_start', 'date_end']);
        });
    }

    public function down(): void
    {
        Schema::table('analytics_daily_summaries', function (Blueprint $table) {
            $table->dropColumn([
                'wc_orders', 'wc_revenue', 'wc_avg_order_value', 'wc_items_sold',
                'wc_refunds', 'wc_shipping', 'wc_tax', 'wc_new_customers', 'wc_coupons_used',
                'manual_ad_spend', 'total_spend', 'real_roas',
            ]);
        });

        Schema::dropIfExists('manual_ad_entries');
    }
};
