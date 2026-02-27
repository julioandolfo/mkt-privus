<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class BrandAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'category',
        'label',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'dimensions',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'is_primary' => 'boolean',
        'file_size' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $appends = ['url'];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    // ===== SCOPES =====

    public function scopeLogos($query)
    {
        return $query->where('category', 'logo');
    }

    public function scopeIcons($query)
    {
        return $query->where('category', 'icon');
    }

    public function scopeWatermarks($query)
    {
        return $query->where('category', 'watermark');
    }

    public function scopeReferences($query)
    {
        return $query->where('category', 'reference');
    }

    public function scopeMascots($query)
    {
        return $query->where('category', 'mascot');
    }

    public function scopeProducts($query)
    {
        return $query->where('category', 'product');
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    // ===== ACCESSORS =====

    public function getUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::disk('public')->url($this->file_path);
    }

    // ===== METHODS =====

    public function formattedFileSize(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
