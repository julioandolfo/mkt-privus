<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemLog extends Model
{
    protected $fillable = [
        'channel',
        'level',
        'action',
        'message',
        'context',
        'user_id',
        'brand_id',
        'ip',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ===== HELPER PARA LOGGING RAPIDO =====

    public static function log(string $channel, string $level, string $action, string $message, array $context = [], ?int $userId = null, ?int $brandId = null, ?string $ip = null): self
    {
        return static::create([
            'channel' => $channel,
            'level' => $level,
            'action' => $action,
            'message' => $message,
            'context' => !empty($context) ? $context : null,
            'user_id' => $userId ?? auth()->id(),
            'brand_id' => $brandId,
            'ip' => $ip ?? request()->ip(),
        ]);
    }

    public static function info(string $channel, string $action, string $message, array $context = []): self
    {
        return static::log($channel, 'info', $action, $message, $context);
    }

    public static function error(string $channel, string $action, string $message, array $context = []): self
    {
        return static::log($channel, 'error', $action, $message, $context);
    }

    public static function warning(string $channel, string $action, string $message, array $context = []): self
    {
        return static::log($channel, 'warning', $action, $message, $context);
    }

    public static function debug(string $channel, string $action, string $message, array $context = []): self
    {
        return static::log($channel, 'debug', $action, $message, $context);
    }

    // Limpar logs antigos (mais de 30 dias)
    public static function cleanup(int $days = 30): int
    {
        return static::where('created_at', '<', now()->subDays($days))->delete();
    }
}
