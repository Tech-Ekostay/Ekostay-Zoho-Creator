<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\Role;
use Illuminate\Support\Collection;

/**
 * The ONLY place a raw Creator `User_Role` string is turned into a Role.
 *
 * §17 step 3: "no string .contains() anywhere in the authorisation path". This
 * class is the boundary — resolution happens once, at import, and every later
 * permission check reads `employees.role_id`.
 *
 * WHY IT IS CASE-INSENSITIVE. The live provisioning chain fails twice on casing
 * (addendum §14), and the data proves it: running the real Deluge logic over the
 * real 475 records assigns a profile to ZERO of them.
 *
 *   Admin.ds       .contains("Account Team-Executive")   <- Title Case literal
 *   the data       "account team-executive"              <- lowercase, 97% of rows
 *   PortalAccess   UserName == "account team-executive"  <- lowercase literal
 *                  ...but Admin passes it "Account Team-Executive"
 *
 * The data also holds `market head` x21 and `Market Head` x1 — the same role in
 * two casings. Any case-sensitive comparison splits that population.
 *
 * So this resolver folds case and collapses whitespace. That is a DELIBERATE
 * behaviour change from Creator, and the only one: it is not a redesign, it is
 * the fix for a defect that currently provisions nobody. Everything else about
 * the role set is reproduced exactly, including roles with no permissions.
 *
 * Unknown values return null rather than a default. Creator has no `else` branch
 * on either chain and fails silently; failing to null and being *visible* about
 * it is the point.
 */
final class RoleResolver
{
    /** @var Collection<string, Role>|null */
    private ?Collection $byKey = null;

    /**
     * The 24 distinct `User_Role` values in the live export, mapped to slugs.
     * Values with no Accounts access map to their own role rather than to null —
     * a caretaker IS a role, it simply holds no Accounts permissions.
     *
     * @var array<string, string>
     */
    public const SOURCE_TO_SLUG = [
        'account team-executive' => 'account-team-executive',
        'account team-senior' => 'account-team-senior',
        'accounts head' => 'accounts-head',
        'food operator' => 'food-operator',
        'property manager' => 'property-manager',
        'market head' => 'market-head',
        'central operations' => 'central-operations',
        'human resources' => 'human-resources',
        'ca' => 'ca',
        'manager' => 'manager',
        'caretaker' => 'caretaker',
        'salesperson' => 'salesperson',
        'sales manager' => 'sales-manager',
        'dependant property owner' => 'dependant-property-owner',
        'independant property owner' => 'independant-property-owner',
        'operations executor' => 'operations-executor',
        'store_keeper' => 'store-keeper',
        'superadmin' => 'superadmin',
        'administrator' => 'administrator',
        'promoter' => 'promoter',
        'vendor' => 'vendor',
        'co-founder' => 'co-founder',
        'check-in assistant' => 'check-in-assistant',
        'ops analyst' => 'ops-analyst',
        'dataentry' => 'data-entry',
        'salesperson1' => 'salesperson',
    ];

    /** Normalise a raw Creator role string: fold case, collapse whitespace. */
    public static function key(?string $sourceRole): ?string
    {
        if ($sourceRole === null) {
            return null;
        }

        $key = strtolower(trim(preg_replace('/\s+/', ' ', $sourceRole) ?? ''));

        return $key === '' ? null : $key;
    }

    public function resolve(?string $sourceRole): ?Role
    {
        $key = self::key($sourceRole);

        if ($key === null) {
            return null;
        }

        $slug = self::SOURCE_TO_SLUG[$key] ?? null;

        if ($slug === null) {
            return null;
        }

        $this->byKey ??= Role::all()->keyBy('slug');

        return $this->byKey->get($slug);
    }

    /** Role values in the data that this resolver does not recognise. */
    public function unresolved(iterable $sourceRoles): array
    {
        $missing = [];

        foreach ($sourceRoles as $source) {
            $key = self::key($source);

            if ($key !== null && ! isset(self::SOURCE_TO_SLUG[$key])) {
                $missing[$key] = ($missing[$key] ?? 0) + 1;
            }
        }

        return $missing;
    }
}
