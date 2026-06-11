<?php

namespace App\Models;

use App\Enums\BrandRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BrandInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'invited_by',
        'email',
        'role',
        'token',
        'accepted_at',
        'expires_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function role(): BrandRole
    {
        return BrandRole::from($this->getAttribute('role'));
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public function acceptUrl(): string
    {
        return route('invitations.accept', $this->token);
    }
}
