<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id', 'name', 'body', 'category', 'is_active',
    ];

    protected $casts = [
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

    // ===== HELPERS =====

    /**
     * Calcula segmentos SMS para o body atual.
     */
    public function getSegmentsAttribute(): int
    {
        return self::calculateSegments($this->body ?? '');
    }

    /**
     * Detecta se o texto contém caracteres Unicode (fora do GSM-7).
     */
    public static function isUnicode(string $text): bool
    {
        // GSM-7 basic character set (simplificado)
        $gsm7 = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZ"
               . "ÄÖÑÜabcdefghijklmnopqrstuvwxyzäöñüà";

        for ($i = 0; $i < mb_strlen($text); $i++) {
            $char = mb_substr($text, $i, 1);
            if (str_contains($gsm7, $char) === false && !ctype_space($char)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Calcula número de segmentos SMS.
     * GSM-7: 160 chars (1 seg) ou 153 chars por seg (multi)
     * Unicode: 70 chars (1 seg) ou 67 chars por seg (multi)
     */
    public static function calculateSegments(string $text): int
    {
        $len = mb_strlen($text);
        if ($len === 0) return 0;

        if (self::isUnicode($text)) {
            return $len <= 70 ? 1 : (int) ceil($len / 67);
        }

        return $len <= 160 ? 1 : (int) ceil($len / 153);
    }
}
