<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Configurações do modo SaaS administráveis pela UI (Configurações →
 * Assinaturas), com fallback para config/.env quando não definidas no banco.
 *
 * Prioridade: Setting (banco, editável na UI) > config/billing.php (.env).
 */
class BillingSettings
{
    public static function enabled(): bool
    {
        return (bool) Setting::get('billing', 'enabled', config('billing.enabled'));
    }

    public static function trialDays(): int
    {
        return (int) Setting::get('billing', 'trial_days', config('billing.trial_days', 14));
    }

    public static function mpAccessToken(): ?string
    {
        return Setting::get('billing', 'mp_access_token')
            ?: config('billing.mercadopago.access_token');
    }

    public static function mpPublicKey(): ?string
    {
        return Setting::get('billing', 'mp_public_key')
            ?: config('billing.mercadopago.public_key');
    }

    public static function mpWebhookSecret(): ?string
    {
        return Setting::get('billing', 'mp_webhook_secret')
            ?: config('billing.mercadopago.webhook_secret');
    }

    public static function adminAlertEmail(): ?string
    {
        return Setting::get('billing', 'admin_alert_email')
            ?: config('mail.admin_alert_address');
    }

    public static function sendpulseWebhookToken(): ?string
    {
        return Setting::get('billing', 'sendpulse_webhook_token')
            ?: config('services.sendpulse.webhook_token');
    }
}
