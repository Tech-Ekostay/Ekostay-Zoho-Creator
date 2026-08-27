<?php

declare(strict_types=1);

namespace App\Services\Zoho;

use RuntimeException;

/**
 * A bulk export that failed in a way a fresh job usually survives.
 *
 * §3 of the field notes, MEASURED: big exports fail intermittently with a bare
 * `ERROR OCCURRED` under load, and a new job normally works. This exists so that
 * condition is retried while a genuine misconfiguration — a bad credential, a
 * missing workspace, an unknown payload shape — is not.
 *
 * A POLL TIMEOUT IS DELIBERATELY NOT THIS. A timed-out job is probably still
 * running and still holding an account-wide concurrency slot, so retrying it starts
 * the slot pile-up that took the other project's exports down. That path throws a
 * plain RuntimeException.
 */
class TransientExportFailure extends RuntimeException {}
