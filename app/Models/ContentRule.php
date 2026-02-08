<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'name',
        'description',
        'category',
        'platforms',
        'post_type',
        'tone_override',
        'instructions',
        'frequency',
        'preferred_times',
        'is_active',
        'last_generated_at',
        'next_generation_at',
    ];

    protected $casts = [
        'platforms' => 'array',
        'preferred_times' => 'array',
        'is_active' => 'boolean',
        'last_generated_at' => 'datetime',
        'next_generation_at' => 'datetime',
    ];

    // ===== RELATIONSHIPS =====

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(ContentSuggestion::class);
    }

    // ===== SCOPES =====

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDueForGeneration($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('next_generation_at')
                    ->orWhere('next_generation_at', '<=', now());
            });
    }

    // ===== METHODS =====

    /**
     * Calcula a proxima data de geracao baseado na frequencia
     */
    public function calculateNextGeneration(): Carbon
    {
        $now = now();

        return match ($this->frequency) {
            'daily' => $now->addDay(),
            'weekday' => $now->isWeekday() ? $now->addWeekday() : $now->next(Carbon::MONDAY),
            'weekly' => $now->addWeek(),
            'biweekly' => $now->addWeeks(2),
            'monthly' => $now->addMonth(),
            default => $now->addWeek(),
        };
    }

    /**
     * Marca como gerado e calcula proxima geracao
     */
    public function markAsGenerated(): void
    {
        $this->update([
            'last_generated_at' => now(),
            'next_generation_at' => $this->calculateNextGeneration(),
        ]);
    }

    /**
     * Retorna label da frequencia
     */
    public function frequencyLabel(): string
    {
        return match ($this->frequency) {
            'daily' => 'Diário',
            'weekday' => 'Dias úteis',
            'weekly' => 'Semanal',
            'biweekly' => 'Quinzenal',
            'monthly' => 'Mensal',
            default => $this->frequency,
        };
    }

    /**
     * Retorna label da categoria
     */
    public function categoryLabel(): string
    {
        return match ($this->category) {
            'dica' => 'Dica',
            'novidade' => 'Novidade',
            'bastidores' => 'Bastidores',
            'promocao' => 'Promoção',
            'educativo' => 'Educativo',
            'inspiracional' => 'Inspiracional',
            'engajamento' => 'Engajamento',
            'produto' => 'Produto',
            default => ucfirst($this->category),
        };
    }
}
