<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAiSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id', 'title', 'description',
        'suggested_subject', 'suggested_preview', 'target_audience',
        'content_type', 'reference_data', 'status', 'suggested_send_date',
    ];

    protected $casts = [
        'reference_data' => 'array',
        'suggested_send_date' => 'date',
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

    // ===== SCOPES =====

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->when($brandId, fn($q) => $q->where('brand_id', $brandId));
    }

    // ===== METHODS =====

    public function accept(): void
    {
        $this->update(['status' => 'accepted']);
    }

    public function reject(): void
    {
        $this->update(['status' => 'rejected']);
    }

    public function markUsed(): void
    {
        $this->update(['status' => 'used']);
    }
}
