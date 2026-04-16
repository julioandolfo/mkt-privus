<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meta (Facebook + Instagram) — integração direta
    |--------------------------------------------------------------------------
    | Obtenha em: https://developers.facebook.com/apps/
    | Permissões: pages_show_list, pages_read_engagement, pages_manage_posts,
    |             instagram_basic, instagram_content_publish, instagram_manage_insights
    */
    'meta' => [
        'app_id' => env('META_APP_ID', ''),
        'app_secret' => env('META_APP_SECRET', ''),
        'api_version' => 'v21.0',
        'scopes' => [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'pages_read_user_content',
            'instagram_basic',
            'instagram_content_publish',
            'instagram_manage_insights',
            'business_management',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | YouTube (Google) — integração direta
    |--------------------------------------------------------------------------
    | Obtenha em: https://console.cloud.google.com/apis/credentials
    | APIs: YouTube Data API v3
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'scopes' => [
            'https://www.googleapis.com/auth/youtube',
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/userinfo.profile',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | LinkedIn, LinkedIn Page, TikTok, Google My Business
    |--------------------------------------------------------------------------
    | Gerenciados via Postiz (services.postiz). Ver app/Services/Social/Postiz.
    */
];
