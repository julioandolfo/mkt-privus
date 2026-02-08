<?php

namespace App\Enums;

enum AIModel: string
{
    case GPT4o = 'gpt-4o';
    case GPT4oMini = 'gpt-4o-mini';
    case Claude35Sonnet = 'claude-3-5-sonnet-20241022';
    case Claude35Haiku = 'claude-3-5-haiku-20241022';
    case GeminiFlash = 'gemini-2.0-flash';
    case GeminiPro = 'gemini-2.0-pro';

    public function label(): string
    {
        return match ($this) {
            self::GPT4o => 'GPT-4o',
            self::GPT4oMini => 'GPT-4o Mini',
            self::Claude35Sonnet => 'Claude 3.5 Sonnet',
            self::Claude35Haiku => 'Claude 3.5 Haiku',
            self::GeminiFlash => 'Gemini 2.0 Flash',
            self::GeminiPro => 'Gemini 2.0 Pro',
        };
    }

    public function provider(): AIProvider
    {
        return match ($this) {
            self::GPT4o, self::GPT4oMini => AIProvider::OpenAI,
            self::Claude35Sonnet, self::Claude35Haiku => AIProvider::Anthropic,
            self::GeminiFlash, self::GeminiPro => AIProvider::Google,
        };
    }

    public function maxTokens(): int
    {
        return match ($this) {
            self::GPT4o => 128000,
            self::GPT4oMini => 128000,
            self::Claude35Sonnet => 200000,
            self::Claude35Haiku => 200000,
            self::GeminiFlash => 1000000,
            self::GeminiPro => 2000000,
        };
    }
}
