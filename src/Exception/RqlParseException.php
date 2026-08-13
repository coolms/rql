<?php

declare(strict_types=1);

namespace CoolMS\Rql\Exception;

use DomainException;

/**
 * Thrown when the RQL query string has invalid syntax.
 * Maps to HTTP 400 Bad Request.
 */
final class RqlParseException extends DomainException
{
    public function __construct(string $message, public readonly string $token = '')
    {
        parent::__construct($message);
    }
}
