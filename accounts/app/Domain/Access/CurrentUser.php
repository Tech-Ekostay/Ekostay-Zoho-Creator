<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Who is acting — the one seam between the audit fields and authentication.
 *
 * Creator fills `Added User` and `Modified User` from `zoho.loginuser`. There is no
 * session here yet: §3.3's permission matrix is extracted into first-class
 * `roles`/`permissions` tables and tested, but it is not wired to a gate, so every
 * endpoint is open and nobody is logged in.
 *
 * SO THIS RETURNS NULL, AND THAT IS THE HONEST ANSWER. A null `added_user` reads as
 * "not recorded", which is true. The alternatives are worse: a hardcoded `'system'`
 * would put a fake actor on real accounting records and be indistinguishable from a
 * real one six months from now.
 *
 * WHEN AUTH LANDS, bind this in a service provider and every model starts recording
 * the actor — no model, trait or migration changes:
 *
 *     $this->app->bind(CurrentUser::class, fn () => new AuthenticatedUser);
 *
 * A LOGIN STRING, NOT A FOREIGN KEY. The imported values are Creator logins —
 * `murali.zoho186`, `mansi.p`, `shibli_ekostayhospitality`, `ekostay` — and some of
 * them will not match any row in `employees`. Modelling this as a FK would reject
 * real audit history.
 */
class CurrentUser
{
    public function login(): ?string
    {
        return null;
    }
}
