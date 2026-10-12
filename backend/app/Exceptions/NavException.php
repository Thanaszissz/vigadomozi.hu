<?php

namespace App\Exceptions;

use RuntimeException;

class NavException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}
