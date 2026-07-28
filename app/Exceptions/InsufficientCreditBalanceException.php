<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditBalanceException extends RuntimeException
{
    public function __construct(
        public readonly float $required,
        public readonly float $available,
    ) {
        parent::__construct('Insufficient credit balance.');
    }
}
