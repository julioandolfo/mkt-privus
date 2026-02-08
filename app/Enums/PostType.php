<?php

namespace App\Enums;

enum PostType: string
{
    case Feed = 'feed';
    case Carousel = 'carousel';
    case Story = 'story';
    case Reel = 'reel';
    case Pin = 'pin';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Feed => 'Post Feed',
            self::Carousel => 'Carrossel',
            self::Story => 'Story',
            self::Reel => 'Reel / TikTok',
            self::Pin => 'Pin',
            self::Video => 'Vídeo',
        };
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::Feed => ['width' => 1080, 'height' => 1080],
            self::Carousel => ['width' => 1080, 'height' => 1350],
            self::Story, self::Reel => ['width' => 1080, 'height' => 1920],
            self::Pin => ['width' => 1000, 'height' => 1500],
            self::Video => ['width' => 1920, 'height' => 1080],
        };
    }
}
