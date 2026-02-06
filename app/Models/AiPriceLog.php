<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiPriceLog extends Model
{
    protected $table = 'ai_price_log';
    
    protected $fillable = [
        'session',
        'document_id',
        'user_email',
        'request_type',
        'model_name',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'image_count',
        'image_size',
        'cost_usd',
        'estimated_cost_usd',
        'prompt_preview',
        'status',
    ];
    
    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'image_count' => 'integer',
        'cost_usd' => 'decimal:6',
        'estimated_cost_usd' => 'decimal:6',
    ];
}
