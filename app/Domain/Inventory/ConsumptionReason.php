<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * Why DG Way consumed its own material (INTERNAL_CONSUMPTION). Deliberately
 * small and stable: free details go into the notes.
 */
enum ConsumptionReason: string
{
    case PACKING = 'PACKING';         // packing goods for retail / B2B orders
    case LABELING = 'LABELING';       // labels, thermal labels
    case DELIVERY = 'DELIVERY';       // delivery operation (tape, raffia, stretch film …)
    case ADMIN = 'ADMIN';             // administration (receipt paper, office supplies)
    case SAMPLE = 'SAMPLE';           // samples given away
    case OTHER = 'OTHER';             // anything else — explain in the notes
}
