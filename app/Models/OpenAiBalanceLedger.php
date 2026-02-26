<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpenAiBalanceLedger extends Model
{
    protected $table = 'openai_balance_ledger';

    protected $fillable = [
        'type',
        'amount',
        'balance_after',
        'description',
        'model',
        'logo_request_id',
    ];

    protected $casts = [
        'amount'        => 'float',
        'balance_after' => 'float',
    ];
}
