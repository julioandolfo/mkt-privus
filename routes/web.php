<?php

use App\Http\Controllers\BrandsController;
use App\Http\Controllers\ChatController;
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
        });

        // Geracao de conteudo com IA
        Route::post('/generate', [PostController::class, 'generateContent'])->name('generate');

        // Calendario
        Route::get('/calendar', [PostController::class, 'calendar'])->name('calendar.index');
        Route::get('/calendar/data', [PostController::class, 'calendarData'])->name('calendar.data');

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
        Route::delete('/connections/{connection}', [AnalyticsController::class, 'destroyConnection'])->name('connections.destroy');
        Route::post('/connections/{connection}/sync', [AnalyticsController::class, 'syncConnection'])->name('connections.sync');
        Route::post('/sync-all', [AnalyticsController::class, 'syncAll'])->name('sync-all');

        // OAuth Analytics (callback está fora do auth - ver acima)
        Route::get('/oauth/redirect/{platform}', [AnalyticsController::class, 'oauthRedirect'])->name('oauth.redirect');
        Route::post('/oauth/save', [AnalyticsController::class, 'saveOAuthAccounts'])->name('oauth.save');
    });

    // Logs do Sistema
    Route::prefix('logs')->name('logs.')->group(function () {
        Route::get('/', [LogsController::class, 'index'])->name('index');
        Route::get('/{log}', [LogsController::class, 'show'])->name('show');
        Route::post('/cleanup', [LogsController::class, 'cleanup'])->name('cleanup');
        Route::post('/clear', [LogsController::class, 'clear'])->name('clear');
    });

    // ===== MODULOS FUTUROS =====
    // Route::prefix('blog')->name('blog.')->group(function () { ... });
    // Route::prefix('links')->name('links.')->group(function () { ... });
});

require __DIR__.'/auth.php';
