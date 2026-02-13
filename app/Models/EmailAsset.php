<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EmailAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id', 'file_path', 'file_name',
        'mime_type', 'file_size', 'dimensions', 'alt_text',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'file_size' => 'integer',
    ];

    protected $appends = ['url'];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ===== ACCESSORS =====

    public function getUrlAttribute(): ?string
    {
        if (!$this->file_path) return null;
        return Storage::disk('public')->url($this->file_path);
    }

    // ===== METHODS =====

    public function formattedFileSize(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
