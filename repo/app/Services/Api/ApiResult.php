<?php

namespace App\Services\Api;

/**
 * Generic typed result returned by API gateway mutations.
 *
 * Mirrors the contract of GatewayResult (reservation-specific) but works
 * with any domain entity. Both REST controllers and Livewire components
 * receive the same typed result; they differ only in presentation:
 *   - REST controllers serialise $data to JSON and use $httpStatus.
 *   - Livewire components surface $error in component state and act on $data.
 */
readonly class ApiResult
{
    private function __construct(
        public readonly bool    $success,
        public readonly mixed   $data,
        public readonly ?string $error,
        public readonly int     $httpStatus,
    ) {}

    public static function success(mixed $data = null, int $httpStatus = 200): self
    {
        return new self(
            success:    true,
            data:       $data,
            error:      null,
            httpStatus: $httpStatus,
        );
    }

    public static function failure(string $error, int $httpStatus = 422): self
    {
        return new self(
            success:    false,
            data:       null,
            error:      $error,
            httpStatus: $httpStatus,
        );
    }
}
