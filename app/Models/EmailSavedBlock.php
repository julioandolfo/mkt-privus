<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSavedBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id', 'name', 'category',
        'mjml_content', 'html_content', 'json_content',
        'thumbnail_path', 'is_global',
    ];

    protected $casts = [
        'json_content' => 'array',
        'is_global' => 'boolean',
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

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->where(function ($q) use ($brandId) {
            $q->where('is_global', true);
            if ($brandId) {
                $q->orWhere('brand_id', $brandId);
            }
        });
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeHeaders($query)
    {
        return $query->where('category', 'header');
    }

    public function scopeFooters($query)
    {
        return $query->where('category', 'footer');
    }
}
