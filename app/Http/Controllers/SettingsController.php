<?php

namespace App\Http\Controllers;

use App\Enums\AIModel;
use App\Enums\AIProvider;
use App\Models\AiUsageLog;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Services\PushNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Página principal de configurações.
     */
    public function index(Request $request): Response
    {
        $tab = $request->get('tab', 'general');

        // Configurações gerais
        $general = Setting::getGroup('general');
        $generalDefaults = [
            'app_name' => config('app.name', 'MKT Privus'),
            'timezone' => config('app.timezone', 'America/Sao_Paulo'),
            'locale' => config('app.locale', 'pt_BR'),
            'date_format' => 'd/m/Y',
            'time_format' => 'H:i',
            'posts_per_page' => 12,
        ];
        $general = array_merge($generalDefaults, $general);

        // Configurações de IA
        $ai = Setting::getGroup('ai');
        $aiDefaults = [
            'default_chat_model' => 'gpt-4o',
            'default_generation_model' => 'gpt-4o-mini',
            'default_temperature' => 0.7,
            'default_max_tokens' => 4096,
            'content_engine_model' => 'gpt-4o-mini',
            'smart_suggestions_count' => 3,
            'auto_generate_hashtags' => true,
            'inject_brand_context' => true,
        ];
        $ai = array_merge($aiDefaults, $ai);

        // Chaves de API (mascaradas)
        $apiKeys = $this->getApiKeysStatus();

        // Configurações de Social
        $social = Setting::getGroup('social');
        $socialDefaults = [
            'default_platforms' => ['instagram', 'facebook'],
            'autopilot_enabled' => true,
            'autopilot_max_retries' => 3,
            'autopilot_retry_interval' => 15,
            'publish_confirmation' => false,
            'default_post_type' => 'feed',
            'watermark_enabled' => false,
        ];
        $social = array_merge($socialDefaults, $social);

        // Configurações de Notificações
        $notifications = Setting::getGroup('notifications');
        $notificationsDefaults = [
            'notify_publish_success' => true,
            'notify_publish_failure' => true,
            'notify_content_generated' => false,
            'notify_token_expiring' => true,
            'email_notifications' => false,
            'email_digest' => 'none',
            'push_enabled' => false,
        ];
        $notifications = array_merge($notificationsDefaults, $notifications);

        // Configurações de Email/SMTP
        $email = Setting::getGroup('email');
        $emailDefaults = [
            'mailer' => env('MAIL_MAILER', 'smtp'),
            'host' => env('MAIL_HOST', ''),
            'port' => (int) env('MAIL_PORT', 587),
            'encryption' => env('MAIL_SCHEME', 'tls'),
            'username' => env('MAIL_USERNAME', ''),
            'password' => '',
            'from_address' => env('MAIL_FROM_ADDRESS', ''),
            'from_name' => env('MAIL_FROM_NAME', config('app.name', 'MKT Privus')),
        ];
        // Merge com dados do banco (mas nunca expor a senha diretamente)
        $emailDb = $email;
        if (isset($emailDb['password'])) {
            unset($emailDb['password']);
        }
        $email = array_merge($emailDefaults, $emailDb);
        $email['password'] = ''; // Nunca enviar senha ao frontend

        $emailConfigured = !empty(Setting::get('email', 'host'))
            || !empty(env('MAIL_HOST'));
        $emailSource = !empty(Setting::get('email', 'host')) ? 'database' : (!empty(env('MAIL_HOST')) ? 'env' : 'none');
        $emailPasswordSet = !empty(Setting::get('email', 'password')) || !empty(env('MAIL_PASSWORD'));

        // Configurações de Push Notifications
        $push = Setting::getGroup('push');
        $pushDefaults = [
            'vapid_public_key' => '',
            'vapid_private_key' => '',
            'vapid_subject' => env('APP_URL', 'https://mktprivus.com'),
        ];
        $push = array_merge($pushDefaults, $push);
        // Mascarar chave privada
        $pushInfo = [
            'vapid_public_key' => $push['vapid_public_key'] ?? '',
            'vapid_private_key_set' => !empty($push['vapid_private_key']),
            'vapid_subject' => $push['vapid_subject'] ?? '',
            'subscriptions_count' => PushSubscription::count(),
            'user_subscribed' => PushSubscription::where('user_id', $request->user()->id)->exists(),
        ];

        // Modelos disponíveis
        $availableModels = collect(AIModel::cases())->map(fn(AIModel $m) => [
            'value' => $m->value,
            'label' => $m->label(),
            'provider' => $m->provider()->label(),
            'provider_key' => $m->provider()->value,
            'max_tokens' => $m->maxTokens(),
        ])->all();

        // Providers
        $providers = collect(AIProvider::cases())->map(fn(AIProvider $p) => [
            'value' => $p->value,
            'label' => $p->label(),
            'env_key' => $p->envKey(),
        ])->all();

        // Estatísticas de uso de IA
        $aiUsageStats = $this->getAiUsageStats();

        // Credenciais OAuth (unificadas para Social + Analytics)
        $oauthCredentials = [
            'meta_app_id' => Setting::get('oauth', 'meta_app_id') ?: config('social_oauth.meta.app_id', ''),
            'meta_app_secret_set' => !empty(Setting::get('oauth', 'meta_app_secret')) || !empty(config('social_oauth.meta.app_secret')),
            'linkedin_client_id' => Setting::get('oauth', 'linkedin_client_id') ?: config('social_oauth.linkedin.client_id', ''),
            'linkedin_client_secret_set' => !empty(Setting::get('oauth', 'linkedin_client_secret')) || !empty(config('social_oauth.linkedin.client_secret')),
            'google_client_id' => Setting::get('oauth', 'google_client_id') ?: config('social_oauth.google.client_id', ''),
            'google_client_secret_set' => !empty(Setting::get('oauth', 'google_client_secret')) || !empty(config('social_oauth.google.client_secret')),
            'google_ads_developer_token_set' => !empty(Setting::get('oauth', 'google_ads_developer_token')) || !empty(config('services.google_ads.developer_token')),
            'tiktok_client_key' => Setting::get('oauth', 'tiktok_client_key') ?: config('social_oauth.tiktok.client_key', ''),
            'tiktok_client_secret_set' => !empty(Setting::get('oauth', 'tiktok_client_secret')) || !empty(config('social_oauth.tiktok.client_secret')),
            'pinterest_app_id' => Setting::get('oauth', 'pinterest_app_id') ?: config('social_oauth.pinterest.app_id', ''),
            'pinterest_app_secret_set' => !empty(Setting::get('oauth', 'pinterest_app_secret')) || !empty(config('social_oauth.pinterest.app_secret')),
        ];

        return Inertia::render('Settings/Index', [
            'tab' => $tab,
            'general' => $general,
            'ai' => $ai,
            'apiKeys' => $apiKeys,
            'social' => $social,
            'notifications' => $notifications,
            'email' => $email,
            'emailConfigured' => $emailConfigured,
            'emailSource' => $emailSource,
            'emailPasswordSet' => $emailPasswordSet,
            'pushInfo' => $pushInfo,
            'oauthCredentials' => $oauthCredentials,
            'availableModels' => $availableModels,
            'providers' => $providers,
            'aiUsageStats' => $aiUsageStats,
            'timezones' => $this->getTimezones(),
        ]);
    }

    /**
     * Salvar configurações gerais.
     */
    public function updateGeneral(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => 'required|string|max:100',
            'timezone' => 'required|string|max:50',
            'locale' => 'required|string|max:10',
            'date_format' => 'required|string|max:20',
            'time_format' => 'required|string|max:20',
            'posts_per_page' => 'required|integer|min:6|max:48',
        ]);

        Setting::setGroup('general', $validated, [
            'posts_per_page' => 'integer',
        ]);

        return back()->with('success', 'Configurações gerais salvas com sucesso.');
    }

    /**
     * Salvar configurações de IA.
     */
    public function updateAI(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_chat_model' => 'required|string',
            'default_generation_model' => 'required|string',
            'default_temperature' => 'required|numeric|min:0|max:2',
            'default_max_tokens' => 'required|integer|min:256|max:32768',
            'content_engine_model' => 'required|string',
            'smart_suggestions_count' => 'required|integer|min:1|max:10',
            'auto_generate_hashtags' => 'boolean',
            'inject_brand_context' => 'boolean',
        ]);

        Setting::setGroup('ai', $validated, [
            'default_temperature' => 'string',
            'default_max_tokens' => 'integer',
            'smart_suggestions_count' => 'integer',
            'auto_generate_hashtags' => 'boolean',
            'inject_brand_context' => 'boolean',
        ]);

        return back()->with('success', 'Configurações de IA salvas com sucesso.');
    }

    /**
     * Salvar chaves de API.
     */
    public function updateApiKeys(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'openai_api_key' => 'nullable|string|max:200',
            'anthropic_api_key' => 'nullable|string|max:200',
            'gemini_api_key' => 'nullable|string|max:200',
        ]);

        SystemLog::info('settings', 'api_keys.save.start', 'Iniciando salvamento de chaves de API', [
            'keys_received' => array_map(fn($v) => $v !== null && $v !== '' ? 'PREENCHIDO (' . strlen($v) . ' chars)' : 'VAZIO', $validated),
            'raw_request_keys' => array_map(fn($v) => $v !== null && $v !== '' ? 'PREENCHIDO (' . strlen($v) . ' chars)' : ($v === null ? 'NULL' : 'EMPTY_STRING'), $request->only(['openai_api_key', 'anthropic_api_key', 'gemini_api_key'])),
        ]);

        $saved = [];
        $errors = [];

        foreach ($validated as $key => $value) {
            if ($value !== null && $value !== '') {
                try {
                    Setting::set('api_keys', $key, $value, 'encrypted');

                    // Verificar se realmente salvou - ler direto do banco (sem cache)
                    $dbCheck = Setting::where('group', 'api_keys')->where('key', $key)->first();
                    $dbExists = $dbCheck !== null;
                    $dbValueLength = $dbCheck ? strlen($dbCheck->value ?? '') : 0;
                    $dbType = $dbCheck ? $dbCheck->type : 'N/A';

                    // Tentar descriptografar para verificar integridade
                    $decryptOk = false;
                    if ($dbCheck && $dbCheck->value) {
                        try {
                            $decrypted = \Illuminate\Support\Facades\Crypt::decryptString($dbCheck->value);
                            $decryptOk = $decrypted === $value;
                        } catch (\Exception $e) {
                            $decryptOk = false;
                        }
                    }

                    $saved[$key] = true;
                    SystemLog::info('settings', 'api_keys.save.key_ok', "Chave {$key} salva com sucesso", [
                        'key' => $key,
                        'db_exists' => $dbExists,
                        'db_value_length' => $dbValueLength,
                        'db_type' => $dbType,
                        'decrypt_integrity' => $decryptOk,
                        'input_length' => strlen($value),
                    ]);
                } catch (\Exception $e) {
                    $errors[$key] = $e->getMessage();
                    SystemLog::error('settings', 'api_keys.save.key_error', "Erro ao salvar chave {$key}: {$e->getMessage()}", [
                        'key' => $key,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                        'trace' => substr($e->getTraceAsString(), 0, 500),
                    ]);
                }
            } else {
                SystemLog::info('settings', 'api_keys.save.key_skip', "Chave {$key} vazia, pulando", ['key' => $key, 'value_is_null' => $value === null]);
            }
        }

        if (!empty($errors)) {
            return back()->with('error', 'Erro ao salvar chaves: ' . implode(', ', array_keys($errors)));
        }

        if (empty($saved)) {
            return back()->with('error', 'Nenhuma chave foi preenchida para salvar.');
        }

        SystemLog::info('settings', 'api_keys.save.done', 'Chaves de API salvas: ' . implode(', ', array_keys($saved)), [
            'saved_keys' => array_keys($saved),
        ]);

        return back()->with('success', count($saved) . ' chave(s) de API atualizada(s) com sucesso.');
    }

    /**
     * Salvar configurações de Social.
     */
    public function updateSocial(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_platforms' => 'required|array|min:1',
            'default_platforms.*' => 'string',
            'autopilot_enabled' => 'boolean',
            'autopilot_max_retries' => 'required|integer|min:1|max:10',
            'autopilot_retry_interval' => 'required|integer|min:5|max:60',
            'publish_confirmation' => 'boolean',
            'default_post_type' => 'required|string',
            'watermark_enabled' => 'boolean',
        ]);

        Setting::setGroup('social', $validated, [
            'default_platforms' => 'json',
            'autopilot_enabled' => 'boolean',
            'autopilot_max_retries' => 'integer',
            'autopilot_retry_interval' => 'integer',
            'publish_confirmation' => 'boolean',
            'watermark_enabled' => 'boolean',
        ]);

        return back()->with('success', 'Configurações de Social salvas com sucesso.');
    }

    /**
     * Salvar credenciais OAuth das plataformas sociais
     */
    public function updateOAuth(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'meta_app_id' => 'nullable|string|max:255',
            'meta_app_secret' => 'nullable|string|max:255',
            'linkedin_client_id' => 'nullable|string|max:255',
            'linkedin_client_secret' => 'nullable|string|max:255',
            'google_client_id' => 'nullable|string|max:500',
            'google_client_secret' => 'nullable|string|max:255',
            'google_ads_developer_token' => 'nullable|string|max:255',
            'tiktok_client_key' => 'nullable|string|max:255',
            'tiktok_client_secret' => 'nullable|string|max:255',
            'pinterest_app_id' => 'nullable|string|max:255',
            'pinterest_app_secret' => 'nullable|string|max:255',
        ]);

        // Salvar apenas os que foram preenchidos (não sobrescrever com vazio)
        $sensitiveKeys = ['secret', 'token'];
        $types = [];
        foreach ($validated as $key => $value) {
            if ($value !== null && $value !== '') {
                $isSensitive = collect($sensitiveKeys)->contains(fn($s) => str_contains($key, $s));
                $types[$key] = $isSensitive ? 'encrypted' : 'string';
            }
        }

        // Filtrar valores vazios
        $toSave = array_filter($validated, fn($v) => $v !== null && $v !== '');

        if (!empty($toSave)) {
            Setting::setGroup('oauth', $toSave, $types);
        }

        return back()->with('success', 'Credenciais OAuth salvas com sucesso.');
    }

    /**
     * Salvar configurações de Notificações.
     */
    public function updateNotifications(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'notify_publish_success' => 'boolean',
            'notify_publish_failure' => 'boolean',
            'notify_content_generated' => 'boolean',
            'notify_token_expiring' => 'boolean',
            'email_notifications' => 'boolean',
            'email_digest' => 'required|string|in:none,daily,weekly',
            'push_enabled' => 'boolean',
        ]);

        Setting::setGroup('notifications', $validated, [
            'notify_publish_success' => 'boolean',
            'notify_publish_failure' => 'boolean',
            'notify_content_generated' => 'boolean',
            'notify_token_expiring' => 'boolean',
            'email_notifications' => 'boolean',
            'push_enabled' => 'boolean',
        ]);

        return back()->with('success', 'Configurações de notificações salvas com sucesso.');
    }

    /**
     * Salvar configurações de Email/SMTP.
     */
    public function updateEmail(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mailer' => 'required|string|in:smtp,sendmail,ses,postmark,resend,log',
            'host' => 'required_if:mailer,smtp|nullable|string|max:255',
            'port' => 'required_if:mailer,smtp|nullable|integer|min:1|max:65535',
            'encryption' => 'nullable|string|in:tls,ssl,none',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'from_address' => 'required|email|max:255',
            'from_name' => 'required|string|max:100',
        ]);

        // Separar a senha (criptografada)
        $password = $validated['password'] ?? null;
        unset($validated['password']);

        Setting::setGroup('email', $validated, [
            'port' => 'integer',
        ]);

        // Salvar senha separadamente se preenchida
        if ($password !== null && $password !== '') {
            Setting::set('email', 'password', $password, 'encrypted');
        }

        return back()->with('success', 'Configuracoes de email salvas com sucesso.');
    }

    /**
     * Testar envio de email.
     */
    public function testEmail(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            // Aplicar configurações do banco temporariamente
            $this->applySmtpConfig();

            $toEmail = $request->user()->email;

            Mail::raw(
                "Este e um email de teste do MKT Privus.\n\nSe voce recebeu esta mensagem, sua configuracao de email esta funcionando corretamente.\n\nEnviado em: " . now()->format('d/m/Y H:i:s'),
                function ($message) use ($toEmail) {
                    $fromAddress = Setting::get('email', 'from_address') ?: config('mail.from.address');
                    $fromName = Setting::get('email', 'from_name') ?: config('mail.from.name');

                    $message->to($toEmail)
                        ->from($fromAddress, $fromName)
                        ->subject('MKT Privus - Teste de Email');
                }
            );

            return response()->json([
                'success' => true,
                'message' => "Email de teste enviado para {$toEmail} com sucesso.",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Falha no envio: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Salvar configurações de Push Notifications.
     */
    public function updatePush(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'vapid_public_key' => 'nullable|string|max:255',
            'vapid_private_key' => 'nullable|string|max:255',
            'vapid_subject' => 'nullable|string|max:255',
        ]);

        if (!empty($validated['vapid_public_key'])) {
            Setting::set('push', 'vapid_public_key', $validated['vapid_public_key']);
        }
        if (!empty($validated['vapid_private_key'])) {
            Setting::set('push', 'vapid_private_key', $validated['vapid_private_key'], 'encrypted');
        }
        if (!empty($validated['vapid_subject'])) {
            Setting::set('push', 'vapid_subject', $validated['vapid_subject']);
        }

        return back()->with('success', 'Configuracoes de push atualizadas com sucesso.');
    }

    /**
     * Gerar novas chaves VAPID.
     */
    public function generateVapidKeys(): \Illuminate\Http\JsonResponse
    {
        try {
            $keys = PushNotificationService::generateVapidKeys();

            Setting::set('push', 'vapid_public_key', $keys['public']);
            Setting::set('push', 'vapid_private_key', $keys['private'], 'encrypted');

            return response()->json([
                'success' => true,
                'public_key' => $keys['public'],
                'message' => 'Chaves VAPID geradas e salvas com sucesso.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar chaves: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Registrar subscription de push para o usuário atual.
     */
    public function subscribePush(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|url|max:500',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'contentEncoding' => 'nullable|string',
        ]);

        // Evitar duplicatas
        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        PushSubscription::create([
            'user_id' => $request->user()->id,
            'endpoint' => $validated['endpoint'],
            'p256dh_key' => $validated['keys']['p256dh'],
            'auth_token' => $validated['keys']['auth'],
            'content_encoding' => $validated['contentEncoding'] ?? 'aesgcm',
        ]);

        return response()->json(['success' => true, 'message' => 'Push ativado com sucesso.']);
    }

    /**
     * Remover subscription de push do usuário atual.
     */
    public function unsubscribePush(Request $request): \Illuminate\Http\JsonResponse
    {
        PushSubscription::where('user_id', $request->user()->id)->delete();

        return response()->json(['success' => true, 'message' => 'Push desativado com sucesso.']);
    }

    /**
     * Enviar push de teste para o usuário atual.
     */
    public function testPush(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $service = new PushNotificationService();
            $sent = $service->sendToUser(
                $request->user(),
                'MKT Privus - Teste',
                'Esta e uma notificacao push de teste. Tudo funcionando!',
                route('settings.index', ['tab' => 'notifications']),
            );

            if ($sent > 0) {
                return response()->json([
                    'success' => true,
                    'message' => "Push de teste enviado para {$sent} dispositivo(s).",
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Nenhum dispositivo registrado. Ative as notificacoes push primeiro.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Aplica configurações SMTP do banco em runtime.
     */
    private function applySmtpConfig(): void
    {
        $emailSettings = Setting::getGroup('email');

        if (!empty($emailSettings['host'])) {
            Config::set('mail.default', $emailSettings['mailer'] ?? 'smtp');
            Config::set('mail.mailers.smtp.host', $emailSettings['host']);
            Config::set('mail.mailers.smtp.port', $emailSettings['port'] ?? 587);
            Config::set('mail.mailers.smtp.scheme', ($emailSettings['encryption'] ?? 'tls') === 'none' ? null : ($emailSettings['encryption'] ?? 'tls'));
            Config::set('mail.mailers.smtp.username', $emailSettings['username'] ?? null);

            $dbPassword = Setting::get('email', 'password');
            Config::set('mail.mailers.smtp.password', $dbPassword ?: env('MAIL_PASSWORD'));

            Config::set('mail.from.address', $emailSettings['from_address'] ?? env('MAIL_FROM_ADDRESS'));
            Config::set('mail.from.name', $emailSettings['from_name'] ?? env('MAIL_FROM_NAME'));

            // Forçar recriação do mailer
            app()->forgetInstance('mail.manager');
        }
    }

    /**
     * Testar conexão com provedor de IA.
     */
    public function testAiConnection(Request $request): \Illuminate\Http\JsonResponse
    {
        $provider = $request->input('provider');

        try {
            $apiKey = Setting::get('api_keys', "{$provider}_api_key");

            // Fallback para .env
            if (!$apiKey) {
                $envKey = match ($provider) {
                    'openai' => 'OPENAI_API_KEY',
                    'anthropic' => 'ANTHROPIC_API_KEY',
                    'gemini' => 'GEMINI_API_KEY',
                    default => null,
                };
                $apiKey = $envKey ? env($envKey) : null;
            }

            if (!$apiKey) {
                return response()->json(['success' => false, 'message' => 'Chave de API nao configurada.']);
            }

            $testResult = match ($provider) {
                'openai' => $this->testOpenAI($apiKey),
                'anthropic' => $this->testAnthropic($apiKey),
                'gemini' => $this->testGemini($apiKey),
                default => ['success' => false, 'message' => 'Provedor desconhecido.'],
            };

            return response()->json($testResult);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Limpar cache do sistema.
     */
    public function clearCache(): RedirectResponse
    {
        Setting::clearCache();
        Artisan::call('cache:clear');

        return back()->with('success', 'Cache do sistema limpo com sucesso.');
    }

    // ===== HELPERS =====

    private function getApiKeysStatus(): array
    {
        $providers = ['openai', 'anthropic', 'gemini'];
        $result = [];

        foreach ($providers as $provider) {
            // Ler via Setting::get (usa cache)
            $dbKey = Setting::get('api_keys', "{$provider}_api_key");

            // Verificar direto no banco se o cache falhou
            if (!$dbKey) {
                $directCheck = Setting::where('group', 'api_keys')
                    ->where('key', "{$provider}_api_key")
                    ->first();

                if ($directCheck && $directCheck->value) {
                    // Existe no DB mas cache retornou vazio - possivel problema de cache/decrypt
                    try {
                        $dbKey = \Illuminate\Support\Facades\Crypt::decryptString($directCheck->value);
                        // Limpar cache corrompido para forcar re-cache
                        \Illuminate\Support\Facades\Cache::forget("settings.api_keys.{$provider}_api_key");
                        \Illuminate\Support\Facades\Cache::forget("settings.api_keys");

                        SystemLog::warning('settings', 'api_keys.cache_miss', "Chave {$provider} encontrada no DB mas nao no cache. Cache limpo.", [
                            'provider' => $provider,
                            'db_type' => $directCheck->type,
                            'db_value_length' => strlen($directCheck->value),
                            'decrypt_ok' => !empty($dbKey),
                        ]);
                    } catch (\Exception $e) {
                        SystemLog::error('settings', 'api_keys.decrypt_error', "Erro ao descriptografar chave {$provider}: {$e->getMessage()}", [
                            'provider' => $provider,
                            'db_type' => $directCheck->type,
                        ]);
                        $dbKey = null;
                    }
                }
            }

            $envKey = match ($provider) {
                'openai' => env('OPENAI_API_KEY'),
                'anthropic' => env('ANTHROPIC_API_KEY'),
                'gemini' => env('GEMINI_API_KEY'),
                default => null,
            };

            $hasKey = !empty($dbKey) || !empty($envKey);
            $source = !empty($dbKey) ? 'database' : (!empty($envKey) ? 'env' : 'none');

            // Mascarar
            $masked = '';
            $activeKey = $dbKey ?: $envKey;
            if ($activeKey && strlen($activeKey) > 8) {
                $masked = substr($activeKey, 0, 4) . str_repeat('•', min(strlen($activeKey) - 8, 20)) . substr($activeKey, -4);
            } elseif ($activeKey) {
                $masked = '••••••••';
            }

            $result[$provider] = [
                'configured' => $hasKey,
                'source' => $source,
                'masked' => $masked,
            ];
        }

        return $result;
    }

    private function getAiUsageStats(): array
    {
        try {
            $thisMonth = AiUsageLog::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year);

            return [
                'total_requests' => (clone $thisMonth)->count(),
                'total_tokens' => (clone $thisMonth)->sum('input_tokens') + (clone $thisMonth)->sum('output_tokens'),
                'estimated_cost' => round((clone $thisMonth)->sum('estimated_cost'), 4),
                'by_provider' => (clone $thisMonth)->selectRaw('provider, COUNT(*) as count, SUM(input_tokens + output_tokens) as tokens, SUM(estimated_cost) as cost')
                    ->groupBy('provider')
                    ->get()
                    ->keyBy('provider')
                    ->map(fn($row) => [
                        'count' => $row->count,
                        'tokens' => (int) $row->tokens,
                        'cost' => round((float) $row->cost, 4),
                    ])
                    ->toArray(),
                'by_feature' => (clone $thisMonth)->selectRaw('feature, COUNT(*) as count')
                    ->groupBy('feature')
                    ->pluck('count', 'feature')
                    ->toArray(),
            ];
        } catch (\Exception $e) {
            return [
                'total_requests' => 0,
                'total_tokens' => 0,
                'estimated_cost' => 0,
                'by_provider' => [],
                'by_feature' => [],
            ];
        }
    }

    private function testOpenAI(string $apiKey): array
    {
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->timeout(10)->get('https://api.openai.com/v1/models');

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Conexao com OpenAI estabelecida com sucesso.'];
        }

        return ['success' => false, 'message' => 'Falha na conexao: ' . ($response->json('error.message') ?? $response->status())];
    }

    private function testAnthropic(string $apiKey): array
    {
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ])->timeout(10)->post('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 10,
            'messages' => [['role' => 'user', 'content' => 'Hi']],
        ]);

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Conexao com Anthropic estabelecida com sucesso.'];
        }

        $errorMsg = $response->json('error.message') ?? (string) $response->status();
        return ['success' => false, 'message' => 'Falha na conexao: ' . $errorMsg];
    }

    private function testGemini(string $apiKey): array
    {
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");

        if ($response->successful()) {
            return ['success' => true, 'message' => 'Conexao com Google Gemini estabelecida com sucesso.'];
        }

        return ['success' => false, 'message' => 'Falha na conexao: ' . ($response->json('error.message') ?? $response->status())];
    }

    private function getTimezones(): array
    {
        return [
            'America/Sao_Paulo' => 'Brasília (GMT-3)',
            'America/Manaus' => 'Manaus (GMT-4)',
            'America/Belem' => 'Belém (GMT-3)',
            'America/Fortaleza' => 'Fortaleza (GMT-3)',
            'America/Recife' => 'Recife (GMT-3)',
            'America/Cuiaba' => 'Cuiabá (GMT-4)',
            'America/Porto_Velho' => 'Porto Velho (GMT-4)',
            'America/Rio_Branco' => 'Rio Branco (GMT-5)',
            'America/Noronha' => 'Fernando de Noronha (GMT-2)',
            'America/New_York' => 'Nova York (GMT-5)',
            'America/Los_Angeles' => 'Los Angeles (GMT-8)',
            'Europe/London' => 'Londres (GMT+0)',
            'Europe/Lisbon' => 'Lisboa (GMT+0)',
            'UTC' => 'UTC',
        ];
    }
}
