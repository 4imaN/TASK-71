<?php

namespace App\Services\Api;

use App\Models\Reservation;

/**
 * Value object returned by ReservationApiGateway for every mutation.
 *
 * Both REST controllers and Livewire components receive the same typed
 * result; they differ only in how they present it to the caller:
 *   - REST controllers serialise $reservation to JSON and use $httpStatus.
 *   - Livewire components surface $error in the component's error state
 *     and redirect on success via $reservation->uuid.
 */
readonly class GatewayResult
{
    private function __construct(
        public readonly bool         $success,
        public readonly ?Reservation $reservation,
        public readonly ?string      $error,
        public readonly int          $httpStatus,
    ) {}

    public static function success(Reservation $reservation, int $httpStatus = 200): self
    {
        return new self(
            success:     true,
            reservation: $reservation,
            error:       null,
            httpStatus:  $httpStatus,
        );
    }

    public static function failure(string $error, int $httpStatus = 422): self
    {
        return new self(
            success:     false,
            reservation: null,
            error:       $error,
            httpStatus:  $httpStatus,
        );
    }
}
