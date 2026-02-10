<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OAuthDiscoveredAccount extends Model
{
    protected $fillable = [
        'session_token',
        'user_id',
        'brand_id',
        'platform',
        'accounts',
        'token_data',
        'expires_at',
    ];

    protected $casts = [
        'accounts' => 'array',
        'token_data' => 'array',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    // Limpar registros expirados
    public static function cleanup(): int
    {
        return static::where('expires_at', '<', now())->delete();
    }
}
