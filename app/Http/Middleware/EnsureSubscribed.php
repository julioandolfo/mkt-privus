<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige assinatura válida (ativa ou trial) para acessar o aplicativo.
 * No-op quando o billing está desabilitado (config/billing.php).
 */
class EnsureSubscribed
{
    /**
     * Rotas acessíveis sem assinatura (gerenciar conta e assinar um plano)
     */
    private const ALLOWED_ROUTES = [
        'billing.*',
        'profile.*',
        'logout',
        'verification.*',
        'brands.switch',
        'invitations.accept',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (!\App\Support\BillingSettings::enabled()) {
            return $next($request);
        }

        $user = $request->user();

        if (!$user || $user->hasValidSubscription()) {
            return $next($request);
        }

        foreach (self::ALLOWED_ROUTES as $pattern) {
            if ($request->routeIs($pattern)) {
                return $next($request);
            }
        }

        return redirect()
            ->route('billing.index')
            ->with('error', 'Sua assinatura expirou ou ainda não está ativa. Escolha um plano para continuar.');
    }
}
