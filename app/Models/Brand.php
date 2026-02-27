<?php

namespace App\Models;

use App\Enums\BrandRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'description',
        'website',
        'urls',
        'segment',
        'target_audience',
        'tone_of_voice',
        'primary_color',
        'secondary_color',
        'accent_color',
        'font_family',
        'keywords',
        'ai_context',
        'is_active',
    ];

    protected $casts = [
        'keywords' => 'array',
        'urls' => 'array',
        'is_active' => 'boolean',
    ];

    // ===== RELATIONSHIPS =====

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'brand_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(BrandAsset::class)->orderBy('sort_order');
    }

    public function logos(): HasMany
    {
        return $this->assets()->where('category', 'logo');
    }

    public function icons(): HasMany
    {
        return $this->assets()->where('category', 'icon');
    }

    public function watermarks(): HasMany
    {
        return $this->assets()->where('category', 'watermark');
    }

    public function references(): HasMany
    {
        return $this->assets()->where('category', 'reference');
    }

    public function mascots(): HasMany
    {
        return $this->assets()->where('category', 'mascot');
    }

    public function products(): HasMany
    {
        return $this->assets()->where('category', 'product');
    }

    public function contentRules(): HasMany
    {
        return $this->hasMany(ContentRule::class);
    }

    public function contentSuggestions(): HasMany
    {
        return $this->hasMany(ContentSuggestion::class);
    }

    public function calendarItems(): HasMany
    {
        return $this->hasMany(ContentCalendarItem::class);
    }

    // ===== METHODS =====

    /**
     * Retorna o logo principal da marca
     */
    public function primaryLogo(): ?BrandAsset
    {
        return $this->assets()
            ->where('category', 'logo')
            ->where('is_primary', true)
            ->first()
            ?? $this->assets()->where('category', 'logo')->first();
    }

    /**
     * Retorna o contexto da marca formatado para injecao no system prompt de IA
     */
    public function getAIContext(): string
    {
        $keywords = is_array($this->keywords) ? implode(', ', $this->keywords) : '';

        // Listar assets disponiveis
        $assetsContext = $this->getAssetsContextForAI();

        // Listar URLs/sites da marca
        $urlsContext = $this->getUrlsContextForAI();

        $context = <<<EOT
        CONTEXTO DA MARCA:
        - Nome: {$this->name}
        - Segmento: {$this->segment}
        - Público-alvo: {$this->target_audience}
        - Tom de voz: {$this->tone_of_voice}
        - Palavras-chave: {$keywords}
        - Cores: Primária {$this->primary_color}, Secundária {$this->secondary_color}, Acento {$this->accent_color}
        {$this->ai_context}
        EOT;

        if ($urlsContext) {
            $context .= "\n\n        URLS E SITES DA MARCA:\n{$urlsContext}";
        }

        if ($assetsContext) {
            $context .= "\n\n        ASSETS VISUAIS DISPONÍVEIS:\n{$assetsContext}";
        }

        $context .= <<<EOT


        REGRAS:
        - Sempre use o tom de voz definido acima.
        - Mantenha a consistência visual com as cores da marca.
        - Considere o público-alvo ao criar conteúdo.
        - Use as palavras-chave naturalmente no conteúdo.
        - Quando relevante, utilize as URLs da marca para direcionar o público (links de produtos, site, etc).
        - Se a marca possuir mascote, incorpore-o nas sugestões de conteúdo quando fizer sentido (storytelling, interação, humor).
        - Se a marca possuir fotos de produtos cadastrados, sugira conteúdos que destaquem esses produtos (fotos, reviews, promoções, unboxing, comparativos).
        EOT;

        return $context;
    }

    /**
     * Monta lista de assets para contexto de IA
     */
    private function getAssetsContextForAI(): string
    {
        $assets = $this->assets()->get();

        if ($assets->isEmpty()) {
            return '';
        }

        $lines = [];
        $grouped = $assets->groupBy('category');

        $categoryLabels = [
            'logo' => 'Logotipos',
            'icon' => 'Ícones',
            'watermark' => 'Marcas d\'água',
            'reference' => 'Referências visuais',
            'mascot' => 'Mascotes',
            'product' => 'Produtos',
        ];

        foreach ($grouped as $category => $items) {
            $label = $categoryLabels[$category] ?? $category;
            $names = $items->pluck('label')->filter()->implode(', ');
            $primary = $items->firstWhere('is_primary', true);
            $primaryNote = $primary ? " (principal: {$primary->label})" : '';
            $lines[] = "        - {$label}: {$items->count()} arquivo(s){$primaryNote}" . ($names ? " — {$names}" : '');
        }

        return implode("\n", $lines);
    }

    /**
     * Monta lista de URLs para contexto de IA
     */
    private function getUrlsContextForAI(): string
    {
        $urls = is_array($this->urls) ? $this->urls : [];

        // Incluir o website legado se existir
        if ($this->website && !collect($urls)->contains('url', $this->website)) {
            array_unshift($urls, [
                'label' => 'Site Principal',
                'url' => $this->website,
                'type' => 'website',
            ]);
        }

        if (empty($urls)) {
            return '';
        }

        $typeLabels = [
            'website' => 'Site',
            'ecommerce' => 'E-commerce',
            'landing_page' => 'Landing Page',
            'blog' => 'Blog',
            'catalog' => 'Catálogo',
            'linktree' => 'Link Tree',
            'other' => 'Outro',
        ];

        $lines = [];
        foreach ($urls as $entry) {
            $label = $entry['label'] ?? '';
            $url = $entry['url'] ?? '';
            $type = $typeLabels[$entry['type'] ?? 'other'] ?? 'Outro';
            $lines[] = "        - [{$type}] {$label}: {$url}";
        }

        return implode("\n", $lines);
    }

    /**
     * Retorna todas as URLs da marca (campo urls + website legado)
     */
    public function getAllUrls(): array
    {
        $urls = is_array($this->urls) ? $this->urls : [];

        if ($this->website && !collect($urls)->contains('url', $this->website)) {
            array_unshift($urls, [
                'label' => 'Site Principal',
                'url' => $this->website,
                'type' => 'website',
            ]);
        }

        return $urls;
    }

    /**
     * Retorna URLs filtradas por tipo
     */
    public function getUrlsByType(string $type): array
    {
        return collect($this->getAllUrls())
            ->filter(fn($u) => ($u['type'] ?? '') === $type)
            ->values()
            ->toArray();
    }

    /**
     * Verifica se um usuario tem um determinado papel nesta marca
     */
    public function userHasRole(User $user, BrandRole $role): bool
    {
        return $this->users()
            ->wherePivot('user_id', $user->id)
            ->wherePivot('role', $role->value)
            ->exists();
    }

    /**
     * Verifica se um usuario pode editar conteudo nesta marca
     */
    public function userCanEdit(User $user): bool
    {
        $pivot = $this->users()->wherePivot('user_id', $user->id)->first()?->pivot;

        if (!$pivot) {
            return false;
        }

        return BrandRole::from($pivot->role)->canEdit();
    }
}
