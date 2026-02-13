<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Templates de SMS (texto simples com merge tags)
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('body'); // Texto com merge tags: {{first_name}}, {{phone}}, {{sms_optout}}
            $table->string('category', 50)->default('marketing'); // marketing, transactional, welcome, reminder
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['brand_id', 'is_active']);
        });

        // Campanhas de SMS
        Schema::create('sms_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_provider_id')->constrained('email_providers')->cascadeOnDelete(); // Provider SMS
            $table->foreignId('sms_template_id')->nullable()->constrained('sms_templates')->nullOnDelete();
            $table->string('name');
            $table->text('body'); // Texto final da mensagem
            $table->string('sender_name', 11); // Alfanumérico, max 11 chars
            $table->string('status', 20)->default('draft'); // draft, scheduled, sending, sent, paused, cancelled
            $table->string('type', 20)->default('regular');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('total_sent')->default(0);
            $table->unsignedInteger('total_delivered')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->unsignedInteger('total_clicked')->default(0);
            $table->decimal('estimated_cost', 10, 4)->nullable();
            $table->string('estimated_currency', 10)->nullable();
            $table->string('sendpulse_campaign_id')->nullable(); // ID da campanha no SendPulse
            $table->json('settings')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status']);
            $table->index('scheduled_at');
        });

        // Pivot: campanhas SMS <-> listas de contatos
        Schema::create('sms_campaign_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_list_id')->constrained('email_lists')->cascadeOnDelete();
            $table->string('type', 10)->default('include'); // include, exclude
            $table->timestamps();

            $table->unique(['sms_campaign_id', 'email_list_id', 'type']);
        });

        // Eventos de entrega SMS
        Schema::create('sms_campaign_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_contact_id')->nullable()->constrained('email_contacts')->nullOnDelete();
            $table->string('phone', 30)->nullable();
            $table->string('event_type', 30); // sent, delivered, failed, clicked, optout
            $table->json('metadata')->nullable(); // status_code, error, url clicked, etc.
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['sms_campaign_id', 'event_type']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_campaign_events');
        Schema::dropIfExists('sms_campaign_lists');
        Schema::dropIfExists('sms_campaigns');
        Schema::dropIfExists('sms_templates');
    }
};
