<?php

declare(strict_types=1);

namespace App\Domain\Partner;

/** Whether the partner is a person (peternak, warung owner) or an organisation. */
enum PartyType: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case ORGANIZATION = 'ORGANIZATION';
}
