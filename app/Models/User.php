<?php

namespace App\Models;

use App\Enums\BrandRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'current_brand_id',
        'is_active',
        'is_admin',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_admin' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    // ===== RELATIONSHIPS =====

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentBrand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'current_brand_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function chatConversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // ===== METHODS =====

    /**
     * Retorna a marca ativa do usuario, ou a primeira marca disponivel
     */
    public function getActiveBrand(): ?Brand
    {
        if ($this->current_brand_id && $this->currentBrand) {
            return $this->currentBrand;
        }

        $firstBrand = $this->brands()->first();

        if ($firstBrand) {
            $this->update(['current_brand_id' => $firstBrand->id]);
            return $firstBrand;
        }

        return null;
    }

    /**
     * Troca a marca ativa do usuario
     */
    public function switchBrand(Brand $brand): void
    {
        if ($this->brands()->where('brand_id', $brand->id)->exists()) {
            $this->update(['current_brand_id' => $brand->id]);
        }
    }

    /**
     * Retorna o papel do usuario em uma marca
     */
    public function roleInBrand(Brand $brand): ?BrandRole
    {
        $pivot = $this->brands()->where('brand_id', $brand->id)->first()?->pivot;

        return $pivot ? BrandRole::from($pivot->role) : null;
    }

    /**
     * Acesso ao sistema: assinatura própria válida (ativa ou trial) ou
     * assento em marca de um Owner assinante. Com billing desabilitado,
     * todo usuário tem acesso.
     */
    public function hasValidSubscription(): bool
    {
        return app(\App\Services\Billing\UsageService::class)->userHasAccess($this);
    }
}
