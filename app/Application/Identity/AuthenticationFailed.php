<?php

declare(strict_types=1);

namespace App\Application\Identity;

use RuntimeException;

final class AuthenticationFailed extends RuntimeException
{
    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    public const OPERATOR_INACTIVE = 'OPERATOR_INACTIVE';

    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('Email atau password salah.', self::INVALID_CREDENTIALS);
    }

    public static function inactive(): self
    {
        return new self('Akun operator tidak aktif.', self::OPERATOR_INACTIVE);
    }
}
