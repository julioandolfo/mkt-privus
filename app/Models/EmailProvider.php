<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id', 'type', 'name', 'config',
        'is_active', 'is_default', 'daily_limit', 'sends_today', 'last_reset_at',
        'hourly_limit', 'sends_this_hour', 'last_hour_reset_at',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'daily_limit' => 'integer',
        'sends_today' => 'integer',
        'last_reset_at' => 'datetime',
        'hourly_limit' => 'integer',
        'sends_this_hour' => 'integer',
        'last_hour_reset_at' => 'datetime',
    ];

    protected $hidden = ['config'];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }

    // ===== SCOPES =====

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->when($brandId, fn($q) => $q->where('brand_id', $brandId));
    }

    // ===== METHODS =====

    public function getFromAddress(): string
    {
        return $this->config['from_email'] ?? $this->config['from_address'] ?? config('mail.from.address');
    }

    /**
     * Alias para getFromAddress() — mantém compatibilidade
     */
    public function getFromEmail(): string
    {
        return $this->getFromAddress();
    }

    public function getFromName(): string
    {
        return $this->config['from_name'] ?? $this->config['sender_name'] ?? config('mail.from.name');
    }

    public function hasQuotaRemaining(): bool
    {
        $this->resetCountersIfNeeded();

        // Verifica limite diário
        if ($this->daily_limit && $this->sends_today >= $this->daily_limit) {
            return false;
        }

        // Verifica limite por hora
        if ($this->hourly_limit && $this->sends_this_hour >= $this->hourly_limit) {
            return false;
        }

        return true;
    }

    public function getRemainingQuota(): ?int
    {
        $this->resetCountersIfNeeded();

        $dailyRemaining = $this->daily_limit ? max(0, $this->daily_limit - $this->sends_today) : null;
        $hourlyRemaining = $this->hourly_limit ? max(0, $this->hourly_limit - $this->sends_this_hour) : null;

        // Retorna o menor dos limites (ou null se ambos são ilimitados)
        if ($dailyRemaining === null) return $hourlyRemaining;
        if ($hourlyRemaining === null) return $dailyRemaining;

        return min($dailyRemaining, $hourlyRemaining);
    }

    public function getRemainingHourlyQuota(): ?int
    {
        $this->resetCountersIfNeeded();
        return $this->hourly_limit ? max(0, $this->hourly_limit - $this->sends_this_hour) : null;
    }

    public function getRemainingDailyQuota(): ?int
    {
        $this->resetCountersIfNeeded();
        return $this->daily_limit ? max(0, $this->daily_limit - $this->sends_today) : null;
    }

    public function incrementSendCount(int $count = 1): void
    {
        $this->resetCountersIfNeeded();
        $this->increment('sends_today', $count);
        $this->increment('sends_this_hour', $count);
    }

    private function resetCountersIfNeeded(): void
    {
        $now = now();

        // Reseta contador diário se necessário
        if (!$this->last_reset_at || !$this->last_reset_at->isToday()) {
            $this->sends_today = 0;
            $this->last_reset_at = $now;
        }

        // Reseta contador por hora se necessário
        if (!$this->last_hour_reset_at || $this->last_hour_reset_at->diffInHours($now) >= 1) {
            $this->sends_this_hour = 0;
            $this->last_hour_reset_at = $now;
        }

        // Salva se houve alteração
        if ($this->isDirty(['sends_today', 'sends_this_hour', 'last_reset_at', 'last_hour_reset_at'])) {
            $this->save();
        }
    }

    /**
     * Retorna informações de quota para exibição
     */
    public function getQuotaInfo(): array
    {
        $this->resetCountersIfNeeded();

        return [
            'daily_limit' => $this->daily_limit,
            'sends_today' => $this->sends_today,
            'daily_remaining' => $this->getRemainingDailyQuota(),
            'hourly_limit' => $this->hourly_limit,
            'sends_this_hour' => $this->sends_this_hour,
            'hourly_remaining' => $this->getRemainingHourlyQuota(),
            'total_remaining' => $this->getRemainingQuota(),
        ];
    }

    public function getConfigValue(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
}
