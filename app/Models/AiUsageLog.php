<?php

namespace App\Models;

use App\Enums\AIModel;
use App\Enums\AIProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'brand_id',
        'provider',
        'model',
        'feature',
        'input_tokens',
        'output_tokens',
        'estimated_cost',
        'metadata',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'estimated_cost' => 'decimal:6',
        'metadata' => 'array',
    ];

    // ===== RELATIONSHIPS =====

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    // ===== METHODS =====

    public function getTotalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }
}
