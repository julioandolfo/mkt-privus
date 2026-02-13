<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCampaignEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_campaign_id', 'email_contact_id',
        'event_type', 'metadata', 'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    // ===== RELATIONSHIPS =====

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(EmailContact::class, 'email_contact_id');
    }

    // ===== SCOPES =====

    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeSent($query) { return $query->where('event_type', 'sent'); }
    public function scopeDelivered($query) { return $query->where('event_type', 'delivered'); }
    public function scopeBounced($query) { return $query->where('event_type', 'bounced'); }
    public function scopeOpened($query) { return $query->where('event_type', 'opened'); }
    public function scopeClicked($query) { return $query->where('event_type', 'clicked'); }

    public function scopeForCampaign($query, int $campaignId)
    {
        return $query->where('email_campaign_id', $campaignId);
    }

    public function scopeInPeriod($query, string $start, string $end)
    {
        return $query->whereBetween('occurred_at', [$start, $end]);
    }
}
