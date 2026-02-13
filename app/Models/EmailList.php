<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailList extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'brand_id', 'user_id', 'name', 'description', 'type',
        'contacts_count', 'tags', 'is_active',
    ];

    protected $casts = [
        'tags' => 'array',
        'contacts_count' => 'integer',
        'is_active' => 'boolean',
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

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(EmailContact::class, 'email_list_contact')
            ->withPivot('added_at');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(EmailListSource::class);
    }

    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(EmailCampaign::class, 'email_campaign_lists')
            ->withPivot('type');
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

    public function refreshContactsCount(): void
    {
        $this->update(['contacts_count' => $this->contacts()->count()]);
    }

    public function getActiveContactsCount(): int
    {
        return $this->contacts()->where('email_contacts.status', 'active')->count();
    }
}
