<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkClick extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'link_page_id', 'block_index', 'block_type', 'block_label',
        'url', 'ip_hash', 'user_agent', 'referer', 'country', 'device',
        'clicked_at',
    ];

    protected $casts = [
        'block_index' => 'integer',
        'clicked_at' => 'datetime',
    ];

    public function linkPage(): BelongsTo
    {
        return $this->belongsTo(LinkPage::class);
    }
}
