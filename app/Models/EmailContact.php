<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'email', 'first_name', 'last_name', 'phone', 'company',
        'metadata', 'status', 'subscribed_at', 'unsubscribed_at',
        'source', 'source_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(EmailList::class, 'email_list_contact')
            ->withPivot('added_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailCampaignEvent::class);
    }

    // ===== SCOPES =====

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSubscribed($query)
    {
        return $query->whereIn('status', ['active']);
    }

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->when($brandId, fn($q) => $q->where('brand_id', $brandId));
    }

    // ===== ACCESSORS =====

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: $this->email;
    }

    // ===== METHODS =====

    public function unsubscribe(): void
    {
        $this->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);
    }

    public function markBounced(): void
    {
        $this->update(['status' => 'bounced']);
    }

    public function markComplained(): void
    {
        $this->update(['status' => 'complained']);
    }

    public function canReceiveEmails(): bool
    {
        return $this->status === 'active';
    }

    public function getMetaValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }
}
