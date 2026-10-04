<?php

declare(strict_types=1);

namespace App\Application\Printing;

use App\Domain\Shared\Exception\DomainException;

/**
 * Stable scan outcomes. Messages never echo the scanned text nor say which
 * entity types were searched, so an unknown code leaks nothing.
 */
final class ScanRejected extends DomainException
{
    public static function invalid(): self
    {
        return new self('The scanned code is not a valid DG Way code.', 'SCAN_CODE_INVALID');
    }

    public static function unsupportedVersion(): self
    {
        return new self('This code was made by a newer DG Way version. Update the app.', 'SCAN_VERSION_UNSUPPORTED');
    }

    public static function unknown(): self
    {
        return new self('No DG Way record matches this code.', 'SCAN_CODE_UNKNOWN');
    }

    /** @param list<string> $types */
    public static function ambiguous(array $types): AmbiguousScan
    {
        return new AmbiguousScan('This code matches more than one kind of record; scan the QR code instead.', 'SCAN_CODE_AMBIGUOUS', ['candidate_types' => $types]);
    }
}
