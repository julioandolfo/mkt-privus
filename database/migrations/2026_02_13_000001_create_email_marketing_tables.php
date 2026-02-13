<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Provedores de email (SMTP / SendPulse) por marca
        Schema::create('email_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // smtp, sendpulse
            $table->string('name');
            $table->json('config'); // credenciais dependendo do tipo
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('sends_today')->default(0);
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'is_active']);
        });

        // 2. Listas de contatos
        Schema::create('email_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 20)->default('static'); // static, dynamic
            $table->unsignedInteger('contacts_count')->default(0);
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['brand_id', 'is_active']);
        });

        // 3. Contatos de email (globais)
        Schema::create('email_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('company')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 20)->default('active'); // active, unsubscribed, bounced, complained
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('source', 30)->default('manual'); // manual, import, woocommerce, mysql, sheets, api
            $table->string('source_id')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'email']);
            $table->index('email');
            $table->index(['brand_id', 'status']);
        });

        // 4. Pivot lista-contato
        Schema::create('email_list_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_contact_id')->constrained()->cascadeOnDelete();
            $table->timestamp('added_at')->useCurrent();

            $table->unique(['email_list_id', 'email_contact_id']);
        });

        // 5. Fontes externas de contatos
        Schema::create('email_list_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_list_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // csv, woocommerce, mysql, google_sheets
            $table->json('config'); // configuracao especifica da fonte
            $table->string('sync_frequency', 20)->default('manual'); // manual, daily, weekly, monthly
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_status', 20)->default('pending'); // pending, syncing, success, error
            $table->text('sync_error')->nullable();
            $table->unsignedInteger('records_synced')->default(0);
            $table->timestamps();
        });

        // 6. Templates de email reutilizaveis
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('subject')->nullable();
            $table->longText('html_content')->nullable();
            $table->longText('mjml_content')->nullable();
            $table->json('json_content')->nullable(); // GrapesJS design JSON
            $table->string('thumbnail_path')->nullable();
            $table->string('category', 30)->default('marketing'); // marketing, transactional, newsletter, promotional, welcome
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['brand_id', 'category']);
        });

        // 7. Blocos salvos reutilizaveis (headers, footers, sections)
        Schema::create('email_saved_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 30); // header, footer, section, product, custom
            $table->longText('mjml_content')->nullable();
            $table->longText('html_content')->nullable();
            $table->json('json_content')->nullable(); // GrapesJS component JSON
            $table->string('thumbnail_path')->nullable();
            $table->boolean('is_global')->default(false);
            $table->timestamps();

            $table->index(['brand_id', 'category']);
        });

        // 8. Assets de email (imagens)
        Schema::create('email_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 50)->nullable();
            $table->unsignedInteger('file_size')->default(0);
            $table->json('dimensions')->nullable(); // {width, height}
            $table->string('alt_text')->nullable();
            $table->timestamps();

            $table->index('brand_id');
        });

        // 9. Campanhas de email
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('email_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('subject');
            $table->string('preview_text')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to')->nullable();
            $table->longText('html_content')->nullable();
            $table->longText('mjml_content')->nullable();
            $table->json('json_content')->nullable(); // GrapesJS design JSON
            $table->string('status', 20)->default('draft'); // draft, scheduled, sending, sent, paused, cancelled, failed
            $table->string('type', 20)->default('regular'); // regular, ab_test
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('tags')->nullable();
            // Metricas cached
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('total_sent')->default(0);
            $table->unsignedInteger('total_delivered')->default(0);
            $table->unsignedInteger('total_bounced')->default(0);
            $table->unsignedInteger('total_opened')->default(0);
            $table->unsignedInteger('total_clicked')->default(0);
            $table->unsignedInteger('total_unsubscribed')->default(0);
            $table->unsignedInteger('total_complained')->default(0);
            $table->unsignedInteger('unique_opens')->default(0);
            $table->unsignedInteger('unique_clicks')->default(0);
            // Config
            $table->json('settings')->nullable(); // track_opens, track_clicks, send_speed, ab_test_config
            $table->timestamps();

            $table->index(['brand_id', 'status']);
            $table->index('scheduled_at');
        });

        // 10. Pivot campanha-listas
        Schema::create('email_campaign_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_list_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->default('include'); // include, exclude

            $table->unique(['email_campaign_id', 'email_list_id']);
        });

        // 11. Eventos de tracking (alta escala)
        Schema::create('email_campaign_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_contact_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 20); // queued, sent, delivered, bounced, opened, clicked, unsubscribed, complained, failed
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['email_campaign_id', 'event_type']);
            $table->index(['email_contact_id', 'event_type']);
            $table->index('occurred_at');
        });

        // 12. Sugestoes de email marketing geradas pela IA
        Schema::create('email_ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('suggested_subject')->nullable();
            $table->text('suggested_preview')->nullable();
            $table->string('target_audience')->nullable();
            $table->string('content_type', 30)->default('newsletter'); // newsletter, promotional, educational, seasonal, engagement
            $table->json('reference_data')->nullable(); // dados que a IA usou (posts recentes, links, etc)
            $table->string('status', 20)->default('pending'); // pending, accepted, rejected, used
            $table->date('suggested_send_date')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_ai_suggestions');
        Schema::dropIfExists('email_campaign_events');
        Schema::dropIfExists('email_campaign_lists');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('email_assets');
        Schema::dropIfExists('email_saved_blocks');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_list_sources');
        Schema::dropIfExists('email_list_contact');
        Schema::dropIfExists('email_contacts');
        Schema::dropIfExists('email_lists');
        Schema::dropIfExists('email_providers');
    }
};
