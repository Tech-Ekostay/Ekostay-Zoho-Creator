<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use RuntimeException;

/**
 * Thrown when a payment's split legs do not sum to its payable — the check §7.4
 * says Payments is missing entirely.
 *
 * A distinct type rather than a bare RuntimeException because the HTTP layer maps
 * it to 422: it is a rejected write, not a server fault.
 */
final class UnbalancedPaymentException extends RuntimeException {}
