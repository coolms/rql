<?php

declare(strict_types=1);

namespace CoolMS\Rql\Exception;

use DomainException;

/**
 * Thrown when an operator is applied to a field whose SQL type does not support it
 * (e.g. LIKE-family ops on numeric extractions). Maps to HTTP 400 Bad Request.
 */
final class RqlInvalidOperatorException extends DomainException
{
    public function __construct(string $op, string $field, string $sqlType)
    {
        parent::__construct(sprintf(
            'Filter operator "%s" is not applicable to field "%s" of type %s. '
            . 'Use range operators (gt/lt/ge/le) or equality (eq) for non-text fields.',
            $op,
            $field,
            $sqlType,
        ));
    }
}
