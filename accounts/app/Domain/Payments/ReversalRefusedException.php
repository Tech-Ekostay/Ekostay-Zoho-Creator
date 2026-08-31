<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use RuntimeException;

/**
 * Thrown when a reversal is not legal: no reason given, the payment never
 * settled, it is already a reversal, or it has been reversed once already.
 *
 * Maps to 422. Every one of these is a refusal to move money, which is the whole
 * point of §7.6 — the Creator path this replaces refused nothing.
 */
final class ReversalRefusedException extends RuntimeException {}
