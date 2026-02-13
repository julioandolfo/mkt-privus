<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailListSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_list_id', 'type', 'config', 'sync_frequency',
        'last_synced_at', 'sync_status', 'sync_error', 'records_synced',
    ];

    protected $casts = [
        'config' => 'array',
        'last_synced_at' => 'datetime',
        'records_synced' => 'integer',
    ];

    protected $hidden = ['config'];

    // ===== RELATIONSHIPS =====

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'email_list_id');
    }

    // ===== SCOPES =====

    public function scopeNeedingSync($query)
    {
        return $query->where('sync_frequency', '!=', 'manual')
            ->where(function ($q) {
                $q->whereNull('last_synced_at')
                    ->orWhere(function ($q2) {
                        $q2->where('sync_frequency', 'daily')
                            ->where('last_synced_at', '<', now()->subDay());
                    })
                    ->orWhere(function ($q2) {
                        $q2->where('sync_frequency', 'weekly')
                            ->where('last_synced_at', '<', now()->subWeek());
                    })
                    ->orWhere(function ($q2) {
                        $q2->where('sync_frequency', 'monthly')
                            ->where('last_synced_at', '<', now()->subMonth());
                    });
            });
    }

    // ===== METHODS =====

    public function markSyncing(): void
    {
        $this->update(['sync_status' => 'syncing', 'sync_error' => null]);
    }

    public function markSuccess(int $records): void
    {
        $this->update([
            'sync_status' => 'success',
            'last_synced_at' => now(),
            'records_synced' => $records,
            'sync_error' => null,
        ]);
    }

    public function markError(string $error): void
    {
        $this->update([
            'sync_status' => 'error',
            'sync_error' => substr($error, 0, 1000),
        ]);
    }

    public function getConfigValue(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
}
