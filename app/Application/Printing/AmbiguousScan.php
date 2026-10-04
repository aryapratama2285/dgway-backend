<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Domain\Shared\Exception\ConflictsWithCurrentState;
use App\Domain\Shared\Exception\DomainException;

/** 409: the text code resolves to more than one entity type (e.g. a SKU equal to a lot number). */
final class AmbiguousScan extends DomainException implements ConflictsWithCurrentState
{
}
