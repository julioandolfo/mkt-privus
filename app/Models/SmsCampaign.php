<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id', 'email_provider_id', 'sms_template_id',
        'name', 'body', 'sender_name', 'status', 'type',
        'scheduled_at', 'started_at', 'completed_at',
        'total_recipients', 'total_sent', 'total_delivered', 'total_failed', 'total_clicked',
        'estimated_cost', 'estimated_currency', 'sendpulse_campaign_id',
        'settings', 'tags',
    ];

    protected $casts = [
        'settings' => 'array',
        'tags' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_recipients' => 'integer',
        'total_sent' => 'integer',
        'total_delivered' => 'integer',
        'total_failed' => 'integer',
        'total_clicked' => 'integer',
        'estimated_cost' => 'decimal:4',
    ];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(EmailProvider::class, 'email_provider_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(EmailList::class, 'sms_campaign_lists')
            ->withPivot('type')
            ->withTimestamps();
    }

    public function includeLists(): BelongsToMany
    {
        return $this->lists()->wherePivot('type', 'include');
    }

    public function excludeLists(): BelongsToMany
    {
        return $this->lists()->wherePivot('type', 'exclude');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SmsCampaignEvent::class);
    }

    // ===== SCOPES =====

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->when($brandId, fn($q) => $q->where('brand_id', $brandId))
            ->orWhereNull('brand_id');
    }

    public function scopeReadyToSend($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now());
    }

    // ===== STATUS METHODS =====

    public function canEdit(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }

    public function canSend(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'])
            && $this->body
            && $this->sender_name
            && $this->total_recipients > 0;
    }

    public function canPause(): bool
    {
        return $this->status === 'sending';
    }

    public function canCancel(): bool
    {
        return in_array($this->status, ['scheduled', 'sending']);
    }

    public function isSending(): bool
    {
        return $this->status === 'sending';
    }

    // ===== RATE ACCESSORS =====

    public function getDeliveryRateAttribute(): float
    {
        if ($this->total_sent === 0) return 0;
        return round(($this->total_delivered / $this->total_sent) * 100, 1);
    }

    public function getFailureRateAttribute(): float
    {
        if ($this->total_sent === 0) return 0;
        return round(($this->total_failed / $this->total_sent) * 100, 1);
    }

    public function getClickRateAttribute(): float
    {
        if ($this->total_delivered === 0) return 0;
        return round(($this->total_clicked / $this->total_delivered) * 100, 1);
    }

    public function getSegmentsAttribute(): int
    {
        return SmsTemplate::calculateSegments($this->body ?? '');
    }

    // ===== STATS =====

    public function refreshStats(): void
    {
        $this->update([
            'total_sent' => $this->events()->where('event_type', 'sent')->count(),
            'total_delivered' => $this->events()->where('event_type', 'delivered')->count(),
            'total_failed' => $this->events()->where('event_type', 'failed')->count(),
            'total_clicked' => $this->events()->where('event_type', 'clicked')->count(),
        ]);
    }
}
