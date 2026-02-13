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
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'daily_limit' => 'integer',
        'sends_today' => 'integer',
        'last_reset_at' => 'datetime',
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
        return $this->config['from_address'] ?? $this->config['from_email'] ?? config('mail.from.address');
    }

    public function getFromName(): string
    {
        return $this->config['from_name'] ?? config('mail.from.name');
    }

    public function hasQuotaRemaining(): bool
    {
        if (!$this->daily_limit) return true;
        $this->resetDailyCounterIfNeeded();
        return $this->sends_today < $this->daily_limit;
    }

    public function getRemainingQuota(): ?int
    {
        if (!$this->daily_limit) return null;
        $this->resetDailyCounterIfNeeded();
        return max(0, $this->daily_limit - $this->sends_today);
    }

    public function incrementSendCount(int $count = 1): void
    {
        $this->resetDailyCounterIfNeeded();
        $this->increment('sends_today', $count);
    }

    private function resetDailyCounterIfNeeded(): void
    {
        if (!$this->last_reset_at || !$this->last_reset_at->isToday()) {
            $this->update(['sends_today' => 0, 'last_reset_at' => now()]);
        }
    }

    public function getConfigValue(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
}
