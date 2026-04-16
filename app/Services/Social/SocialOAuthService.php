<?php

namespace App\Services\Social;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Serviço de OAuth direto para plataformas que não passam pelo Postiz:
 * Meta (Facebook + Instagram) e Google (YouTube).
 *
 * LinkedIn, TikTok, Google My Business etc. são gerenciados via PostizGateway
 * (ver app/Services/Social/Postiz/PostizGateway.php).
 */
class SocialOAuthService
{
    public function getAuthorizationUrl(string $platform, string $redirectUri, string $state): string
    {
        return match ($platform) {
            'facebook', 'instagram' => $this->metaAuthUrl($redirectUri, $state),
            'youtube' => $this->googleAuthUrl($redirectUri, $state),
            default => throw new \InvalidArgumentException("Plataforma '{$platform}' não suportada para OAuth direto."),
        };
    }

    public function exchangeCode(string $platform, string $code, string $redirectUri): array
    {
        return match ($platform) {
            'facebook', 'instagram' => $this->metaExchangeCode($code, $redirectUri),
            'youtube' => $this->googleExchangeCode($code, $redirectUri),
            default => throw new \InvalidArgumentException("Plataforma não suportada."),
        };
    }

    public function fetchAccounts(string $platform, string $accessToken): array
    {
        return match ($platform) {
            'facebook' => $this->fetchFacebookPages($accessToken),
            'instagram' => $this->fetchInstagramAccounts($accessToken),
            'youtube' => $this->fetchYoutubeChannels($accessToken),
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
            'api_version' => config('social_oauth.meta.api_version', 'v21.0'),
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
                'expires_in' => $longData['expires_in'] ?? 5184000,
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
                    'access_token' => $page['access_token'],
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

        $pages = Http::get("https://graph.facebook.com/{$config['api_version']}/me/accounts", [
            'access_token' => $accessToken,
            'fields' => 'id,name,access_token,instagram_business_account',
        ]);

        if ($pages->successful()) {
            foreach ($pages->json('data', []) as $page) {
                $igAccountId = $page['instagram_business_account']['id'] ?? null;

                if ($igAccountId) {
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
                            'access_token' => $page['access_token'],
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
    // TOKEN REFRESH
    // ================================================================

    public function refreshToken(\App\Models\SocialAccount $account): ?array
    {
        $platform = $account->platform->value ?? $account->platform;

        return match ($platform) {
            'facebook', 'instagram' => $this->refreshMetaToken($account),
            'youtube' => $this->refreshGoogleToken($account),
            default => null,
        };
    }

    private function refreshMetaToken(\App\Models\SocialAccount $account): ?array
    {
        $config = $this->metaConfig();
        $currentToken = $account->access_token;

        $response = Http::get("https://graph.facebook.com/{$config['api_version']}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $config['app_id'],
            'client_secret' => $config['app_secret'],
            'fb_exchange_token' => $currentToken,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return [
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'] ?? 5184000,
            ];
        }

        $check = Http::get("https://graph.facebook.com/{$config['api_version']}/me", [
            'access_token' => $currentToken,
            'fields' => 'id',
        ]);

        if ($check->successful()) {
            return [
                'access_token' => $currentToken,
                'expires_in' => 5184000,
            ];
        }

        return null;
    }

    private function refreshGoogleToken(\App\Models\SocialAccount $account): ?array
    {
        if (!$account->refresh_token) {
            return null;
        }

        $config = $this->googleConfig();

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
        ]);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $account->refresh_token,
            'expires_in' => $data['expires_in'] ?? 3600,
        ];
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
