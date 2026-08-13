<?php

declare(strict_types=1);

namespace CoolMS\Rql\Exception;

use DomainException;
use Throwable;

/**
 * Thrown when a filter value cannot be coerced to the field's database type
 * (e.g. non-UUID string filtered via `eq` on a UUID column). Maps to HTTP 400.
 */
final class RqlInvalidValueException extends DomainException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
