<?php

namespace App\Exceptions;

use RuntimeException;

class ProductionConfigException extends RuntimeException
{
    /** @param string[] $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(
            "Production configuration is not valid; the app will not start until this is fixed:\n - "
            .implode("\n - ", $problems)
            ."\nFix the variables, then run: composer deploy (or php artisan config:cache). Check with: php artisan app:check-config"
        );
    }
}
