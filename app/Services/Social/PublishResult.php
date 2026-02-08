<?php

namespace App\Services\Social;

/**
 * DTO para resultado de publicacao em rede social.
 */
class PublishResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $platformPostId = null,
        public readonly ?string $platformPostUrl = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function ok(string $platformPostId, ?string $platformPostUrl = null): self
    {
        return new self(
            success: true,
            platformPostId: $platformPostId,
            platformPostUrl: $platformPostUrl,
        );
    }

    public static function fail(string $errorMessage): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
        );
    }
}
