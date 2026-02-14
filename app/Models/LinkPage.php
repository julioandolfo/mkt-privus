<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LinkPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id', 'user_id',
        'title', 'slug', 'description', 'avatar_path',
        'theme', 'blocks',
        'seo_title', 'seo_description', 'seo_image',
        'custom_css',
        'is_active', 'total_views', 'total_clicks',
    ];

    protected $casts = [
        'theme' => 'array',
        'blocks' => 'array',
        'is_active' => 'boolean',
        'total_views' => 'integer',
        'total_clicks' => 'integer',
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

    public function clicks(): HasMany
    {
        return $this->hasMany(LinkClick::class);
    }

    // ===== SCOPES =====

    public function scopeForBrand($query, ?int $brandId)
    {
        return $query->when($brandId, fn($q) => $q->where('brand_id', $brandId));
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ===== ACCESSORS =====

    public function getPublicUrlAttribute(): string
    {
        return url("/l/{$this->slug}");
    }

    public function getBlockCountAttribute(): int
    {
        return count($this->blocks ?? []);
    }

    public function getActiveBlocksAttribute(): array
    {
        return collect($this->blocks ?? [])
            ->filter(fn($b) => ($b['visible'] ?? true))
            ->sortBy('sort_order')
            ->values()
            ->toArray();
    }

    public function getThemeDefaults(): array
    {
        return array_merge([
            'bg_color' => '#0f172a',
            'bg_gradient' => null,
            'bg_image' => null,
            'text_color' => '#ffffff',
            'button_color' => '#4f46e5',
            'button_text_color' => '#ffffff',
            'button_style' => 'rounded', // rounded, pill, square, outline
            'font_family' => 'Inter',
            'layout' => 'center', // center, left
        ], $this->theme ?? []);
    }

    // ===== METHODS =====

    public function incrementViews(): void
    {
        $this->increment('total_views');
    }

    public function recordClick(int $blockIndex, ?string $url, ?string $ip, ?string $userAgent, ?string $referer): LinkClick
    {
        $block = ($this->blocks ?? [])[$blockIndex] ?? null;

        $click = $this->clicks()->create([
            'block_index' => $blockIndex,
            'block_type' => $block['type'] ?? null,
            'block_label' => $block['label'] ?? null,
            'url' => $url,
            'ip_hash' => $ip ? hash('sha256', $ip . config('app.key')) : null,
            'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
            'referer' => $referer ? substr($referer, 0, 500) : null,
            'device' => self::detectDevice($userAgent),
            'clicked_at' => now(),
        ]);

        $this->increment('total_clicks');

        return $click;
    }

    /**
     * Gera slug único
     */
    public static function generateUniqueSlug(string $title, ?int $excludeId = null): string
    {
        $slug = Str::slug($title);
        // Garantir slug curto e amigável
        $slug = substr($slug, 0, 30);
        $original = $slug;
        $counter = 1;

        while (static::where('slug', $slug)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists()
        ) {
            $slug = substr($original, 0, 26) . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Blocos padrão para nova página
     */
    public static function defaultBlocks(): array
    {
        return [
            [
                'type' => 'header',
                'label' => 'Título',
                'config' => [
                    'title' => 'Minha Página',
                    'subtitle' => '@usuario',
                    'show_avatar' => true,
                ],
                'visible' => true,
                'sort_order' => 0,
            ],
            [
                'type' => 'link',
                'label' => 'Meu site',
                'config' => [
                    'url' => 'https://',
                    'icon' => 'globe',
                    'highlight' => false,
                ],
                'visible' => true,
                'sort_order' => 1,
            ],
        ];
    }

    private static function detectDevice(?string $userAgent): ?string
    {
        if (!$userAgent) return null;
        $ua = strtolower($userAgent);
        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) return 'mobile';
        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) return 'tablet';
        return 'desktop';
    }
}
