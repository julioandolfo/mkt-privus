<?php

namespace App\Http\Controllers;

use App\Jobs\SyncSingleSocialAccountJob;
use App\Models\OAuthDiscoveredAccount;
use App\Models\SocialAccount;
use App\Models\SystemLog;
use App\Services\Social\SocialOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SocialOAuthController extends Controller
{
    public function __construct(
        private SocialOAuthService $oauthService
    ) {}

    /**
     * Redireciona o usuário para a página de autorização da plataforma.
     * Suporta modo popup (retorna JSON com URL) e redirect padrão.
     */
    public function redirect(Request $request, string $platform): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $validPlatforms = ['facebook', 'instagram', 'linkedin', 'youtube', 'tiktok', 'pinterest'];
        $isPopup = $request->boolean('popup', false);

        SystemLog::info('oauth', 'oauth.redirect.start', "Iniciando redirect OAuth para {$platform}", [
            'platform' => $platform,
            'is_popup' => $isPopup,
            'user_agent' => $request->userAgent(),
        ]);

        if (!in_array($platform, $validPlatforms)) {
            SystemLog::warning('oauth', 'oauth.redirect.invalid_platform', "Plataforma invalida: {$platform}");
            if ($isPopup) {
                return response()->json(['error' => 'Plataforma não suportada.'], 400);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Plataforma não suportada.');
        }

        try {
            $brand = $request->user()->getActiveBrand();

            if (!$brand) {
                $errorMsg = 'Nenhuma marca ativa. Crie ou selecione uma marca antes de conectar contas.';
                SystemLog::warning('oauth', 'oauth.redirect.no_brand', $errorMsg);
                if ($isPopup) {
                    return response()->json(['error' => $errorMsg], 400);
                }
                return redirect()->route('brands.index')->with('error', $errorMsg);
            }

            // Para Meta: Instagram e Facebook usam a mesma autenticação
            $oauthPlatform = in_array($platform, ['facebook', 'instagram']) ? 'facebook' : $platform;

            // Verificar se as credenciais OAuth estão configuradas para a plataforma
            $this->validateOAuthCredentials($platform, $oauthPlatform, $isPopup);

            // Gerar state para segurança CSRF
            $state = Str::random(40);

            // Salvar no Cache (mais confiavel que sessao em Docker)
            Cache::put('social_oauth_' . $state, [
                'platform' => $platform,
                'brand_id' => $brand->id,
                'user_id' => auth()->id(),
                'popup' => $isPopup,
            ], now()->addMinutes(15));

            // Tambem na sessao como fallback
            session([
                'oauth_state' => $state,
                'oauth_platform' => $platform,
                'oauth_brand_id' => $brand->id,
                'oauth_popup' => $isPopup,
            ]);

            $redirectUri = route('social.oauth.callback', $oauthPlatform);

            SystemLog::debug('oauth', 'oauth.redirect.config', "Configuracao OAuth", [
                'oauth_platform' => $oauthPlatform,
                'redirect_uri' => $redirectUri,
                'brand_id' => $brand->id,
                'state_prefix' => substr($state, 0, 8) . '...',
            ]);

            $authUrl = $this->oauthService->getAuthorizationUrl($platform, $redirectUri, $state);

            SystemLog::info('oauth', 'oauth.redirect.success', "URL de autorizacao gerada com sucesso para {$platform}", [
                'auth_url_domain' => parse_url($authUrl, PHP_URL_HOST),
            ]);

            if ($isPopup) {
                return response()->json(['url' => $authUrl]);
            }

            return redirect()->away($authUrl);
        } catch (\Throwable $e) {
            try {
                SystemLog::error('oauth', 'oauth.redirect.error', "Erro ao gerar URL OAuth: {$e->getMessage()}", [
                    'platform' => $platform,
                    'exception' => get_class($e),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                    'trace' => substr($e->getTraceAsString(), 0, 1000),
                ]);
            } catch (\Throwable $logErr) {
                error_log("OAuth error (log also failed): {$e->getMessage()}");
            }

            $errorMsg = 'Erro ao iniciar autenticação: ' . $e->getMessage();
            if ($isPopup) {
                return response()->json(['error' => $errorMsg], 500);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', $errorMsg);
        }
    }

    /**
     * Callback de retorno após autorização na plataforma.
     * Troca o code por token e busca as contas disponíveis.
     * Salva contas no BANCO DE DADOS (nao mais sessao) para funcionar entre popup e janela principal.
     */
    public function callback(Request $request, string $platform): RedirectResponse|\Illuminate\Contracts\View\View
    {
        $receivedState = $request->get('state');

        // Tentar recuperar dados do Cache (prioridade) ou Sessao (fallback)
        $oauthData = null;
        if ($receivedState) {
            $oauthData = Cache::get('social_oauth_' . $receivedState);
            if ($oauthData) {
                SystemLog::debug('oauth', 'oauth.callback.cache_hit', "Dados OAuth recuperados do Cache");
            }
        }

        // Fallback: sessao
        if (!$oauthData) {
            $storedState = session('oauth_state');
            if ($storedState && $storedState === $receivedState) {
                $oauthData = [
                    'platform' => session('oauth_platform', $platform),
                    'brand_id' => session('oauth_brand_id'),
                    'user_id' => auth()->id(),
                    'popup' => session('oauth_popup', false),
                ];
                SystemLog::debug('oauth', 'oauth.callback.session_fallback', "Dados OAuth recuperados da sessao");
            }
        }

        // Fallback final: usuario autenticado sem state
        if (!$oauthData && auth()->check()) {
            $oauthData = [
                'platform' => session('oauth_platform', $platform),
                'brand_id' => session('oauth_brand_id'),
                'user_id' => auth()->id(),
                'popup' => session('oauth_popup', false),
            ];
            SystemLog::warning('oauth', 'oauth.callback.no_state', "State nao validado - usando dados do usuario logado");
        }

        $isPopup = $oauthData['popup'] ?? false;

        SystemLog::info('oauth', 'oauth.callback.start', "Callback OAuth recebido para {$platform}", [
            'platform' => $platform,
            'is_popup' => $isPopup,
            'has_code' => $request->has('code'),
            'has_error' => $request->has('error'),
            'has_oauth_data' => !empty($oauthData),
            'session_id' => session()->getId(),
        ]);

        if (!$oauthData) {
            SystemLog::error('oauth', 'oauth.callback.invalid_state', 'State CSRF invalido e nenhum fallback disponivel', [
                'received_state_exists' => !empty($receivedState),
            ]);
            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Sessão inválida. Tente novamente.',
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'discoveryToken' => null,
                ]);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Sessão inválida. Tente novamente.');
        }

        // Verificar erro
        if ($request->has('error')) {
            $errorDesc = $request->get('error_description', $request->get('error'));
            SystemLog::warning('oauth', 'oauth.callback.denied', "Autorizacao negada pelo usuario: {$errorDesc}", [
                'error_code' => $request->get('error'),
                'error_reason' => $request->get('error_reason'),
            ]);
            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Autorização negada: ' . $errorDesc,
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'discoveryToken' => null,
                ]);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Autorização negada: ' . $errorDesc);
        }

        $code = $request->get('code');
        if (!$code) {
            SystemLog::error('oauth', 'oauth.callback.no_code', 'Codigo de autorizacao nao recebido');
            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Código de autorização não recebido.',
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'discoveryToken' => null,
                ]);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Código de autorização não recebido.');
        }

        $originalPlatform = $oauthData['platform'] ?? $platform;
        $brandId = $oauthData['brand_id'] ?? null;
        $userId = $oauthData['user_id'] ?? auth()->id();

        // Limpar dados temporários da sessao
        session()->forget(['oauth_state', 'oauth_popup']);

        $redirectUri = route('social.oauth.callback', $platform);

        SystemLog::info('oauth', 'oauth.callback.exchanging', "Trocando code por token para {$originalPlatform}", [
            'brand_id' => $brandId,
            'user_id' => $userId,
        ]);

        try {
            // Trocar code por token
            $tokenData = $this->oauthService->exchangeCode($originalPlatform, $code, $redirectUri);

            SystemLog::info('oauth', 'oauth.callback.token_received', "Token recebido com sucesso", [
                'has_access_token' => !empty($tokenData['access_token']),
                'has_refresh_token' => !empty($tokenData['refresh_token']),
                'expires_in' => $tokenData['expires_in'] ?? 'N/A',
            ]);

            // Buscar contas disponíveis
            $accounts = $this->oauthService->fetchAccounts($originalPlatform, $tokenData['access_token']);

            SystemLog::info('oauth', 'oauth.callback.accounts_fetched', count($accounts) . " conta(s) encontrada(s) para {$originalPlatform}", [
                'count' => count($accounts),
                'accounts' => collect($accounts)->map(fn($a) => [
                    'username' => $a['username'] ?? 'N/A',
                    'type' => $a['type'] ?? 'N/A',
                    'platform_user_id' => $a['platform_user_id'] ?? 'N/A',
                ])->toArray(),
            ]);

            if (empty($accounts)) {
                if ($isPopup) {
                    return view('oauth.callback-popup', [
                        'status' => 'error',
                        'message' => 'Nenhuma conta encontrada para esta plataforma.',
                        'platform' => $originalPlatform,
                        'accountsCount' => 0,
                        'discoveryToken' => null,
                    ]);
                }
                return redirect()->route('social.accounts.index')
                    ->with('error', 'Nenhuma conta encontrada para esta plataforma.');
            }

            // ===== SALVAR NO BANCO DE DADOS (nao mais sessao) =====
            // Isso resolve o problema de sessao nao compartilhada entre popup e janela principal
            $expiresAt = isset($tokenData['expires_in'])
                ? now()->addSeconds($tokenData['expires_in'])
                : null;

            $discoveryToken = OAuthDiscoveredAccount::generateToken();

            // Limpar registros anteriores deste usuario/brand
            OAuthDiscoveredAccount::where('user_id', $userId)
                ->where('brand_id', $brandId)
                ->delete();

            $discovery = OAuthDiscoveredAccount::create([
                'session_token' => $discoveryToken,
                'user_id' => $userId,
                'brand_id' => $brandId,
                'platform' => $originalPlatform,
                'accounts' => $accounts,
                'token_data' => [
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'expires_at' => $expiresAt?->toIso8601String(),
                ],
                'expires_at' => now()->addMinutes(30),
            ]);

            SystemLog::info('oauth', 'oauth.callback.saved_to_db', "Contas salvas no banco com token: {$discoveryToken}", [
                'discovery_id' => $discovery->id,
                'token_prefix' => substr($discoveryToken, 0, 12) . '...',
                'expires_at' => $discovery->expires_at->toIso8601String(),
            ]);

            // Limpar sessao e cache de dados oauth antigos
            session()->forget(['oauth_discovered_accounts', 'oauth_token_data', 'oauth_brand_id', 'oauth_platform', 'oauth_state', 'oauth_popup']);
            if ($receivedState) {
                Cache::forget('social_oauth_' . $receivedState);
            }

            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'success',
                    'message' => count($accounts) . ' conta(s) encontrada(s)! Selecione as que deseja conectar.',
                    'platform' => $originalPlatform,
                    'accountsCount' => count($accounts),
                    'discoveryToken' => $discoveryToken,
                ]);
            }

            return redirect()->route('social.accounts.index', ['discovery_token' => $discoveryToken])
                ->with('oauth_success', true);

        } catch (\Throwable $e) {
            SystemLog::error('oauth', 'oauth.callback.error', "Erro no callback OAuth: {$e->getMessage()}", [
                'platform' => $originalPlatform,
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);
            Log::error("OAuth callback error for {$platform}", ['error' => $e->getMessage()]);

            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Erro ao conectar: ' . $e->getMessage(),
                    'platform' => $originalPlatform,
                    'accountsCount' => 0,
                    'discoveryToken' => null,
                ]);
            }

            return redirect()->route('social.accounts.index')
                ->with('error', 'Erro ao conectar: ' . $e->getMessage());
        }
    }

    /**
     * Retorna as contas descobertas do BANCO (via AJAX).
     * Usa token ao inves de sessao para funcionar entre popup e janela principal.
     */
    public function discoveredAccounts(Request $request): JsonResponse
    {
        $token = $request->get('token');
        $userId = auth()->id();

        SystemLog::debug('oauth', 'oauth.discovered.fetch', "Buscando contas descobertas", [
            'has_token' => !empty($token),
            'user_id' => $userId,
        ]);

        if ($token) {
            // Buscar pelo token especifico
            $discovery = OAuthDiscoveredAccount::where('session_token', $token)
                ->where('user_id', $userId)
                ->where('expires_at', '>', now())
                ->first();
        } else {
            // Fallback: buscar o mais recente do usuario
            $discovery = OAuthDiscoveredAccount::where('user_id', $userId)
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->first();
        }

        if ($discovery) {
            SystemLog::info('oauth', 'oauth.discovered.found', "Contas encontradas no banco", [
                'discovery_id' => $discovery->id,
                'platform' => $discovery->platform,
                'count' => count($discovery->accounts),
            ]);

            return response()->json([
                'accounts' => $discovery->accounts,
                'platform' => $discovery->platform,
                'token' => $discovery->session_token,
            ]);
        }

        SystemLog::debug('oauth', 'oauth.discovered.empty', "Nenhuma conta descoberta encontrada");

        return response()->json([
            'accounts' => [],
            'platform' => null,
            'token' => null,
        ]);
    }

    /**
     * Salva as contas selecionadas pelo usuário.
     * Le do BANCO DE DADOS ao inves da sessao.
     */
    public function saveAccounts(Request $request): RedirectResponse
    {
        $request->validate([
            'selected' => 'required|array|min:1',
            'selected.*' => 'integer',
            'discovery_token' => 'required|string|size:64',
        ]);

        $token = $request->input('discovery_token');
        $userId = auth()->id();

        SystemLog::info('oauth', 'oauth.save.start', "Salvando contas selecionadas", [
            'token_prefix' => substr($token, 0, 12) . '...',
            'selected_count' => count($request->input('selected')),
            'selected_indexes' => $request->input('selected'),
        ]);

        // Buscar do banco
        $discovery = OAuthDiscoveredAccount::where('session_token', $token)
            ->where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->first();

        if (!$discovery) {
            SystemLog::error('oauth', 'oauth.save.expired', "Token de descoberta expirado ou invalido", [
                'token_prefix' => substr($token, 0, 12) . '...',
            ]);
            return redirect()->route('social.accounts.index')
                ->with('error', 'Sessão expirada. Tente conectar novamente.');
        }

        $discoveredAccounts = $discovery->accounts;
        $tokenData = $discovery->token_data;
        $brandId = $discovery->brand_id;
        $platform = $discovery->platform;

        $selectedIndexes = $request->input('selected');
        $savedCount = 0;
        $errors = [];

        foreach ($selectedIndexes as $index) {
            if (!isset($discoveredAccounts[$index])) {
                SystemLog::warning('oauth', 'oauth.save.invalid_index', "Indice invalido: {$index}");
                continue;
            }

            $account = $discoveredAccounts[$index];

            try {
                // Verificar se já existe para QUALQUER brand
                $existsGlobal = SocialAccount::where('platform', $account['platform'])
                    ->where('platform_user_id', $account['platform_user_id'])
                    ->first();

                if ($existsGlobal) {
                    $existsGlobal->update([
                        'brand_id' => $brandId,
                        'username' => $account['username'],
                        'display_name' => $account['display_name'],
                        'avatar_url' => $account['avatar_url'],
                        'access_token' => $account['access_token'] ?? $tokenData['access_token'] ?? null,
                        'refresh_token' => $tokenData['refresh_token'] ?? $existsGlobal->refresh_token,
                        'token_expires_at' => $tokenData['expires_at'] ?? $existsGlobal->token_expires_at,
                        'metadata' => $account['metadata'] ?? $existsGlobal->metadata,
                        'is_active' => true,
                    ]);
                    SystemLog::info('oauth', 'oauth.save.updated', "Conta atualizada: {$account['username']}", [
                        'account_id' => $existsGlobal->id,
                        'platform' => $account['platform'],
                    ]);
                } else {
                    $newAccount = SocialAccount::create([
                        'brand_id' => $brandId,
                        'platform' => $account['platform'],
                        'platform_user_id' => $account['platform_user_id'],
                        'username' => $account['username'],
                        'display_name' => $account['display_name'],
                        'avatar_url' => $account['avatar_url'],
                        'access_token' => $account['access_token'] ?? $tokenData['access_token'] ?? null,
                        'refresh_token' => $tokenData['refresh_token'] ?? null,
                        'token_expires_at' => $tokenData['expires_at'] ?? null,
                        'metadata' => is_array($account['metadata'] ?? null) ? $account['metadata'] : null,
                        'is_active' => true,
                    ]);
                    SystemLog::info('oauth', 'oauth.save.created', "Conta criada: {$account['username']}", [
                        'account_id' => $newAccount->id,
                        'platform' => $account['platform'],
                    ]);
                }

                $savedCount++;
            } catch (\Throwable $e) {
                SystemLog::error('oauth', 'oauth.save.account_error', "Erro ao salvar conta: {$account['username']}: {$e->getMessage()}", [
                    'platform' => $account['platform'],
                    'exception' => get_class($e),
                    'trace' => substr($e->getTraceAsString(), 0, 1000),
                ]);
                Log::error("Erro ao salvar conta OAuth: {$account['username']}", [
                    'platform' => $account['platform'],
                    'error' => $e->getMessage(),
                ]);
                $errors[] = $account['username'] . ': ' . $e->getMessage();
            }
        }

        // Limpar registro do banco
        $discovery->delete();

        // Disparar sync de insights automaticamente para as contas salvas
        // para que engajamento, alcance etc. aparecam imediatamente
        if ($savedCount > 0) {
            $savedAccountIds = SocialAccount::where('brand_id', $brandId)
                ->where('is_active', true)
                ->whereIn('platform', collect($selectedIndexes)
                    ->filter(fn($idx) => isset($discoveredAccounts[$idx]))
                    ->map(fn($idx) => $discoveredAccounts[$idx]['platform'])
                    ->unique()
                    ->toArray()
                )
                ->pluck('id');

            foreach ($savedAccountIds as $accountId) {
                SyncSingleSocialAccountJob::dispatch($accountId)->delay(now()->addSeconds(5));
            }

            SystemLog::info('oauth', 'oauth.save.auto_sync_dispatched', "Auto-sync disparado para {$savedAccountIds->count()} conta(s)");
        }

        SystemLog::info('oauth', 'oauth.save.complete', "Salvamento concluido: {$savedCount} salvas, " . count($errors) . " erros", [
            'saved_count' => $savedCount,
            'error_count' => count($errors),
            'errors' => $errors,
        ]);

        if ($savedCount > 0 && empty($errors)) {
            $msg = $savedCount === 1 ? '1 conta conectada com sucesso!' : "{$savedCount} contas conectadas com sucesso!";
            return redirect()->route('social.accounts.index')->with('success', $msg);
        }

        if ($savedCount > 0 && !empty($errors)) {
            return redirect()->route('social.accounts.index')
                ->with('success', "{$savedCount} conta(s) conectada(s). Algumas falharam: " . implode('; ', $errors));
        }

        return redirect()->route('social.accounts.index')
            ->with('error', 'Falha ao salvar contas: ' . implode('; ', $errors));
    }

    /**
     * Verificar credenciais OAuth configuradas para cada plataforma.
     */
    public function checkCredentials(): JsonResponse
    {
        $platforms = [
            'facebook' => $this->hasMetaCredentials(),
            'instagram' => $this->hasMetaCredentials(),
            'linkedin' => !empty(config('social_oauth.linkedin.client_id')) || !empty($this->getSetting('linkedin_client_id')),
            'youtube' => !empty(config('social_oauth.google.client_id')) || !empty($this->getSetting('google_client_id')),
            'tiktok' => !empty(config('social_oauth.tiktok.client_key')) || !empty($this->getSetting('tiktok_client_key')),
            'pinterest' => !empty(config('social_oauth.pinterest.app_id')) || !empty($this->getSetting('pinterest_app_id')),
        ];

        return response()->json($platforms);
    }

    /**
     * Valida se as credenciais OAuth estão configuradas para a plataforma.
     * Lança exceção com mensagem clara se não estiverem.
     */
    private function validateOAuthCredentials(string $platform, string $oauthPlatform, bool $isPopup): void
    {
        $platformNames = [
            'facebook' => 'Meta (Facebook)',
            'instagram' => 'Meta (Instagram)',
            'linkedin' => 'LinkedIn',
            'youtube' => 'Google (YouTube)',
            'tiktok' => 'TikTok',
            'pinterest' => 'Pinterest',
        ];

        $hasCredentials = match ($oauthPlatform) {
            'facebook' => $this->hasMetaCredentials(),
            'linkedin' => !empty(config('social_oauth.linkedin.client_id')) || !empty($this->getSetting('linkedin_client_id')),
            'youtube' => !empty(config('social_oauth.google.client_id')) || !empty($this->getSetting('google_client_id')),
            'tiktok' => !empty(config('social_oauth.tiktok.client_key')) || !empty($this->getSetting('tiktok_client_key')),
            'pinterest' => !empty(config('social_oauth.pinterest.app_id')) || !empty($this->getSetting('pinterest_app_id')),
            default => false,
        };

        if (!$hasCredentials) {
            $name = $platformNames[$platform] ?? $platform;
            throw new \RuntimeException(
                "Credenciais OAuth para {$name} não configuradas. Acesse Configurações > OAuth para configurar."
            );
        }
    }

    private function hasMetaCredentials(): bool
    {
        return !empty(config('social_oauth.meta.app_id')) || !empty($this->getSetting('meta_app_id'));
    }

    private function getSetting(string $key): ?string
    {
        try {
            return \App\Models\Setting::get('oauth', $key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
