<?php

namespace App\Http\Controllers;

use App\Models\EmailProvider;
use App\Services\Email\EmailProviderService;
use App\Services\Sms\SmsProviderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;

class EmailProviderController extends Controller
{
    public function index(Request $request)
    {
        $brandId = session('current_brand_id');

        $providers = EmailProvider::where(function ($q) use ($brandId) {
            $q->where('brand_id', $brandId)->orWhereNull('brand_id');
        })
            ->latest()
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type,
                    'brand_id' => $p->brand_id,
                    'is_active' => $p->is_active,
                    'is_default' => $p->is_default,
                    'daily_limit' => $p->daily_limit,
                    'sends_today' => $p->sends_today,
                    'remaining_quota' => $p->getRemainingQuota(),
                    'hourly_limit' => $p->hourly_limit,
                    'sends_this_hour' => $p->sends_this_hour,
                    'quota_info' => $p->getQuotaInfo(),
                    'campaigns_count' => $p->campaigns()->count(),
                    'created_at' => $p->created_at->format('d/m/Y H:i'),
                    // Informacoes parciais do config para exibicao
                    'config_summary' => $this->getConfigSummary($p),
                    // Config completo para edição (sem secrets expostos na listagem)
                    'config_edit' => $this->getConfigForEdit($p),
                ];
            });

        // Montar URLs dos webhooks para exibição
        $baseUrl = config('app.url');
        $webhooks = [
            [
                'label' => 'Webhook Unificado (Email + SMS)',
                'url' => $baseUrl . '/webhook/sendpulse',
                'method' => 'POST',
                'primary' => true,
                'description' => 'URL principal — recebe e processa automaticamente eventos de Email e SMS. Configure esta URL para TODOS os eventos no painel do SendPulse.',
                'events' => [
                    'Entregue (delivered)',
                    'Erro permanente (hard bounce)',
                    'Erro temporário (soft bounce)',
                    'Marcado como spam (complaint)',
                    'Abertura de email (open)',
                    'Clique no email (click)',
                    'Novo assinante (subscribe)',
                    'Removido da lista',
                    'Cancelou assinatura (unsubscribe)',
                    'Estado de envio mudando',
                    'SMS entregue / falhou / clicado / opt-out',
                ],
            ],
            [
                'label' => 'Email (legado)',
                'url' => $baseUrl . '/email/webhook/sendpulse',
                'method' => 'POST',
                'primary' => false,
                'description' => 'URL alternativa que aceita apenas eventos de email. Use a URL unificada acima preferencialmente.',
                'events' => [],
            ],
            [
                'label' => 'SMS (legado)',
                'url' => $baseUrl . '/sms/webhook/sendpulse',
                'method' => 'POST',
                'primary' => false,
                'description' => 'URL alternativa que aceita apenas eventos de SMS. Use a URL unificada acima preferencialmente.',
                'events' => [],
            ],
        ];

        return Inertia::render('Email/Providers/Index', [
            'providers' => $providers,
            'webhooks' => $webhooks,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:smtp,sendpulse,sms_sendpulse',
            'brand_id' => 'nullable|exists:brands,id',
            'is_default' => 'boolean',
            'daily_limit' => 'nullable|integer|min:0',
            'hourly_limit' => 'nullable|integer|min:0',
            // SMTP fields
            'host' => 'required_if:type,smtp|string|nullable',
            'port' => 'nullable|integer',
            'encryption' => 'nullable|in:tls,ssl,none',
            'username' => 'required_if:type,smtp|string|nullable',
            'password' => 'required_if:type,smtp|string|nullable',
            'from_address' => 'nullable|email',
            'from_name' => 'nullable|string|max:255',
            // SendPulse fields
            'api_user_id' => 'required_if:type,sendpulse|required_if:type,sms_sendpulse|string|nullable',
            'api_secret' => 'required_if:type,sendpulse|required_if:type,sms_sendpulse|string|nullable',
            'from_email' => 'nullable|email',
            // SMS SendPulse fields
            'sender_name' => 'nullable|string|max:11',
        ]);

        $config = match ($validated['type']) {
            'smtp' => [
                'host' => $validated['host'],
                'port' => $validated['port'] ?? 587,
                'encryption' => $validated['encryption'] ?? 'tls',
                'username' => $validated['username'],
                'password' => $validated['password'],
                'from_address' => $validated['from_address'] ?? $validated['username'],
                'from_name' => $validated['from_name'] ?? config('app.name'),
            ],
            'sendpulse' => [
                'api_user_id' => $validated['api_user_id'],
                'api_secret' => $validated['api_secret'],
                'from_email' => $validated['from_email'] ?? '',
                'from_name' => $validated['from_name'] ?? config('app.name'),
            ],
            'sms_sendpulse' => [
                'api_user_id' => $validated['api_user_id'],
                'api_secret' => $validated['api_secret'],
                'sender_name' => $validated['sender_name'] ?? config('app.name'),
            ],
        };

        $brandId = $validated['brand_id'] ?? session('current_brand_id');

        // Se is_default, desmarcar outros
        if ($request->boolean('is_default')) {
            EmailProvider::where('brand_id', $brandId)
                ->update(['is_default' => false]);
        }

        $provider = EmailProvider::create([
            'brand_id' => $brandId,
            'user_id' => Auth::id(),
            'type' => $validated['type'],
            'name' => $validated['name'],
            'config' => $config,
            'is_default' => $request->boolean('is_default', false),
            'daily_limit' => $validated['daily_limit'] ?? null,
            'hourly_limit' => $validated['hourly_limit'] ?? null,
        ]);

        return redirect()->route('email.providers.index')
            ->with('success', 'Provedor de email criado com sucesso!');
    }

    public function update(Request $request, EmailProvider $provider)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_default' => 'boolean',
            'daily_limit' => 'nullable|integer|min:0',
            'hourly_limit' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            // SMTP fields
            'host' => 'nullable|string',
            'port' => 'nullable|integer',
            'encryption' => 'nullable|in:tls,ssl,none',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
            'from_address' => 'nullable|email',
            'from_name' => 'nullable|string|max:255',
            // SendPulse fields
            'api_user_id' => 'nullable|string',
            'api_secret' => 'nullable|string',
            'from_email' => 'nullable|email',
            // SMS SendPulse
            'sender_name' => 'nullable|string|max:11',
        ]);

        // Atualizar config mesclando com existente
        $config = $provider->config;
        $configFields = match ($provider->type) {
            'smtp' => ['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'],
            'sms_sendpulse' => ['api_user_id', 'api_secret', 'sender_name'],
            default => ['api_user_id', 'api_secret', 'from_email', 'from_name'],
        };

        foreach ($configFields as $field) {
            if (isset($validated[$field]) && $validated[$field] !== '') {
                $config[$field] = $validated[$field];
            }
        }

        if ($request->boolean('is_default')) {
            EmailProvider::where('brand_id', $provider->brand_id)
                ->where('id', '!=', $provider->id)
                ->update(['is_default' => false]);
        }

        $provider->update([
            'name' => $validated['name'],
            'config' => $config,
            'is_default' => $request->boolean('is_default', false),
            'daily_limit' => $validated['daily_limit'] ?? null,
            'hourly_limit' => $validated['hourly_limit'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('email.providers.index')
            ->with('success', 'Provedor atualizado com sucesso!');
    }

    public function destroy(EmailProvider $provider)
    {
        if ($provider->campaigns()->where('status', 'sending')->exists()) {
            return back()->with('error', 'Não é possível excluir um provedor com campanhas em andamento.');
        }

        $provider->delete();
        return redirect()->route('email.providers.index')
            ->with('success', 'Provedor removido.');
    }

    public function test(Request $request, EmailProvider $provider, EmailProviderService $service)
    {
        if ($provider->type === 'sms_sendpulse') {
            $smsService = app(SmsProviderService::class);
            $result = $smsService->testConnection($provider);
            return response()->json($result);
        }

        $result = $service->testConnection($provider);
        return response()->json($result);
    }

    public function sendTest(Request $request, EmailProvider $provider, EmailProviderService $service)
    {
        $request->validate(['test_email' => 'required|email']);
        $result = $service->sendTest($provider, $request->input('test_email'));

        return response()->json($result);
    }

    private function getConfigSummary(EmailProvider $provider): array
    {
        $config = $provider->config;
        return match ($provider->type) {
            'smtp' => [
                'host' => $config['host'] ?? '-',
                'port' => $config['port'] ?? 587,
                'from' => $config['from_address'] ?? $config['username'] ?? '-',
            ],
            'sendpulse' => [
                'from' => $config['from_email'] ?? '-',
                'api_user_id' => substr($config['api_user_id'] ?? '', 0, 8) . '...',
            ],
            'sms_sendpulse' => [
                'sender_name' => $config['sender_name'] ?? '-',
                'api_user_id' => substr($config['api_user_id'] ?? '', 0, 8) . '...',
            ],
            default => [],
        };
    }

    /**
     * Retorna config completo para popular o formulário de edição.
     * Secrets são mascarados (placeholder) para não trafegar em claro na listagem.
     */
    private function getConfigForEdit(EmailProvider $provider): array
    {
        $config = $provider->config ?? [];
        return match ($provider->type) {
            'smtp' => [
                'host' => $config['host'] ?? '',
                'port' => $config['port'] ?? 587,
                'encryption' => $config['encryption'] ?? 'tls',
                'username' => $config['username'] ?? '',
                'password' => '', // Não enviar senha real — placeholder para indicar que existe
                'has_password' => !empty($config['password']),
                'from_address' => $config['from_address'] ?? '',
                'from_name' => $config['from_name'] ?? '',
            ],
            'sendpulse' => [
                'api_user_id' => $config['api_user_id'] ?? '',
                'api_secret' => '', // Não enviar secret real
                'has_secret' => !empty($config['api_secret']),
                'from_email' => $config['from_email'] ?? '',
                'from_name' => $config['from_name'] ?? '',
            ],
            'sms_sendpulse' => [
                'api_user_id' => $config['api_user_id'] ?? '',
                'api_secret' => '', // Não enviar secret real
                'has_secret' => !empty($config['api_secret']),
                'sender_name' => $config['sender_name'] ?? '',
            ],
            default => [],
        };
    }
}
