<?php

namespace App\Http\Controllers;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\SystemLog;
use App\Services\Social\Postiz\PostizGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Gerencia conexões e desconexões de contas sociais via Postiz Cloud.
 *
 * Ao contrário do OAuth direto, o Postiz processa o callback internamente —
 * após a autorização, precisamos sincronizar ($sync) as integrations retornadas
 * pela API para criar/atualizar rows na tabela social_accounts.
 */
class PostizIntegrationController extends Controller
{
    /**
     * Plataformas suportadas via Postiz nesta implementação.
     */
    private const SUPPORTED_PLATFORMS = [
        'linkedin',
        'linkedin_page',
        'tiktok',
        'google_my_business',
    ];

    public function redirect(Request $request, string $platform): JsonResponse|RedirectResponse
    {
        $isPopup = $request->boolean('popup', false);

        if (!in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
            $msg = "Plataforma {$platform} não suportada via Postiz.";
            SystemLog::warning('oauth', 'postiz.redirect.invalid_platform', $msg);
            return $isPopup
                ? response()->json(['error' => $msg], 400)
                : redirect()->route('social.accounts.index')->with('error', $msg);
        }

        $socialPlatform = SocialPlatform::from($platform);
        $postizType = $socialPlatform->postizIdentifier();

        try {
            $gateway = PostizGateway::fromConfig();

            if (!$gateway->isConfigured()) {
                $msg = 'Postiz não configurado. Defina POSTIZ_API_KEY em .env.';
                return $isPopup
                    ? response()->json(['error' => $msg], 400)
                    : redirect()->route('social.accounts.index')->with('error', $msg);
            }

            $url = $gateway->generateOAuthUrl($postizType);

            SystemLog::info('oauth', 'postiz.redirect.success', "URL OAuth Postiz gerada para {$platform}", [
                'platform' => $platform,
                'postiz_type' => $postizType,
            ]);

            return $isPopup
                ? response()->json(['url' => $url])
                : redirect()->away($url);
        } catch (Throwable $e) {
            SystemLog::error('oauth', 'postiz.redirect.error', "Erro ao gerar URL Postiz: {$e->getMessage()}", [
                'platform' => $platform,
            ]);
            $msg = 'Erro ao iniciar conexão Postiz: ' . $e->getMessage();
            return $isPopup
                ? response()->json(['error' => $msg], 500)
                : redirect()->route('social.accounts.index')->with('error', $msg);
        }
    }

    /**
     * Sincroniza as integrations do Postiz com a tabela social_accounts.
     *
     * Cria rows novas para integrations que ainda não existem no DB,
     * atualiza metadados das já vinculadas, e marca como inativas as
     * integrations que deixaram de existir no Postiz.
     */
    public function sync(Request $request): JsonResponse
    {
        $brand = $request->user()->getActiveBrand();

        if (!$brand) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhuma marca ativa. Selecione uma marca antes de sincronizar.',
            ], 400);
        }

        try {
            $gateway = PostizGateway::fromConfig();
            $integrations = $gateway->listIntegrations();

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $postizIds = [];

            foreach ($integrations as $integration) {
                $platform = $this->mapPostizIdentifierToPlatform($integration['identifier'] ?? '');
                if (!$platform) {
                    $skipped++;
                    continue;
                }

                $postizIds[] = $integration['id'];

                $existing = SocialAccount::where('postiz_integration_id', $integration['id'])->first();

                $attrs = [
                    'brand_id' => $brand->id,
                    'platform' => $platform->value,
                    'source' => 'postiz',
                    'postiz_integration_id' => $integration['id'],
                    'platform_user_id' => $integration['id'],
                    'username' => $integration['profile'] ?? $integration['name'] ?? $integration['id'],
                    'display_name' => $integration['name'] ?? $integration['profile'] ?? '',
                    'avatar_url' => $integration['picture'] ?? null,
                    'is_active' => !($integration['disabled'] ?? false),
                    'metadata' => [
                        'postiz' => [
                            'identifier' => $integration['identifier'] ?? null,
                            'customer' => $integration['customer'] ?? null,
                        ],
                    ],
                ];

                if ($existing) {
                    $existing->update($attrs);
                    $updated++;
                } else {
                    SocialAccount::create($attrs);
                    $created++;
                }
            }

            // Marca como inativas contas Postiz cujo integration_id não veio mais na listagem
            $deactivated = SocialAccount::postiz()
                ->where('brand_id', $brand->id)
                ->when(!empty($postizIds), fn($q) => $q->whereNotIn('postiz_integration_id', $postizIds))
                ->where('is_active', true)
                ->update(['is_active' => false]);

            SystemLog::info('oauth', 'postiz.sync.complete', "Sync Postiz concluído", [
                'brand_id' => $brand->id,
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
                'skipped' => $skipped,
                'total_integrations' => count($integrations),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Sync concluído: {$created} criada(s), {$updated} atualizada(s), {$deactivated} desativada(s).",
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
                'skipped' => $skipped,
            ]);
        } catch (Throwable $e) {
            SystemLog::error('oauth', 'postiz.sync.error', "Erro ao sincronizar Postiz: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Erro ao sincronizar: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function disconnect(Request $request, SocialAccount $account): JsonResponse
    {
        if ($account->source !== 'postiz' || !$account->postiz_integration_id) {
            return response()->json([
                'success' => false,
                'message' => 'Esta conta não é gerenciada via Postiz.',
            ], 400);
        }

        try {
            PostizGateway::fromConfig()->deleteIntegration($account->postiz_integration_id);
            $account->delete();

            SystemLog::info('oauth', 'postiz.disconnect.success', "Conta Postiz #{$account->id} desconectada", [
                'account_id' => $account->id,
                'integration_id' => $account->postiz_integration_id,
            ]);

            return response()->json(['success' => true, 'message' => 'Conta desconectada.']);
        } catch (Throwable $e) {
            SystemLog::error('oauth', 'postiz.disconnect.error', "Erro ao desconectar conta Postiz: {$e->getMessage()}", [
                'account_id' => $account->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erro ao desconectar: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mapeia o identifier retornado pelo Postiz (string) para o enum interno.
     * Retorna null se a plataforma Postiz não está no escopo desta integração
     * (ex: X/Twitter, Threads — poderão ser habilitados no futuro).
     */
    private function mapPostizIdentifierToPlatform(string $identifier): ?SocialPlatform
    {
        return match ($identifier) {
            'linkedin' => SocialPlatform::LinkedIn,
            'linkedin-page' => SocialPlatform::LinkedInPage,
            'tiktok' => SocialPlatform::TikTok,
            'googlebusiness' => SocialPlatform::GoogleMyBusiness,
            default => null,
        };
    }
}
