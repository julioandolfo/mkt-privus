<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Meta (Facebook + Instagram)
    |--------------------------------------------------------------------------
    | Obtenha em: https://developers.facebook.com/apps/
    | Permissões: pages_show_list, pages_read_engagement, pages_manage_posts,
    |             instagram_basic, instagram_content_publish, instagram_manage_insights
    */
    'meta' => [
        'app_id' => env('META_APP_ID', ''),
        'app_secret' => env('META_APP_SECRET', ''),
        'api_version' => 'v19.0',
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
    | LinkedIn
    |--------------------------------------------------------------------------
    | Obtenha em: https://www.linkedin.com/developers/apps/
    | Permissões: r_liteprofile, r_organization_social, w_organization_social
    */
    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID', ''),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET', ''),
        'scopes' => [
            'openid',
            'profile',
            'w_member_social',
            'r_organization_social',
            'w_organization_social',
            'r_basicprofile',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | YouTube (Google)
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
    | TikTok
    |--------------------------------------------------------------------------
    | Obtenha em: https://developers.tiktok.com/
    | Permissões: user.info.basic, video.publish, video.list
    */
    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY', ''),
        'client_secret' => env('TIKTOK_CLIENT_SECRET', ''),
        'scopes' => [
            'user.info.basic',
            'video.publish',
            'video.list',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pinterest
    |--------------------------------------------------------------------------
    | Obtenha em: https://developers.pinterest.com/
    */
    'pinterest' => [
        'app_id' => env('PINTEREST_APP_ID', ''),
        'app_secret' => env('PINTEREST_APP_SECRET', ''),
        'scopes' => [
            'boards:read',
            'boards:write',
            'pins:read',
            'pins:write',
            'user_accounts:read',
        ],
    ],
];
