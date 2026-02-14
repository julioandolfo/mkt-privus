<?php

use App\Http\Controllers\BrandsController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContentCalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\AutopilotController;
use App\Http\Controllers\ContentRuleController;
use App\Http\Controllers\ContentSuggestionController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\SocialOAuthController;
use App\Http\Controllers\LogsController;
use App\Http\Controllers\EmailProviderController;
use App\Http\Controllers\EmailListController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\EmailCampaignController;
use App\Http\Controllers\EmailEditorController;
use App\Http\Controllers\EmailTrackingController;
use App\Http\Controllers\EmailAnalyticsController;
use App\Http\Controllers\EmailAiSuggestionController;
use App\Http\Controllers\SmsCampaignController;
use App\Http\Controllers\SmsTemplateController;
use App\Http\Controllers\SmsDashboardController;
use App\Http\Controllers\SmsWebhookController;
use App\Http\Controllers\SendPulseWebhookController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\LinkPageController;
use App\Http\Controllers\LinkPagePublicController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Rota de boas-vindas (redireciona para login se nao autenticado)
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return redirect()->route('login');
});

/*
|--------------------------------------------------------------------------
| Callbacks OAuth (fora do auth para evitar problemas de sessão)
|--------------------------------------------------------------------------
*/
Route::get('/analytics/oauth/callback/{platform}', [AnalyticsController::class, 'oauthCallback'])
    ->name('analytics.oauth.callback');

Route::get('/social/oauth/callback/{platform}', [SocialOAuthController::class, 'callback'])
    ->name('social.oauth.callback');

/*
|--------------------------------------------------------------------------
| Email Tracking (rotas públicas, sem auth)
|--------------------------------------------------------------------------
*/
Route::get('/email/t/open/{token}', [EmailTrackingController::class, 'open'])->name('email.track.open');
Route::get('/email/t/click/{token}', [EmailTrackingController::class, 'click'])->name('email.track.click');
Route::get('/email/unsubscribe/{token}', [EmailTrackingController::class, 'unsubscribe'])->name('email.unsubscribe');
// Webhook UNIFICADO SendPulse (usar esta URL no painel do SendPulse)
Route::post('/webhook/sendpulse', [SendPulseWebhookController::class, 'handle'])->name('webhook.sendpulse');

// Webhooks legados (redirecionam para o unificado para compatibilidade)
Route::post('/email/webhook/sendpulse', [SendPulseWebhookController::class, 'handle'])->name('email.webhook.sendpulse');
Route::post('/sms/webhook/sendpulse', [SendPulseWebhookController::class, 'handle'])->name('sms.webhook.sendpulse');

/*
|--------------------------------------------------------------------------
| Rotas autenticadas
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Marcas
    Route::resource('brands', BrandsController::class)->except(['show']);
    Route::post('/brands/{brand}/switch', [BrandsController::class, 'switchBrand'])->name('brands.switch');
    Route::post('/brands/{brand}/assets', [BrandsController::class, 'uploadAsset'])->name('brands.assets.upload');
    Route::delete('/brands/{brand}/assets/{asset}', [BrandsController::class, 'deleteAsset'])->name('brands.assets.delete');
    Route::post('/brands/{brand}/assets/{asset}/primary', [BrandsController::class, 'setPrimaryAsset'])->name('brands.assets.primary');

    // Perfil (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Chat IA
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [ChatController::class, 'index'])->name('index');
        Route::post('/', [ChatController::class, 'store'])->name('store');
        Route::get('/{conversation}', [ChatController::class, 'show'])->name('show');
        Route::put('/{conversation}', [ChatController::class, 'update'])->name('update');
        Route::delete('/{conversation}', [ChatController::class, 'destroy'])->name('destroy');
        Route::post('/{conversation}/message', [ChatController::class, 'sendMessage'])->name('message');
        Route::post('/{conversation}/stream', [ChatController::class, 'streamMessage'])->name('stream');
    });

    // Metricas Customizadas
    Route::prefix('metrics')->name('metrics.')->group(function () {
        Route::get('/', [MetricsController::class, 'index'])->name('index');
        Route::get('/create', [MetricsController::class, 'create'])->name('create');
        Route::post('/', [MetricsController::class, 'store'])->name('store');

        // Categorias (antes das rotas com {metric} para evitar conflito)
        Route::post('/categories', [MetricsController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [MetricsController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [MetricsController::class, 'destroyCategory'])->name('categories.destroy');

        // Metas (rotas sem {metric})
        Route::put('/goals/{goal}', [MetricsController::class, 'updateGoal'])->name('goals.update');
        Route::delete('/goals/{goal}', [MetricsController::class, 'destroyGoal'])->name('goals.destroy');

        // Social Insights
        Route::post('/sync-social', [MetricsController::class, 'syncSocialInsights'])->name('syncSocial');
        Route::post('/from-templates', [MetricsController::class, 'createFromTemplates'])->name('fromTemplates');
        Route::get('/social-insights/{account}', [MetricsController::class, 'socialInsightsData'])->name('socialInsights');

        // Rotas com {metric}
        Route::get('/{metric}', [MetricsController::class, 'show'])->name('show');
        Route::get('/{metric}/edit', [MetricsController::class, 'edit'])->name('edit');
        Route::put('/{metric}', [MetricsController::class, 'update'])->name('update');
        Route::delete('/{metric}', [MetricsController::class, 'destroy'])->name('destroy');
        Route::post('/{metric}/entries', [MetricsController::class, 'addEntry'])->name('entries.store');
        Route::delete('/{metric}/entries/{entry}', [MetricsController::class, 'removeEntry'])->name('entries.destroy');
        Route::get('/{metric}/chart', [MetricsController::class, 'chartData'])->name('chart');
        Route::post('/{metric}/goals', [MetricsController::class, 'storeGoal'])->name('goals.store');
    });

    // Social Media
    Route::prefix('social')->name('social.')->group(function () {
        // Posts
        Route::prefix('posts')->name('posts.')->group(function () {
            Route::get('/', [PostController::class, 'index'])->name('index');
            Route::get('/create', [PostController::class, 'create'])->name('create');
            Route::post('/', [PostController::class, 'store'])->name('store');
            Route::get('/{post}/edit', [PostController::class, 'edit'])->name('edit');
            Route::put('/{post}', [PostController::class, 'update'])->name('update');
            Route::delete('/{post}', [PostController::class, 'destroy'])->name('destroy');
            Route::post('/{post}/duplicate', [PostController::class, 'duplicate'])->name('duplicate');
            Route::post('/{post}/reschedule', [PostController::class, 'reschedule'])->name('reschedule');
        });

        // Geracao de conteudo com IA
        Route::post('/generate', [PostController::class, 'generateContent'])->name('generate');
        Route::post('/generate-complete', [PostController::class, 'generateCompletePost'])->name('generate-complete');

        // Calendario
        Route::get('/calendar', [PostController::class, 'calendar'])->name('calendar.index');
        Route::get('/calendar/data', [PostController::class, 'calendarData'])->name('calendar.data');

        // Calendario de Conteudo (AI)
        Route::prefix('calendar/content')->name('calendar.content.')->group(function () {
            Route::get('/items', [ContentCalendarController::class, 'items'])->name('items');
            Route::post('/generate', [ContentCalendarController::class, 'generate'])->name('generate');
            Route::post('/{item}/generate-post', [ContentCalendarController::class, 'generatePost'])->name('generate-post');
            Route::post('/generate-all-posts', [ContentCalendarController::class, 'generateAllPosts'])->name('generate-all-posts');
            Route::put('/{item}', [ContentCalendarController::class, 'update'])->name('update');
            Route::delete('/{item}', [ContentCalendarController::class, 'destroy'])->name('destroy');
            Route::post('/clear-period', [ContentCalendarController::class, 'clearPeriod'])->name('clear-period');
            Route::post('/approve-batch', [ContentCalendarController::class, 'approveBatch'])->name('approve-batch');
            Route::post('/reject-batch', [ContentCalendarController::class, 'rejectBatch'])->name('reject-batch');
            Route::post('/{item}/approve', [ContentCalendarController::class, 'approveItem'])->name('approve-item');
        });

        // Content Engine
        Route::prefix('content-engine')->name('content-engine.')->group(function () {
            // Dashboard de sugestoes
            Route::get('/', [ContentSuggestionController::class, 'index'])->name('index');

            // Pautas
            Route::get('/rules', [ContentRuleController::class, 'index'])->name('rules');
            Route::post('/rules', [ContentRuleController::class, 'store'])->name('rules.store');
            Route::put('/rules/{rule}', [ContentRuleController::class, 'update'])->name('rules.update');
            Route::delete('/rules/{rule}', [ContentRuleController::class, 'destroy'])->name('rules.destroy');
            Route::post('/rules/{rule}/toggle', [ContentRuleController::class, 'toggle'])->name('rules.toggle');
            Route::post('/rules/{rule}/generate', [ContentRuleController::class, 'generate'])->name('rules.generate');

            // Sugestoes
            Route::post('/suggestions/{suggestion}/approve', [ContentSuggestionController::class, 'approve'])->name('suggestions.approve');
            Route::post('/suggestions/{suggestion}/reject', [ContentSuggestionController::class, 'reject'])->name('suggestions.reject');
            Route::put('/suggestions/{suggestion}', [ContentSuggestionController::class, 'update'])->name('suggestions.update');
            Route::post('/suggestions/bulk-approve', [ContentSuggestionController::class, 'bulkApprove'])->name('suggestions.bulk-approve');
            Route::post('/suggestions/generate-smart', [ContentSuggestionController::class, 'generateSmart'])->name('suggestions.generate-smart');
        });

        // Autopilot
        Route::get('/autopilot', [AutopilotController::class, 'index'])->name('autopilot.index');
        Route::post('/autopilot/{schedule}/retry', [AutopilotController::class, 'retry'])->name('autopilot.retry');

        // Contas sociais
        Route::prefix('accounts')->name('accounts.')->group(function () {
            Route::get('/', [SocialAccountController::class, 'index'])->name('index');
            Route::post('/', [SocialAccountController::class, 'store'])->name('store');
            Route::put('/{account}', [SocialAccountController::class, 'update'])->name('update');
            Route::delete('/{account}', [SocialAccountController::class, 'destroy'])->name('destroy');
            Route::post('/{account}/toggle', [SocialAccountController::class, 'toggle'])->name('toggle');
            Route::post('/{account}/link-brand', [SocialAccountController::class, 'linkBrand'])->name('link-brand');
            Route::post('/{account}/sync', [SocialAccountController::class, 'syncAccount'])->name('sync');
        });

        // OAuth Social (callback está fora do auth - ver acima)
        Route::prefix('oauth')->name('oauth.')->group(function () {
            Route::get('/redirect/{platform}', [SocialOAuthController::class, 'redirect'])->name('redirect');
            Route::get('/discovered', [SocialOAuthController::class, 'discoveredAccounts'])->name('discovered');
            Route::post('/save', [SocialOAuthController::class, 'saveAccounts'])->name('save');
            Route::get('/check-credentials', [SocialOAuthController::class, 'checkCredentials'])->name('check');
        });
    });

    // Configurações do Sistema
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::put('/general', [SettingsController::class, 'updateGeneral'])->name('general');
        Route::put('/ai', [SettingsController::class, 'updateAI'])->name('ai');
        Route::put('/api-keys', [SettingsController::class, 'updateApiKeys'])->name('api-keys');
        Route::put('/social', [SettingsController::class, 'updateSocial'])->name('social');
        Route::put('/oauth', [SettingsController::class, 'updateOAuth'])->name('oauth');
        Route::put('/notifications', [SettingsController::class, 'updateNotifications'])->name('notifications');
        Route::put('/email', [SettingsController::class, 'updateEmail'])->name('email');
        Route::post('/test-email', [SettingsController::class, 'testEmail'])->name('test-email');
        Route::post('/test-ai', [SettingsController::class, 'testAiConnection'])->name('test-ai');
        Route::put('/push', [SettingsController::class, 'updatePush'])->name('push');
        Route::post('/push/generate-vapid', [SettingsController::class, 'generateVapidKeys'])->name('push.generate-vapid');
        Route::post('/push/subscribe', [SettingsController::class, 'subscribePush'])->name('push.subscribe');
        Route::post('/push/unsubscribe', [SettingsController::class, 'unsubscribePush'])->name('push.unsubscribe');
        Route::post('/push/test', [SettingsController::class, 'testPush'])->name('push.test');
        Route::post('/clear-cache', [SettingsController::class, 'clearCache'])->name('clear-cache');

        // Usuários
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
    });

    // Analytics
    Route::prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/', [AnalyticsController::class, 'index'])->name('index');
        Route::get('/website', [AnalyticsController::class, 'website'])->name('website');
        Route::get('/ads', [AnalyticsController::class, 'ads'])->name('ads');
        Route::get('/seo', [AnalyticsController::class, 'seo'])->name('seo');

        // Conexões
        Route::get('/connections', [AnalyticsController::class, 'connections'])->name('connections');
        Route::post('/connections', [AnalyticsController::class, 'storeConnection'])->name('connections.store');
        Route::post('/connections/{connection}/toggle', [AnalyticsController::class, 'toggleConnection'])->name('connections.toggle');
        Route::post('/connections/{connection}/link-brand', [AnalyticsController::class, 'linkBrand'])->name('connections.link-brand');
        Route::delete('/connections/{connection}', [AnalyticsController::class, 'destroyConnection'])->name('connections.destroy');
        Route::post('/connections/{connection}/sync', [AnalyticsController::class, 'syncConnection'])->name('connections.sync');
        Route::post('/connections/test-woocommerce', [AnalyticsController::class, 'testWooCommerce'])->name('connections.test-woocommerce');
        Route::post('/connections/woocommerce-statuses', [AnalyticsController::class, 'fetchWooCommerceStatuses'])->name('connections.woocommerce-statuses');
        Route::put('/connections/{connection}/woocommerce-statuses', [AnalyticsController::class, 'updateWooCommerceStatuses'])->name('connections.update-woocommerce-statuses');
        Route::post('/sync-all', [AnalyticsController::class, 'syncAll'])->name('sync-all');

        // Investimentos Manuais
        Route::post('/manual-entries', [AnalyticsController::class, 'storeManualEntry'])->name('manual-entries.store');
        Route::put('/manual-entries/{entry}', [AnalyticsController::class, 'updateManualEntry'])->name('manual-entries.update');
        Route::delete('/manual-entries/{entry}', [AnalyticsController::class, 'destroyManualEntry'])->name('manual-entries.destroy');

        // OAuth Analytics (callback está fora do auth - ver acima)
        Route::get('/oauth/redirect/{platform}', [AnalyticsController::class, 'oauthRedirect'])->name('oauth.redirect');
        Route::get('/oauth/discovered', [AnalyticsController::class, 'discoveredAccounts'])->name('oauth.discovered');
        Route::post('/oauth/save', [AnalyticsController::class, 'saveOAuthAccounts'])->name('oauth.save');
    });

    // Logs do Sistema
    Route::prefix('logs')->name('logs.')->group(function () {
        Route::get('/', [LogsController::class, 'index'])->name('index');
        Route::get('/laravel', [LogsController::class, 'laravelLog'])->name('laravel');
        Route::post('/laravel/clear', [LogsController::class, 'clearLaravelLog'])->name('laravel.clear');
        Route::get('/laravel/download', [LogsController::class, 'downloadLaravelLog'])->name('laravel.download');
        Route::get('/{log}', [LogsController::class, 'show'])->name('show');
        Route::post('/cleanup', [LogsController::class, 'cleanup'])->name('cleanup');
        Route::post('/clear', [LogsController::class, 'clear'])->name('clear');
    });

    // ===== EMAIL MARKETING =====
    Route::prefix('email')->name('email.')->group(function () {
        // Dashboard / Analytics
        Route::get('/', [EmailAnalyticsController::class, 'dashboard'])->name('dashboard');
        Route::get('/campaign-analytics/{campaign}', [EmailAnalyticsController::class, 'campaignAnalytics'])->name('campaign-analytics');

        // Provedores
        Route::prefix('providers')->name('providers.')->group(function () {
            Route::get('/', [EmailProviderController::class, 'index'])->name('index');
            Route::post('/', [EmailProviderController::class, 'store'])->name('store');
            Route::put('/{provider}', [EmailProviderController::class, 'update'])->name('update');
            Route::delete('/{provider}', [EmailProviderController::class, 'destroy'])->name('destroy');
            Route::post('/{provider}/test', [EmailProviderController::class, 'test'])->name('test');
            Route::post('/{provider}/send-test', [EmailProviderController::class, 'sendTest'])->name('send-test');
        });

        // Listas de Contatos
        Route::prefix('lists')->name('lists.')->group(function () {
            Route::get('/', [EmailListController::class, 'index'])->name('index');
            Route::get('/create', [EmailListController::class, 'create'])->name('create');
            Route::post('/', [EmailListController::class, 'store'])->name('store');
            Route::get('/{list}', [EmailListController::class, 'show'])->name('show');
            Route::put('/{list}', [EmailListController::class, 'update'])->name('update');
            Route::delete('/{list}', [EmailListController::class, 'destroy'])->name('destroy');
            Route::post('/{list}/contacts', [EmailListController::class, 'addContact'])->name('add-contact');
            Route::delete('/{list}/contacts/{contact}', [EmailListController::class, 'removeContact'])->name('remove-contact');
            Route::post('/{list}/import', [EmailListController::class, 'import'])->name('import');
            Route::post('/{list}/sources', [EmailListController::class, 'addSource'])->name('add-source');
            Route::post('/{list}/sources/{source}/sync', [EmailListController::class, 'syncSource'])->name('sync-source');
            Route::delete('/{list}/sources/{source}', [EmailListController::class, 'removeSource'])->name('remove-source');
            // MySQL dinâmico
            Route::post('/mysql/tables', [EmailListController::class, 'mysqlTables'])->name('mysql-tables');
            Route::post('/mysql/columns', [EmailListController::class, 'mysqlColumns'])->name('mysql-columns');
        });

        // Templates
        Route::prefix('templates')->name('templates.')->group(function () {
            Route::get('/', [EmailTemplateController::class, 'index'])->name('index');
            Route::get('/create', [EmailTemplateController::class, 'create'])->name('create');
            Route::post('/', [EmailTemplateController::class, 'store'])->name('store');
            Route::get('/{template}/edit', [EmailTemplateController::class, 'edit'])->name('edit');
            Route::put('/{template}', [EmailTemplateController::class, 'update'])->name('update');
            Route::delete('/{template}', [EmailTemplateController::class, 'destroy'])->name('destroy');
            Route::post('/{template}/duplicate', [EmailTemplateController::class, 'duplicate'])->name('duplicate');
        });

        // Campanhas
        Route::prefix('campaigns')->name('campaigns.')->group(function () {
            Route::get('/', [EmailCampaignController::class, 'index'])->name('index');
            Route::get('/create', [EmailCampaignController::class, 'create'])->name('create');
            Route::post('/', [EmailCampaignController::class, 'store'])->name('store');
            Route::post('/send-test-preview', [EmailCampaignController::class, 'sendTestPreview'])->name('send-test-preview');
            Route::get('/{campaign}', [EmailCampaignController::class, 'show'])->name('show');
            Route::get('/{campaign}/edit', [EmailCampaignController::class, 'edit'])->name('edit');
            Route::put('/{campaign}', [EmailCampaignController::class, 'update'])->name('update');
            Route::delete('/{campaign}', [EmailCampaignController::class, 'destroy'])->name('destroy');
            Route::post('/{campaign}/send', [EmailCampaignController::class, 'send'])->name('send');
            Route::post('/{campaign}/schedule', [EmailCampaignController::class, 'schedule'])->name('schedule');
            Route::post('/{campaign}/pause', [EmailCampaignController::class, 'pause'])->name('pause');
            Route::post('/{campaign}/cancel', [EmailCampaignController::class, 'cancel'])->name('cancel');
            Route::post('/{campaign}/duplicate', [EmailCampaignController::class, 'duplicate'])->name('duplicate');
            Route::post('/{campaign}/send-test', [EmailCampaignController::class, 'sendTest'])->name('send-test');
        });

        // Editor (API JSON)
        Route::prefix('editor')->name('editor.')->group(function () {
            Route::post('/upload-asset', [EmailEditorController::class, 'uploadAsset'])->name('upload-asset');
            Route::get('/assets', [EmailEditorController::class, 'listAssets'])->name('assets');
            Route::delete('/assets/{asset}', [EmailEditorController::class, 'deleteAsset'])->name('delete-asset');
            Route::get('/saved-blocks', [EmailEditorController::class, 'savedBlocks'])->name('saved-blocks');
            Route::post('/saved-blocks', [EmailEditorController::class, 'storeSavedBlock'])->name('store-saved-block');
            Route::put('/saved-blocks/{block}', [EmailEditorController::class, 'updateSavedBlock'])->name('update-saved-block');
            Route::delete('/saved-blocks/{block}', [EmailEditorController::class, 'destroySavedBlock'])->name('delete-saved-block');
            Route::get('/woo-products', [EmailEditorController::class, 'wooProducts'])->name('woo-products');
            Route::post('/generate-ai', [EmailEditorController::class, 'generateWithAI'])->name('generate-ai');
        });

        // Sugestões IA
        Route::prefix('ai-suggestions')->name('ai-suggestions.')->group(function () {
            Route::get('/', [EmailAiSuggestionController::class, 'index'])->name('index');
            Route::post('/{suggestion}/accept', [EmailAiSuggestionController::class, 'accept'])->name('accept');
            Route::post('/{suggestion}/reject', [EmailAiSuggestionController::class, 'reject'])->name('reject');
            Route::post('/{suggestion}/create-campaign', [EmailAiSuggestionController::class, 'createCampaign'])->name('create-campaign');
            Route::post('/generate', [EmailAiSuggestionController::class, 'generate'])->name('generate');
        });
    });

    // ===== SMS MARKETING =====
    Route::prefix('sms')->name('sms.')->group(function () {
        // Dashboard
        Route::get('/', [SmsDashboardController::class, 'index'])->name('dashboard');

        // Templates
        Route::prefix('templates')->name('templates.')->group(function () {
            Route::get('/', [SmsTemplateController::class, 'index'])->name('index');
            Route::get('/create', [SmsTemplateController::class, 'create'])->name('create');
            Route::post('/', [SmsTemplateController::class, 'store'])->name('store');
            Route::put('/{template}', [SmsTemplateController::class, 'update'])->name('update');
            Route::delete('/{template}', [SmsTemplateController::class, 'destroy'])->name('destroy');
        });

        // Campanhas
        Route::prefix('campaigns')->name('campaigns.')->group(function () {
            Route::get('/', [SmsCampaignController::class, 'index'])->name('index');
            Route::get('/create', [SmsCampaignController::class, 'create'])->name('create');
            Route::post('/', [SmsCampaignController::class, 'store'])->name('store');
            Route::get('/{campaign}', [SmsCampaignController::class, 'show'])->name('show');
            Route::delete('/{campaign}', [SmsCampaignController::class, 'destroy'])->name('destroy');
            Route::post('/{campaign}/send', [SmsCampaignController::class, 'send'])->name('send');
            Route::post('/{campaign}/schedule', [SmsCampaignController::class, 'schedule'])->name('schedule');
            Route::post('/{campaign}/pause', [SmsCampaignController::class, 'pause'])->name('pause');
            Route::post('/{campaign}/cancel', [SmsCampaignController::class, 'cancel'])->name('cancel');
            Route::post('/{campaign}/duplicate', [SmsCampaignController::class, 'duplicate'])->name('duplicate');
        });

        // API (JSON)
        Route::post('/calculate-segments', [SmsCampaignController::class, 'calculateSegments'])->name('calculate-segments');
        Route::get('/campaigns/{campaign}/estimate-cost', [SmsCampaignController::class, 'estimateCost'])->name('estimate-cost');
    });

    // ===== BLOG =====
    Route::prefix('blog')->name('blog.')->group(function () {
        // Listagem e criação
        Route::get('/', [BlogController::class, 'index'])->name('index');
        Route::get('/create', [BlogController::class, 'create'])->name('create');
        Route::post('/', [BlogController::class, 'store'])->name('store');

        // Geração com IA (AJAX) — ANTES das rotas com {article}
        Route::post('/generate', [BlogController::class, 'generate'])->name('generate');
        Route::post('/generate-cover', [BlogController::class, 'generateCover'])->name('generate-cover');
        Route::post('/generate-topics', [BlogController::class, 'generateTopics'])->name('generate-topics');
        Route::post('/upload-cover', [BlogController::class, 'uploadCover'])->name('upload-cover');

        // Categorias — ANTES das rotas com {article}
        Route::get('/categories', [BlogController::class, 'categories'])->name('categories');
        Route::post('/categories', [BlogController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [BlogController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}', [BlogController::class, 'destroyCategory'])->name('categories.destroy');
        Route::post('/categories/sync', [BlogController::class, 'syncCategories'])->name('categories.sync');

        // Conexões WordPress — ANTES das rotas com {article}
        Route::post('/connections', [BlogController::class, 'storeConnection'])->name('connections.store');
        Route::post('/connections/test', [BlogController::class, 'testConnection'])->name('connections.test');
        Route::delete('/connections/{connection}', [BlogController::class, 'destroyConnection'])->name('connections.destroy');
        Route::get('/connections/{connection}/categories', [BlogController::class, 'connectionCategories'])->name('connections.categories');

        // Artigos individuais (com {article})
        Route::get('/{article}', [BlogController::class, 'show'])->name('show');
        Route::get('/{article}/edit', [BlogController::class, 'edit'])->name('edit');
        Route::put('/{article}', [BlogController::class, 'update'])->name('update');
        Route::delete('/{article}', [BlogController::class, 'destroy'])->name('destroy');
        Route::post('/{article}/publish', [BlogController::class, 'publish'])->name('publish');
        Route::post('/{article}/approve', [BlogController::class, 'approve'])->name('approve');
        Route::post('/{article}/generate-seo', [BlogController::class, 'generateSeo'])->name('generate-seo');
    });

    // ===== LINKS (BIO LINK PAGES) =====
    Route::prefix('links')->name('links.')->group(function () {
        Route::get('/', [LinkPageController::class, 'index'])->name('index');
        Route::post('/', [LinkPageController::class, 'store'])->name('store');
        Route::get('/{page}/editor', [LinkPageController::class, 'editor'])->name('editor');
        Route::put('/{page}/save', [LinkPageController::class, 'save'])->name('save');
        Route::get('/{page}/analytics', [LinkPageController::class, 'analytics'])->name('analytics');
        Route::post('/{page}/upload-avatar', [LinkPageController::class, 'uploadAvatar'])->name('upload-avatar');
        Route::post('/{page}/duplicate', [LinkPageController::class, 'duplicate'])->name('duplicate');
        Route::delete('/{page}', [LinkPageController::class, 'destroy'])->name('destroy');
    });
});

// Rotas públicas - Link Pages (fora do middleware auth)
Route::get('/l/{slug}', [LinkPagePublicController::class, 'show'])->name('links.public');
Route::post('/l/{slug}/click', [LinkPagePublicController::class, 'click'])->name('links.public.click');

require __DIR__.'/auth.php';
