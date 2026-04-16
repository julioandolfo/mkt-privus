<?php

namespace App\Enums;

enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case YouTube = 'youtube';
    case LinkedIn = 'linkedin';
    case LinkedInPage = 'linkedin_page';
    case TikTok = 'tiktok';
    case GoogleMyBusiness = 'google_my_business';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::YouTube => 'YouTube',
            self::LinkedIn => 'LinkedIn',
            self::LinkedInPage => 'LinkedIn Page',
            self::TikTok => 'TikTok',
            self::GoogleMyBusiness => 'Google My Business',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Instagram => 'instagram',
            self::Facebook => 'facebook',
            self::YouTube => 'youtube',
            self::LinkedIn, self::LinkedInPage => 'linkedin',
            self::TikTok => 'tiktok',
            self::GoogleMyBusiness => 'google',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Instagram => '#E4405F',
            self::Facebook => '#1877F2',
            self::YouTube => '#FF0000',
            self::LinkedIn, self::LinkedInPage => '#0A66C2',
            self::TikTok => '#000000',
            self::GoogleMyBusiness => '#4285F4',
        };
    }

    /**
     * Identificador da plataforma no Postiz (__type do settings e slug do OAuth).
     * Retorna null para plataformas com integração direta (não vão pelo Postiz).
     */
    public function postizIdentifier(): ?string
    {
        return match ($this) {
            self::LinkedIn => 'linkedin',
            self::LinkedInPage => 'linkedin-page',
            self::TikTok => 'tiktok',
            self::GoogleMyBusiness => 'googlebusiness',
            default => null,
        };
    }

    /**
     * Indica se a plataforma publica via Postiz em vez de integração direta.
     */
    public function usesPostiz(): bool
    {
        return $this->postizIdentifier() !== null;
    }
}
