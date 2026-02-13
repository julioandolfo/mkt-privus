<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id', 'name', 'description', 'subject',
        'html_content', 'mjml_content', 'json_content', 'thumbnail_path',
        'category', 'is_active',
    ];

    protected $casts = [
        'json_content' => 'array',
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

    // ===== SCOPES =====

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->when($brandId, fn($q) => $q->where('brand_id', $brandId))
            ->orWhereNull('brand_id');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
