<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing / Assinaturas (SaaS)
    |--------------------------------------------------------------------------
    |
    | Quando desabilitado (padrão), nenhum limite de plano é aplicado e o
    | middleware de assinatura não bloqueia nada — comportamento atual de
    | instalação single-tenant é preservado.
    |
    */

    'enabled' => env('BILLING_ENABLED', false),

    // Dias de teste grátis concedidos no cadastro
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        // Secret de assinatura de webhooks (painel Mercado Pago > Webhooks)
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
    ],

];
