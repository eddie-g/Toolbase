<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Word extends Model
{
    protected $fillable = ['word', 'rank', 'length'];

    /**
     * Get random words for domain generation.
     */
    public static function getRandomWords(int $count = 100, int $minLength = 3, int $maxLength = 10): array
    {
        return self::where('length', '>=', $minLength)
            ->where('length', '<=', $maxLength)
            ->inRandomOrder()
            ->limit($count)
            ->pluck('word')
            ->toArray();
    }

    /**
     * Get common words by rank.
     */
    public static function getCommonWords(int $count = 100, int $maxRank = 5000): array
    {
        return self::where('rank', '<=', $maxRank)
            ->where('length', '>=', 3)
            ->where('length', '<=', 10)
            ->inRandomOrder()
            ->limit($count)
            ->pluck('word')
            ->toArray();
    }
}

