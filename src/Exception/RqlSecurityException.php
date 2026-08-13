<?php

declare(strict_types=1);

namespace CoolMS\Rql\Exception;

use DomainException;

/**
 * Thrown when a query references a field not in the allowed whitelist.
 * Maps to HTTP 400 Bad Request (not 403 — avoids leaking schema info).
 *
 * `$purpose` names the capability that was refused. Filtering and sorting
 * carry separate whitelists ({@see \CoolMS\Rql\RqlContext::resolveSort}), so a
 * rejected `sort=` must not report the field as unavailable "for filtering" —
 * that sends whoever reads it to the wrong half of the grid config.
 */
final class RqlSecurityException extends DomainException
{
    public function __construct(string $field, string $purpose = 'filtering')
    {
        parent::__construct(sprintf('Field "%s" is not available for %s.', $field, $purpose));
    }
}
