<?php

declare(strict_types=1);

namespace CoolMS\Rql;

enum FilterOp: string
{
    case Eq = 'eq';   // equals
    case Ne = 'ne';   // not equals
    case Gt = 'gt';   // greater than
    case Lt = 'lt';   // less than
    case Ge = 'ge';   // greater or equal
    case Le = 'le';   // less or equal
    case Cn = 'cn';   // contains (LIKE %x%)
    case Bw = 'bw';   // begins with (LIKE x%)
    case Ew = 'ew';   // ends with (LIKE %x)
    case In = 'in';   // in array
    case Ni = 'ni';   // not in array
    case Null = 'null'; // is null
    case Nn = 'nn';   // is not null
}
