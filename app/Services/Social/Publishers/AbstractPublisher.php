<?php

namespace App\Services\Social\Publishers;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use Illuminate\Support\Facades\Log;

/**
 * Classe base para publishers de redes sociais.
 * Cada plataforma deve estender esta classe e implementar doPublish().
 */
abstract class AbstractPublisher
{
    /**
     * Nome da plataforma para logs
     */
    abstract protected function platformName(): string;

    /**
     * Logica de publicacao especifica da plataforma.
     * Deve ser implementada por cada publisher concreto.
     */
    abstract protected function doPublish(Post $post, SocialAccount $account): PublishResult;

    /**
     * Publica um post na plataforma, com validacoes e tratamento de erro.
     */
    public function publish(Post $post, SocialAccount $account): PublishResult
    {
        $platform = $this->platformName();

        Log::info("Autopilot [{$platform}]: Iniciando publicação", [
            'post_id' => $post->id,
            'account_id' => $account->id,
            'username' => $account->username,
        ]);

        // Validar e renovar token automaticamente se necessário
        if ($account->access_token && $account->isTokenExpired()) {
            if (!$account->ensureFreshToken()) {
                $msg = "Token expirado para @{$account->username} no {$platform}. Reconecte a conta.";
                Log::warning("Autopilot [{$platform}]: {$msg}");
                return PublishResult::fail($msg);
            }
        }

        // Validar conta ativa
        if (!$account->is_active) {
            $msg = "Conta @{$account->username} está inativa no {$platform}";
            Log::warning("Autopilot [{$platform}]: {$msg}");
            return PublishResult::fail($msg);
        }

        try {
            $result = $this->doPublish($post, $account);

            if ($result->success) {
                Log::info("Autopilot [{$platform}]: Publicado com sucesso", [
                    'post_id' => $post->id,
                    'platform_post_id' => $result->platformPostId,
                ]);
            } else {
                Log::error("Autopilot [{$platform}]: Falha na publicação", [
                    'post_id' => $post->id,
                    'error' => $result->errorMessage,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $msg = "Erro inesperado: {$e->getMessage()}";
            Log::error("Autopilot [{$platform}]: {$msg}", [
                'post_id' => $post->id,
                'exception' => $e,
            ]);
            return PublishResult::fail($msg);
        }
    }
}
