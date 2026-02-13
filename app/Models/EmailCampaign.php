<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id', 'email_provider_id', 'email_template_id',
        'name', 'subject', 'preview_text', 'from_name', 'from_email', 'reply_to',
        'html_content', 'mjml_content', 'json_content',
        'status', 'type', 'scheduled_at', 'started_at', 'completed_at',
        'tags', 'total_recipients', 'total_sent', 'total_delivered', 'total_bounced',
        'total_opened', 'total_clicked', 'total_unsubscribed', 'total_complained',
        'unique_opens', 'unique_clicks', 'settings',
    ];

    protected $casts = [
        'json_content' => 'array',
        'tags' => 'array',
        'settings' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_recipients' => 'integer',
        'total_sent' => 'integer',
        'total_delivered' => 'integer',
        'total_bounced' => 'integer',
        'total_opened' => 'integer',
        'total_clicked' => 'integer',
        'total_unsubscribed' => 'integer',
        'total_complained' => 'integer',
        'unique_opens' => 'integer',
        'unique_clicks' => 'integer',
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
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(EmailList::class, 'email_campaign_lists')
            ->withPivot('type');
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
        return $this->hasMany(EmailCampaignEvent::class);
    }

    // ===== SCOPES =====

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->when($brandId, fn($q) => $q->where('brand_id', $brandId));
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeReadyToSend($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now());
    }

    // ===== ACCESSORS =====

    public function getOpenRateAttribute(): float
    {
        if ($this->total_delivered <= 0) return 0;
        return round(($this->unique_opens / $this->total_delivered) * 100, 2);
    }

    public function getClickRateAttribute(): float
    {
        if ($this->total_delivered <= 0) return 0;
        return round(($this->unique_clicks / $this->total_delivered) * 100, 2);
    }

    public function getBounceRateAttribute(): float
    {
        if ($this->total_sent <= 0) return 0;
        return round(($this->total_bounced / $this->total_sent) * 100, 2);
    }

    public function getDeliveryRateAttribute(): float
    {
        if ($this->total_sent <= 0) return 0;
        return round(($this->total_delivered / $this->total_sent) * 100, 2);
    }

    public function getUnsubscribeRateAttribute(): float
    {
        if ($this->total_delivered <= 0) return 0;
        return round(($this->total_unsubscribed / $this->total_delivered) * 100, 2);
    }

    // ===== METHODS =====

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isSending(): bool { return $this->status === 'sending'; }
    public function isSent(): bool { return $this->status === 'sent'; }
    public function isScheduled(): bool { return $this->status === 'scheduled'; }
    public function isPaused(): bool { return $this->status === 'paused'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }

    public function canEdit(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }

    public function canSend(): bool
    {
        return $this->status === 'draft' && $this->html_content && $this->subject;
    }

    public function canPause(): bool
    {
        return $this->status === 'sending';
    }

    public function canCancel(): bool
    {
        return in_array($this->status, ['sending', 'scheduled', 'paused']);
    }

    public function getSetting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    public function refreshStats(): void
    {
        $this->update([
            'total_sent' => $this->events()->where('event_type', 'sent')->count(),
            'total_delivered' => $this->events()->where('event_type', 'delivered')->count(),
            'total_bounced' => $this->events()->where('event_type', 'bounced')->count(),
            'total_opened' => $this->events()->where('event_type', 'opened')->count(),
            'total_clicked' => $this->events()->where('event_type', 'clicked')->count(),
            'total_unsubscribed' => $this->events()->where('event_type', 'unsubscribed')->count(),
            'total_complained' => $this->events()->where('event_type', 'complained')->count(),
            'unique_opens' => $this->events()->where('event_type', 'opened')->distinct('email_contact_id')->count('email_contact_id'),
            'unique_clicks' => $this->events()->where('event_type', 'clicked')->distinct('email_contact_id')->count('email_contact_id'),
        ]);
    }
}
