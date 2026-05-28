<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentNote extends Model
{
    protected $fillable = [
        'document_id',
        'user_id',
        'admin_id',
        'page_index',
        'anchor_x',
        'anchor_y',
        'body',
    ];

    protected $casts = [
        'page_index' => 'integer',
        'anchor_x' => 'float',
        'anchor_y' => 'float',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
