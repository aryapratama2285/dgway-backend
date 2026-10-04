<?php

declare(strict_types=1);

namespace App\Application\Partner;

use App\Application\Shared\Query\Page;
use App\Application\Shared\Query\PageRequest;
use App\Application\Shared\Query\StatusFilter;
use App\Domain\Partner\BusinessPartner;
use App\Domain\Partner\CustomerType;
use App\Domain\Partner\PartnerRole;

interface PartnerQueries
{
    /**
     * @param  ?PartnerRole  $role  only partners holding this role; with status=active the ROLE must be active too
     * @return Page<BusinessPartner>
     */
    public function partners(StatusFilter $status, ?PartnerRole $role, ?CustomerType $customerType, ?string $search, PageRequest $page): Page;
}
