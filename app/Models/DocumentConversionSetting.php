<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentConversionSetting extends Model
{
    public const PROVIDER_LOCAL = 'local';

    public const PROVIDER_ADOBE = 'adobe';

    protected $fillable = [
        'word_provider',
        'excel_provider',
        'fallback_to_local',
    ];

    protected $casts = [
        'fallback_to_local' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'word_provider' => self::PROVIDER_ADOBE,
            'excel_provider' => self::PROVIDER_ADOBE,
            'fallback_to_local' => false,
        ]);
    }
}
