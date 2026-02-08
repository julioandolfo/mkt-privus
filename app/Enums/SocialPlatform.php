<?php

namespace App\Enums;

enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case LinkedIn = 'linkedin';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Pinterest = 'pinterest';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::LinkedIn => 'LinkedIn',
            self::TikTok => 'TikTok',
            self::YouTube => 'YouTube',
            self::Pinterest => 'Pinterest',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Instagram => 'instagram',
            self::Facebook => 'facebook',
            self::LinkedIn => 'linkedin',
            self::TikTok => 'tiktok',
            self::YouTube => 'youtube',
            self::Pinterest => 'pinterest',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Instagram => '#E4405F',
            self::Facebook => '#1877F2',
            self::LinkedIn => '#0A66C2',
            self::TikTok => '#000000',
            self::YouTube => '#FF0000',
            self::Pinterest => '#BD081C',
        };
    }
}
