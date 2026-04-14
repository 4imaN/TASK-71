<?php
namespace App\Exceptions;

use RuntimeException;

/**
 * Base class for all domain-layer exceptions.
 * Controllers and Livewire components catch these to produce
 * user-facing error messages without page reloads.
 */
class DomainException extends RuntimeException {}
