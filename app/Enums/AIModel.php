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

    public function isAvailable(): bool
    {
        $envKey = $this->provider()->envKey();
        $key = config('services.' . $this->serviceConfigKey()) ?: env($envKey);
        return !empty($key);
    }

    private function serviceConfigKey(): string
    {
        return match ($this->provider()) {
            AIProvider::OpenAI => 'openai.api_key',
            AIProvider::Anthropic => 'anthropic.api_key',
            AIProvider::Google => 'gemini.api_key',
        };
    }

    /**
     * Retorna apenas modelos que possuem API key configurada,
     * priorizando OpenAI no topo da lista.
     */
    public static function availableCases(): array
    {
        $available = collect(self::cases())
            ->filter(fn(self $m) => $m->isAvailable());

        // OpenAI primeiro, depois demais providers em ordem
        return $available->sortBy(function (self $m) {
            return match ($m->provider()) {
                AIProvider::OpenAI => 0,
                AIProvider::Anthropic => 1,
                AIProvider::Google => 2,
            };
        })->values()->toArray();
    }

    /**
     * Retorna o melhor modelo padrão (OpenAI preferido).
     */
    public static function defaultModel(): self
    {
        $available = self::availableCases();
        return $available[0] ?? self::GPT4oMini;
    }
}
