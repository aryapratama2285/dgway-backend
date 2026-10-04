<?php

declare(strict_types=1);

namespace App\Domain\Network;

/**
 * What an operational node can do in the supply-chain network.
 *
 * INVENTORY   holds stock (A3 inventory ledger posts against it)
 * RECEIVING   accepts goods from suppliers (goods receipt + QC)
 * FULFILLMENT picks and packs orders
 * PICKUP      customers collect orders here
 * DELIVERY    dispatches deliveries
 */
enum NodeCapability: string
{
    case INVENTORY = 'INVENTORY';
    case RECEIVING = 'RECEIVING';
    case FULFILLMENT = 'FULFILLMENT';
    case PICKUP = 'PICKUP';
    case DELIVERY = 'DELIVERY';
}
