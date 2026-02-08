<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'width',
        'height',
        'order',
        'alt_text',
        'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'order' => 'integer',
        'metadata' => 'array',
    ];

    // ===== RELATIONSHIPS =====

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
