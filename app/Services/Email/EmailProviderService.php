<?php

namespace App\Services\Email;

use App\Models\EmailProvider;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

class EmailProviderService
{
    /**
     * Envia um email individual via o provedor configurado
     */
    public function send(
        EmailProvider $provider,
        string $to,
        string $subject,
        string $html,
        ?string $fromName = null,
        ?string $fromEmail = null,
        ?string $replyTo = null,
        array $headers = []
    ): array {
        if (!$provider->hasQuotaRemaining()) {
            return ['success' => false, 'error' => 'Limite diário de envios atingido.'];
        }

        try {
            $result = match ($provider->type) {
                'smtp' => $this->sendViaSMTP($provider, $to, $subject, $html, $fromName, $fromEmail, $replyTo, $headers),
                'sendpulse' => $this->sendViaSendPulse($provider, $to, $subject, $html, $fromName, $fromEmail, $replyTo, $headers),
                default => ['success' => false, 'error' => "Tipo de provedor desconhecido: {$provider->type}"],
            };

            if ($result['success']) {
                $provider->incrementSendCount();
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error("Email send failed via {$provider->type}", [
                'provider_id' => $provider->id,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Envia via SMTP customizado
     */
    private function sendViaSMTP(
        EmailProvider $provider,
        string $to,
        string $subject,
        string $html,
        ?string $fromName,
        ?string $fromEmail,
        ?string $replyTo,
        array $headers
    ): array {
        $config = $provider->config;

        // Configurar mailer dinamico
        config([
            'mail.mailers.dynamic_smtp' => [
                'transport' => 'smtp',
                'host' => $config['host'],
                'port' => $config['port'] ?? 587,
                'encryption' => $config['encryption'] ?? 'tls',
                'username' => $config['username'],
                'password' => $config['password'],
                'timeout' => 30,
            ],
        ]);

        $from = $fromEmail ?? $config['from_address'] ?? $config['username'];
        $name = $fromName ?? $config['from_name'] ?? config('app.name');

        Mail::mailer('dynamic_smtp')->html($html, function ($message) use ($to, $subject, $from, $name, $replyTo, $headers) {
            $message->to($to)
                ->subject($subject)
                ->from($from, $name);

            if ($replyTo) {
                $message->replyTo($replyTo);
            }

            foreach ($headers as $key => $value) {
                $message->getHeaders()->addTextHeader($key, $value);
            }
        });

        return ['success' => true, 'message_id' => null];
    }

    /**
     * Envia via SendPulse API
     */
    private function sendViaSendPulse(
        EmailProvider $provider,
        string $to,
        string $subject,
        string $html,
        ?string $fromName,
        ?string $fromEmail,
        ?string $replyTo,
        array $headers
    ): array {
        $config = $provider->config;

        // 1. Obter token de acesso
        $tokenResponse = Http::post('https://api.sendpulse.com/oauth/access_token', [
            'grant_type' => 'client_credentials',
            'client_id' => $config['api_user_id'],
            'client_secret' => $config['api_secret'],
        ]);

        if (!$tokenResponse->successful()) {
            return ['success' => false, 'error' => 'Falha ao autenticar com SendPulse: ' . $tokenResponse->body()];
        }

        $accessToken = $tokenResponse->json('access_token');

        // 2. Determinar remetente — SEMPRE priorizar o from_email do config do provedor
        // pois este é o email verificado no SendPulse
        $configFromEmail = $config['from_email'] ?? $config['from_address'] ?? null;
        $from = $configFromEmail ?: $fromEmail;
        $name = $config['from_name'] ?? $fromName ?? config('app.name');

        // Se o fromEmail passado for diferente do config, usar config (o verificado no SendPulse)
        // Evita erro "Unauthorized action" quando um email nao verificado é usado
        if (!$from) {
            return ['success' => false, 'error' => 'Email remetente não configurado no provedor SendPulse.'];
        }

        $payload = [
            'email' => [
                'html' => $html,
                'text' => strip_tags($html),
                'subject' => $subject,
                'from' => [
                    'name' => $name,
                    'email' => $from,
                ],
                'to' => [
                    ['email' => $to],
                ],
            ],
        ];

        if ($replyTo) {
            $payload['email']['reply_to'] = $replyTo;
        }

        $sendResponse = Http::withToken($accessToken)
            ->post('https://api.sendpulse.com/smtp/emails', $payload);

        if ($sendResponse->successful()) {
            return [
                'success' => true,
                'message_id' => $sendResponse->json('id'),
            ];
        }

        // Detalhar erro para facilitar diagnóstico
        $errorMsg = $sendResponse->json('message') ?? $sendResponse->body();
        $statusCode = $sendResponse->status();

        SystemLog::warning('email', 'sendpulse.send.error', "SendPulse envio falhou: {$errorMsg}", [
            'status' => $statusCode,
            'from' => $from,
            'to' => $to,
            'response' => substr($sendResponse->body(), 0, 500),
        ]);

        // Mensagem amigável para erros comuns
        if (str_contains($errorMsg, 'Unauthorized') || $statusCode === 403) {
            $errorMsg = "Email remetente '{$from}' não está verificado no SendPulse. Verifique em SendPulse > SMTP > Sender Emails.";
        }

        return [
            'success' => false,
            'error' => 'SendPulse: ' . $errorMsg,
        ];
    }

    /**
     * Testa a conexao com o provedor
     */
    public function testConnection(EmailProvider $provider): array
    {
        try {
            return match ($provider->type) {
                'smtp' => $this->testSMTP($provider),
                'sendpulse' => $this->testSendPulse($provider),
                default => ['success' => false, 'error' => 'Tipo desconhecido'],
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Testa conexao SMTP
     */
    private function testSMTP(EmailProvider $provider): array
    {
        $config = $provider->config;

        try {
            $scheme = match ($config['encryption'] ?? 'tls') {
                'ssl' => 'smtps',
                'tls' => 'smtp',
                default => 'smtp',
            };

            $dsn = new Dsn(
                $scheme,
                $config['host'],
                $config['username'] ?? null,
                $config['password'] ?? null,
                (int) ($config['port'] ?? 587),
            );

            $factory = new EsmtpTransportFactory();
            $transport = $factory->create($dsn);
            $transport->start();
            $transport->stop();

            return ['success' => true, 'message' => 'Conexão SMTP estabelecida com sucesso.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Falha SMTP: ' . $e->getMessage()];
        }
    }

    /**
     * Testa conexao SendPulse
     */
    private function testSendPulse(EmailProvider $provider): array
    {
        $config = $provider->config;

        $response = Http::post('https://api.sendpulse.com/oauth/access_token', [
            'grant_type' => 'client_credentials',
            'client_id' => $config['api_user_id'],
            'client_secret' => $config['api_secret'],
        ]);

        if ($response->successful() && $response->json('access_token')) {
            return ['success' => true, 'message' => 'Autenticação SendPulse OK.'];
        }

        return [
            'success' => false,
            'error' => 'Falha na autenticação: ' . ($response->json('error_description') ?? $response->body()),
        ];
    }

    /**
     * Envia email de teste
     */
    public function sendTest(EmailProvider $provider, string $testEmail, string $subject = 'Teste de Envio', ?string $html = null): array
    {
        $html = $html ?? '<html><body><h1>Email de Teste</h1><p>Este é um email de teste enviado via ' . $provider->name . '.</p><p>Provedor: ' . $provider->type . '</p><p>Hora: ' . now()->format('d/m/Y H:i:s') . '</p></body></html>';

        return $this->send($provider, $testEmail, $subject, $html);
    }
}
