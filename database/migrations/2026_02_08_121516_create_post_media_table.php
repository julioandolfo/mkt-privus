<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('image'); // image, video, document
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->string('alt_text')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_media');
    }
};
