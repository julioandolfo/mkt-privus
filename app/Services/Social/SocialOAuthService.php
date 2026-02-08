<?php

namespace App\Services\Social;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SocialOAuthService
{
    /**
     * Gera a URL de autorização OAuth para cada plataforma.
     */
    public function getAuthorizationUrl(string $platform, string $redirectUri, string $state): string
    {
        return match ($platform) {
            'facebook', 'instagram' => $this->metaAuthUrl($redirectUri, $state),
            'linkedin' => $this->linkedinAuthUrl($redirectUri, $state),
            'youtube' => $this->googleAuthUrl($redirectUri, $state),
            'tiktok' => $this->tiktokAuthUrl($redirectUri, $state),
            'pinterest' => $this->pinterestAuthUrl($redirectUri, $state),
            default => throw new \InvalidArgumentException("Plataforma '{$platform}' não suportada para OAuth."),
        };
    }

    /**
     * Troca o authorization code por access token.
     */
    public function exchangeCode(string $platform, string $code, string $redirectUri): array
    {
        return match ($platform) {
            'facebook', 'instagram' => $this->metaExchangeCode($code, $redirectUri),
            'linkedin' => $this->linkedinExchangeCode($code, $redirectUri),
            'youtube' => $this->googleExchangeCode($code, $redirectUri),
            'tiktok' => $this->tiktokExchangeCode($code, $redirectUri),
            'pinterest' => $this->pinterestExchangeCode($code, $redirectUri),
            default => throw new \InvalidArgumentException("Plataforma não suportada."),
        };
    }

    /**
     * Busca todas as contas/páginas disponíveis após autenticação.
     */
    public function fetchAccounts(string $platform, string $accessToken): array
    {
        return match ($platform) {
            'facebook' => $this->fetchFacebookPages($accessToken),
            'instagram' => $this->fetchInstagramAccounts($accessToken),
            'linkedin' => $this->fetchLinkedinOrganizations($accessToken),
            'youtube' => $this->fetchYoutubeChannels($accessToken),
            'tiktok' => $this->fetchTiktokUser($accessToken),
            'pinterest' => $this->fetchPinterestUser($accessToken),
            default => [],
        };
    }

    // ================================================================
    // META (Facebook + Instagram)
    // ================================================================

    private function metaConfig(): array
    {
        return [
            'app_id' => $this->getSetting('meta_app_id') ?: config('social_oauth.meta.app_id'),
            'app_secret' => $this->getSetting('meta_app_secret') ?: config('social_oauth.meta.app_secret'),
            'api_version' => config('social_oauth.meta.api_version', 'v19.0'),
            'scopes' => config('social_oauth.meta.scopes', []),
        ];
    }

    private function metaAuthUrl(string $redirectUri, string $state): string
    {
        $config = $this->metaConfig();
        $scopes = implode(',', $config['scopes']);

        return "https://www.facebook.com/{$config['api_version']}/dialog/oauth?" . http_build_query([
            'client_id' => $config['app_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $scopes,
            'response_type' => 'code',
        ]);
    }

    private function metaExchangeCode(string $code, string $redirectUri): array
    {
        $config = $this->metaConfig();

        $response = Http::get("https://graph.facebook.com/{$config['api_version']}/oauth/access_token", [
            'client_id' => $config['app_id'],
            'client_secret' => $config['app_secret'],
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (!$response->successful()) {
            Log::error('Meta OAuth exchange failed', ['response' => $response->json()]);
            throw new \RuntimeException('Falha ao trocar código por token Meta: ' . ($response->json('error.message') ?? 'Erro desconhecido'));
        }

        $data = $response->json();
        $shortToken = $data['access_token'];

        // Trocar por token de longa duração (60 dias)
        $longResponse = Http::get("https://graph.facebook.com/{$config['api_version']}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $config['app_id'],
            'client_secret' => $config['app_secret'],
            'fb_exchange_token' => $shortToken,
        ]);

        if ($longResponse->successful()) {
            $longData = $longResponse->json();
            return [
                'access_token' => $longData['access_token'],
                'expires_in' => $longData['expires_in'] ?? 5184000, // ~60 dias
                'token_type' => 'bearer',
            ];
        }

        return [
            'access_token' => $shortToken,
            'expires_in' => $data['expires_in'] ?? 3600,
            'token_type' => 'bearer',
        ];
    }

    private function fetchFacebookPages(string $accessToken): array
    {
        $config = $this->metaConfig();

        // Buscar dados do usuário
        $me = Http::get("https://graph.facebook.com/{$config['api_version']}/me", [
            'access_token' => $accessToken,
            'fields' => 'id,name,picture',
        ]);

        $accounts = [];

        if ($me->successful()) {
            $user = $me->json();
            $accounts[] = [
                'platform' => 'facebook',
                'type' => 'profile',
                'platform_user_id' => $user['id'],
                'username' => $user['name'],
                'display_name' => $user['name'],
                'avatar_url' => $user['picture']['data']['url'] ?? null,
                'access_token' => $accessToken,
                'metadata' => ['type' => 'profile'],
            ];
        }

        // Buscar páginas que o usuário administra
        $pages = Http::get("https://graph.facebook.com/{$config['api_version']}/me/accounts", [
            'access_token' => $accessToken,
            'fields' => 'id,name,access_token,picture,category,fan_count,followers_count',
        ]);

        if ($pages->successful()) {
            foreach ($pages->json('data', []) as $page) {
                $accounts[] = [
                    'platform' => 'facebook',
                    'type' => 'page',
                    'platform_user_id' => $page['id'],
                    'username' => $page['name'],
                    'display_name' => $page['name'],
                    'avatar_url' => $page['picture']['data']['url'] ?? null,
                    'access_token' => $page['access_token'], // Token da página (não expira)
                    'metadata' => [
                        'type' => 'page',
                        'category' => $page['category'] ?? null,
                        'fan_count' => $page['fan_count'] ?? null,
                        'followers_count' => $page['followers_count'] ?? null,
                    ],
                ];
            }
        }

        return $accounts;
    }

    private function fetchInstagramAccounts(string $accessToken): array
    {
        $config = $this->metaConfig();
        $accounts = [];

        // Instagram Business: precisa buscar via páginas do Facebook
        $pages = Http::get("https://graph.facebook.com/{$config['api_version']}/me/accounts", [
            'access_token' => $accessToken,
            'fields' => 'id,name,access_token,instagram_business_account',
        ]);

        if ($pages->successful()) {
            foreach ($pages->json('data', []) as $page) {
                $igAccountId = $page['instagram_business_account']['id'] ?? null;

                if ($igAccountId) {
                    // Buscar detalhes da conta Instagram
                    $ig = Http::get("https://graph.facebook.com/{$config['api_version']}/{$igAccountId}", [
                        'access_token' => $page['access_token'],
                        'fields' => 'id,username,name,profile_picture_url,followers_count,media_count,biography',
                    ]);

                    if ($ig->successful()) {
                        $igData = $ig->json();
                        $accounts[] = [
                            'platform' => 'instagram',
                            'type' => 'business',
                            'platform_user_id' => $igData['id'],
                            'username' => $igData['username'] ?? '',
                            'display_name' => $igData['name'] ?? $igData['username'] ?? '',
                            'avatar_url' => $igData['profile_picture_url'] ?? null,
                            'access_token' => $page['access_token'], // Usa token da página
                            'metadata' => [
                                'type' => 'business',
                                'facebook_page_id' => $page['id'],
                                'facebook_page_name' => $page['name'],
                                'followers_count' => $igData['followers_count'] ?? null,
                                'media_count' => $igData['media_count'] ?? null,
                                'biography' => $igData['biography'] ?? null,
                            ],
                        ];
                    }
                }
            }
        }

        return $accounts;
    }

    // ================================================================
    // LINKEDIN
    // ================================================================

    private function linkedinConfig(): array
    {
        return [
            'client_id' => $this->getSetting('linkedin_client_id') ?: config('social_oauth.linkedin.client_id'),
            'client_secret' => $this->getSetting('linkedin_client_secret') ?: config('social_oauth.linkedin.client_secret'),
            'scopes' => config('social_oauth.linkedin.scopes', []),
        ];
    }

    private function linkedinAuthUrl(string $redirectUri, string $state): string
    {
        $config = $this->linkedinConfig();
        $scopes = implode(' ', $config['scopes']);

        return "https://www.linkedin.com/oauth/v2/authorization?" . http_build_query([
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $scopes,
        ]);
    }

    private function linkedinExchangeCode(string $code, string $redirectUri): array
    {
        $config = $this->linkedinConfig();

        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao trocar código LinkedIn: ' . $response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'expires_in' => $data['expires_in'] ?? 5184000,
            'refresh_token' => $data['refresh_token'] ?? null,
            'token_type' => 'bearer',
        ];
    }

    private function fetchLinkedinOrganizations(string $accessToken): array
    {
        $accounts = [];

        // Buscar perfil do usuário
        $profile = Http::withToken($accessToken)->get('https://api.linkedin.com/v2/userinfo');

        if ($profile->successful()) {
            $user = $profile->json();
            $accounts[] = [
                'platform' => 'linkedin',
                'type' => 'profile',
                'platform_user_id' => $user['sub'] ?? '',
                'username' => $user['name'] ?? $user['given_name'] ?? '',
                'display_name' => $user['name'] ?? '',
                'avatar_url' => $user['picture'] ?? null,
                'access_token' => $accessToken,
                'metadata' => ['type' => 'profile', 'email' => $user['email'] ?? null],
            ];
        }

        // Buscar organizações administradas
        $orgs = Http::withToken($accessToken)->get('https://api.linkedin.com/v2/organizationalEntityAcls', [
            'q' => 'roleAssignee',
            'role' => 'ADMINISTRATOR',
            'projection' => '(elements*(organizationalTarget))',
        ]);

        if ($orgs->successful()) {
            foreach ($orgs->json('elements', []) as $element) {
                $orgUrn = $element['organizationalTarget'] ?? '';
                $orgId = str_replace('urn:li:organization:', '', $orgUrn);

                if ($orgId) {
                    $orgDetail = Http::withToken($accessToken)->get("https://api.linkedin.com/v2/organizations/{$orgId}", [
                        'projection' => '(id,localizedName,vanityName,logoV2)',
                    ]);

                    if ($orgDetail->successful()) {
                        $org = $orgDetail->json();
                        $accounts[] = [
                            'platform' => 'linkedin',
                            'type' => 'organization',
                            'platform_user_id' => $orgId,
                            'username' => $org['vanityName'] ?? $org['localizedName'] ?? '',
                            'display_name' => $org['localizedName'] ?? '',
                            'avatar_url' => null,
                            'access_token' => $accessToken,
                            'metadata' => ['type' => 'organization'],
                        ];
                    }
                }
            }
        }

        return $accounts;
    }

    // ================================================================
    // GOOGLE / YOUTUBE
    // ================================================================

    private function googleConfig(): array
    {
        return [
            'client_id' => $this->getSetting('google_client_id') ?: config('social_oauth.google.client_id'),
            'client_secret' => $this->getSetting('google_client_secret') ?: config('social_oauth.google.client_secret'),
            'scopes' => config('social_oauth.google.scopes', []),
        ];
    }

    private function googleAuthUrl(string $redirectUri, string $state): string
    {
        $config = $this->googleConfig();
        $scopes = implode(' ', $config['scopes']);

        return "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scopes,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    private function googleExchangeCode(string $code, string $redirectUri): array
    {
        $config = $this->googleConfig();

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao trocar código Google: ' . $response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => $data['expires_in'] ?? 3600,
            'token_type' => 'bearer',
        ];
    }

    private function fetchYoutubeChannels(string $accessToken): array
    {
        $accounts = [];

        $response = Http::withToken($accessToken)->get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'snippet,statistics,brandingSettings',
            'mine' => 'true',
        ]);

        if ($response->successful()) {
            foreach ($response->json('items', []) as $channel) {
                $accounts[] = [
                    'platform' => 'youtube',
                    'type' => 'channel',
                    'platform_user_id' => $channel['id'],
                    'username' => $channel['snippet']['customUrl'] ?? $channel['snippet']['title'],
                    'display_name' => $channel['snippet']['title'],
                    'avatar_url' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
                    'access_token' => $accessToken,
                    'metadata' => [
                        'type' => 'channel',
                        'subscriber_count' => $channel['statistics']['subscriberCount'] ?? null,
                        'video_count' => $channel['statistics']['videoCount'] ?? null,
                        'view_count' => $channel['statistics']['viewCount'] ?? null,
                        'description' => $channel['snippet']['description'] ?? null,
                    ],
                ];
            }
        }

        return $accounts;
    }

    // ================================================================
    // TIKTOK
    // ================================================================

    private function tiktokConfig(): array
    {
        return [
            'client_key' => $this->getSetting('tiktok_client_key') ?: config('social_oauth.tiktok.client_key'),
            'client_secret' => $this->getSetting('tiktok_client_secret') ?: config('social_oauth.tiktok.client_secret'),
            'scopes' => config('social_oauth.tiktok.scopes', []),
        ];
    }

    private function tiktokAuthUrl(string $redirectUri, string $state): string
    {
        $config = $this->tiktokConfig();
        $scopes = implode(',', $config['scopes']);

        return "https://www.tiktok.com/v2/auth/authorize/?" . http_build_query([
            'client_key' => $config['client_key'],
            'redirect_uri' => $redirectUri,
            'scope' => $scopes,
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    private function tiktokExchangeCode(string $code, string $redirectUri): array
    {
        $config = $this->tiktokConfig();

        $response = Http::post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $config['client_key'],
            'client_secret' => $config['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao trocar código TikTok: ' . $response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => $data['expires_in'] ?? 86400,
            'open_id' => $data['open_id'] ?? null,
            'token_type' => 'bearer',
        ];
    }

    private function fetchTiktokUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get('https://open.tiktokapis.com/v2/user/info/', [
            'fields' => 'open_id,union_id,avatar_url,display_name,username,follower_count,following_count,likes_count,video_count',
        ]);

        if (!$response->successful()) {
            return [];
        }

        $user = $response->json('data.user', []);

        return [[
            'platform' => 'tiktok',
            'type' => 'creator',
            'platform_user_id' => $user['open_id'] ?? '',
            'username' => $user['username'] ?? $user['display_name'] ?? '',
            'display_name' => $user['display_name'] ?? '',
            'avatar_url' => $user['avatar_url'] ?? null,
            'access_token' => $accessToken,
            'metadata' => [
                'type' => 'creator',
                'follower_count' => $user['follower_count'] ?? null,
                'following_count' => $user['following_count'] ?? null,
                'likes_count' => $user['likes_count'] ?? null,
                'video_count' => $user['video_count'] ?? null,
            ],
        ]];
    }

    // ================================================================
    // PINTEREST
    // ================================================================

    private function pinterestConfig(): array
    {
        return [
            'app_id' => $this->getSetting('pinterest_app_id') ?: config('social_oauth.pinterest.app_id'),
            'app_secret' => $this->getSetting('pinterest_app_secret') ?: config('social_oauth.pinterest.app_secret'),
            'scopes' => config('social_oauth.pinterest.scopes', []),
        ];
    }

    private function pinterestAuthUrl(string $redirectUri, string $state): string
    {
        $config = $this->pinterestConfig();
        $scopes = implode(',', $config['scopes']);

        return "https://www.pinterest.com/oauth/?" . http_build_query([
            'client_id' => $config['app_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scopes,
            'state' => $state,
        ]);
    }

    private function pinterestExchangeCode(string $code, string $redirectUri): array
    {
        $config = $this->pinterestConfig();

        $response = Http::withBasicAuth($config['app_id'], $config['app_secret'])
            ->asForm()
            ->post('https://api.pinterest.com/v5/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Falha ao trocar código Pinterest: ' . $response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => $data['expires_in'] ?? 2592000,
            'token_type' => 'bearer',
        ];
    }

    private function fetchPinterestUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get('https://api.pinterest.com/v5/user_account');

        if (!$response->successful()) {
            return [];
        }

        $user = $response->json();

        return [[
            'platform' => 'pinterest',
            'type' => 'profile',
            'platform_user_id' => $user['username'] ?? '',
            'username' => $user['username'] ?? '',
            'display_name' => $user['business_name'] ?? $user['username'] ?? '',
            'avatar_url' => $user['profile_image'] ?? null,
            'access_token' => $accessToken,
            'metadata' => [
                'type' => 'profile',
                'follower_count' => $user['follower_count'] ?? null,
                'pin_count' => $user['pin_count'] ?? null,
            ],
        ]];
    }

    // ================================================================
    // HELPERS
    // ================================================================

    private function getSetting(string $key): ?string
    {
        try {
            return Setting::get('oauth', $key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
