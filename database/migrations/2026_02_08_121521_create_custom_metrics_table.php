<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('unit')->default('number'); // number, currency, percentage, followers
            $table->string('currency_code', 3)->default('BRL');
            $table->string('color', 7)->default('#6366F1');
            $table->string('icon')->default('chart-bar');
            $table->enum('aggregation', ['sum', 'avg', 'last', 'max', 'min'])->default('last');
            $table->decimal('goal_value', 15, 2)->nullable();
            $table->string('goal_period')->nullable(); // monthly, quarterly, yearly
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['brand_id', 'category']);
            $table->index(['brand_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_metrics');
    }
};
