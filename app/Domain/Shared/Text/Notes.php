<?php

declare(strict_types=1);

namespace App\Domain\Shared\Text;

use App\Domain\Shared\Exception\InvariantViolation;

/** Free-text operational notes: trimmed, empty → null, max 2000 characters. */
final class Notes
{
    public const MAX = 2000;

    public static function clean(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }
        $notes = trim($notes);
        if ($notes === '') {
            return null;
        }
        if (mb_strlen($notes) > self::MAX) {
            throw InvariantViolation::because('NOTES_TOO_LONG', 'Notes must be at most '.self::MAX.' characters.', ['field' => 'notes']);
        }

        return $notes;
    }
}
