<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_metric_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_metric_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('value', 15, 2);
            $table->date('date');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['custom_metric_id', 'date']);
            $table->unique(['custom_metric_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_metric_entries');
    }
};
