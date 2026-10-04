<?php

declare(strict_types=1);

namespace App\Application\Printing;

/** Port: the single stored print-settings row (null until first saved). */
interface PrintSettingsStore
{
    public function find(): ?PrintSettings;

    /** Row-locks the settings (inside a transaction); null when none stored yet. */
    public function findForUpdate(): ?PrintSettings;

    public function save(PrintSettings $settings, ?string $actorUserId): void;

    public function id(): ?string;
}
