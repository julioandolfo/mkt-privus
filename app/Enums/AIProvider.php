<?php

namespace App\Enums;

enum AIProvider: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::OpenAI => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Google => 'Google',
        };
    }

    public function envKey(): string
    {
        return match ($this) {
            self::OpenAI => 'OPENAI_API_KEY',
            self::Anthropic => 'ANTHROPIC_API_KEY',
            self::Google => 'GEMINI_API_KEY',
        };
    }
}
