<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\Social\SocialOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        if (!in_array($platform, $validPlatforms)) {
            if ($isPopup) {
                return response()->json(['error' => 'Plataforma não suportada.'], 400);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Plataforma não suportada.');
        }

        $brand = $request->user()->getActiveBrand();
        if (!$brand) {
            if ($isPopup) {
                return response()->json(['error' => 'Selecione uma marca ativa antes de conectar.'], 400);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Selecione uma marca ativa antes de conectar.');
        }

        // Para Meta: Instagram e Facebook usam a mesma autenticação
        $oauthPlatform = in_array($platform, ['facebook', 'instagram']) ? 'facebook' : $platform;

        // Gerar state para segurança CSRF
        $state = Str::random(40);
        session([
            'oauth_state' => $state,
            'oauth_platform' => $platform,
            'oauth_brand_id' => $brand->id,
            'oauth_popup' => $isPopup,
        ]);

        $redirectUri = route('social.oauth.callback', $oauthPlatform);

        try {
            $authUrl = $this->oauthService->getAuthorizationUrl($platform, $redirectUri, $state);

            if ($isPopup) {
                return response()->json(['url' => $authUrl]);
            }

            return redirect()->away($authUrl);
        } catch (\Throwable $e) {
            Log::error("OAuth redirect error for {$platform}", ['error' => $e->getMessage()]);
            if ($isPopup) {
                return response()->json(['error' => 'Erro ao iniciar autenticação: ' . $e->getMessage()], 500);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Erro ao iniciar autenticação: ' . $e->getMessage());
        }
    }

    /**
     * Callback de retorno após autorização na plataforma.
     * Troca o code por token e busca as contas disponíveis.
     * Suporta modo popup (retorna blade que fecha popup).
     */
    public function callback(Request $request, string $platform): RedirectResponse|\Illuminate\Contracts\View\View
    {
        $isPopup = session('oauth_popup', false);

        // Validar state
        $storedState = session('oauth_state');
        $receivedState = $request->get('state');

        if (!$storedState || $storedState !== $receivedState) {
            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Sessão inválida. Tente novamente.',
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'brandId' => null,
                ]);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Sessão inválida. Tente novamente.');
        }

        // Verificar erro
        if ($request->has('error')) {
            $errorDesc = $request->get('error_description', $request->get('error'));
            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Autorização negada: ' . $errorDesc,
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'brandId' => null,
                ]);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Autorização negada: ' . $errorDesc);
        }

        $code = $request->get('code');
        if (!$code) {
            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Código de autorização não recebido.',
                    'platform' => $platform,
                    'accountsCount' => 0,
                    'brandId' => null,
                ]);
            }
            return redirect()->route('social.accounts.index')
                ->with('error', 'Código de autorização não recebido.');
        }

        $originalPlatform = session('oauth_platform', $platform);
        $brandId = session('oauth_brand_id');

        // Limpar dados temporários (manter dados das contas)
        session()->forget(['oauth_state', 'oauth_popup']);

        $redirectUri = route('social.oauth.callback', $platform);

        try {
            // Trocar code por token
            $tokenData = $this->oauthService->exchangeCode($originalPlatform, $code, $redirectUri);

            // Buscar contas disponíveis
            $accounts = $this->oauthService->fetchAccounts($originalPlatform, $tokenData['access_token']);

            if (empty($accounts)) {
                if ($isPopup) {
                    return view('oauth.callback-popup', [
                        'status' => 'error',
                        'message' => 'Nenhuma conta encontrada para esta plataforma.',
                        'platform' => $originalPlatform,
                        'accountsCount' => 0,
                        'brandId' => $brandId,
                    ]);
                }
                return redirect()->route('social.accounts.index')
                    ->with('error', 'Nenhuma conta encontrada para esta plataforma.');
            }

            // Guardar contas na sessão para o usuário selecionar
            $expiresAt = isset($tokenData['expires_in'])
                ? now()->addSeconds($tokenData['expires_in'])
                : null;

            session([
                'oauth_discovered_accounts' => $accounts,
                'oauth_token_data' => [
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'expires_at' => $expiresAt?->toIso8601String(),
                ],
                'oauth_brand_id' => $brandId,
                'oauth_platform' => $originalPlatform,
            ]);

            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'success',
                    'message' => count($accounts) . ' conta(s) encontrada(s)! Selecione as que deseja conectar.',
                    'platform' => $originalPlatform,
                    'accountsCount' => count($accounts),
                    'brandId' => $brandId,
                ]);
            }

            return redirect()->route('social.accounts.index')
                ->with('oauth_success', true);

        } catch (\Throwable $e) {
            Log::error("OAuth callback error for {$platform}", ['error' => $e->getMessage()]);

            if ($isPopup) {
                return view('oauth.callback-popup', [
                    'status' => 'error',
                    'message' => 'Erro ao conectar: ' . $e->getMessage(),
                    'platform' => $originalPlatform,
                    'accountsCount' => 0,
                    'brandId' => $brandId,
                ]);
            }

            return redirect()->route('social.accounts.index')
                ->with('error', 'Erro ao conectar: ' . $e->getMessage());
        }
    }

    /**
     * Retorna as contas descobertas na sessão (chamado via AJAX).
     */
    public function discoveredAccounts(Request $request): JsonResponse
    {
        $accounts = session('oauth_discovered_accounts', []);

        return response()->json([
            'accounts' => $accounts,
            'platform' => session('oauth_platform'),
        ]);
    }

    /**
     * Salva as contas selecionadas pelo usuário.
     */
    public function saveAccounts(Request $request): RedirectResponse
    {
        $request->validate([
            'selected' => 'required|array|min:1',
            'selected.*' => 'integer',
        ]);

        $discoveredAccounts = session('oauth_discovered_accounts', []);
        $tokenData = session('oauth_token_data', []);
        $brandId = session('oauth_brand_id');
        $platform = session('oauth_platform');

        if (empty($discoveredAccounts) || !$brandId) {
            return redirect()->route('social.accounts.index')
                ->with('error', 'Sessão expirada. Tente conectar novamente.');
        }

        $selectedIndexes = $request->input('selected');
        $savedCount = 0;

        foreach ($selectedIndexes as $index) {
            if (!isset($discoveredAccounts[$index])) {
                continue;
            }

            $account = $discoveredAccounts[$index];

            // Verificar se já existe
            $exists = SocialAccount::where('brand_id', $brandId)
                ->where('platform', $account['platform'])
                ->where('platform_user_id', $account['platform_user_id'])
                ->first();

            if ($exists) {
                // Atualizar token e dados
                $exists->update([
                    'username' => $account['username'],
                    'display_name' => $account['display_name'],
                    'avatar_url' => $account['avatar_url'],
                    'access_token' => $account['access_token'] ?? $tokenData['access_token'] ?? null,
                    'refresh_token' => $tokenData['refresh_token'] ?? $exists->refresh_token,
                    'token_expires_at' => $tokenData['expires_at'] ?? $exists->token_expires_at,
                    'metadata' => $account['metadata'] ?? $exists->metadata,
                    'is_active' => true,
                ]);
            } else {
                SocialAccount::create([
                    'brand_id' => $brandId,
                    'platform' => $account['platform'],
                    'platform_user_id' => $account['platform_user_id'],
                    'username' => $account['username'],
                    'display_name' => $account['display_name'],
                    'avatar_url' => $account['avatar_url'],
                    'access_token' => $account['access_token'] ?? $tokenData['access_token'] ?? null,
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => $tokenData['expires_at'] ?? null,
                    'metadata' => $account['metadata'] ?? null,
                    'is_active' => true,
                ]);
            }

            $savedCount++;
        }

        // Limpar sessão
        session()->forget(['oauth_discovered_accounts', 'oauth_token_data', 'oauth_brand_id', 'oauth_platform']);

        $msg = $savedCount === 1 ? '1 conta conectada com sucesso!' : "{$savedCount} contas conectadas com sucesso!";

        return redirect()->route('social.accounts.index')
            ->with('success', $msg);
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
