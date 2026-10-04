<?php

declare(strict_types=1);

namespace App\Domain\Partner;

use App\Domain\Shared\Identity\Id;

interface BusinessPartnerRepository
{
    /** @throws \App\Domain\Shared\Exception\EntityNotFound */
    public function get(Id $id): BusinessPartner;

    public function getForUpdate(Id $id): BusinessPartner;

    /** Persists partner + roles. @throws \App\Domain\Shared\Exception\DuplicateCode */
    public function add(BusinessPartner $partner): void;

    /** Persists partner + roles. @throws \App\Domain\Shared\Exception\ConcurrentModification */
    public function save(BusinessPartner $partner): void;
}
