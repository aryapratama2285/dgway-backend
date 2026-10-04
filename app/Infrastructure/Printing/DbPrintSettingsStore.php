<?php

declare(strict_types=1);

namespace App\Infrastructure\Printing;

use App\Application\Printing\PrintSettings;
use App\Application\Printing\PrintSettingsStore;
use App\Application\Shared\Identity\IdentityGenerator;
use Illuminate\Database\ConnectionInterface;

final class DbPrintSettingsStore implements PrintSettingsStore
{
    public function __construct(private readonly ConnectionInterface $db, private readonly IdentityGenerator $ids)
    {
    }

    public function find(): ?PrintSettings
    {
        return $this->hydrate($this->db->table('print_settings')->first());
    }

    public function findForUpdate(): ?PrintSettings
    {
        return $this->hydrate($this->db->table('print_settings')->lockForUpdate()->first());
    }

    public function id(): ?string
    {
        $id = $this->db->table('print_settings')->value('id');

        return $id === null ? null : (string) $id;
    }

    public function save(PrintSettings $s, ?string $actorUserId): void
    {
        $row = $s->toInput() + ['version' => $s->version, 'updated_by' => $actorUserId, 'updated_at' => now()];
        $id = $this->id();
        if ($id === null) {
            // the singleton index makes a concurrent first save fail instead of creating two rows
            $this->db->table('print_settings')->insert(['id' => $this->ids->next()->value, 'created_at' => now()] + $row);

            return;
        }
        $this->db->table('print_settings')->where('id', $id)->update($row);
    }

    private function hydrate(?object $r): ?PrintSettings
    {
        return $r === null ? null : PrintSettings::of((array) $r, (int) $r->version);
    }
}
