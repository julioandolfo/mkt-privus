<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsCampaignEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'sms_campaign_id', 'email_contact_id', 'phone',
        'event_type', 'metadata', 'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    // ===== RELATIONSHIPS =====

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(SmsCampaign::class, 'sms_campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(EmailContact::class, 'email_contact_id');
    }

    // ===== SCOPES =====

    public function scopeSent($query)
    {
        return $query->where('event_type', 'sent');
    }

    public function scopeDelivered($query)
    {
        return $query->where('event_type', 'delivered');
    }

    public function scopeFailed($query)
    {
        return $query->where('event_type', 'failed');
    }

    public function scopeClicked($query)
    {
        return $query->where('event_type', 'clicked');
    }

    public function scopeOptout($query)
    {
        return $query->where('event_type', 'optout');
    }
}
