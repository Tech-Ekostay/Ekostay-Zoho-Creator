<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * TRIMMING IS DISABLED ON THE MASTER-DATA WRITE PATHS. This is not a style
         * choice — Laravel's global TrimStrings middleware would silently corrupt
         * live lookup keys.
         *
         * CLAUDE.md: "Preserve source spellings. `Luxery`, `ACCOMODATION`,
         * `Maintaince`, ... and the trailing space on `F&B STAFF MEDICAL EXPENSE `.
         * These are live lookup keys. Normalise at display only, never in data."
         *
         * That item category is 26 characters in the database and 25 trimmed —
         * verified 22-Aug-2026. Editing it through a normal Laravel route would save
         * the 25-character form and break every join that currently matches on it,
         * with no error anywhere. The seeder's `text()` reader already refuses to
         * trim on import for exactly this reason; the write path has to match, or
         * import and edit disagree about what the data is.
         *
         * Eight villa names carry a leading space and three carry doubled spaces
         * (addendum §15), so this is not a single-record curiosity.
         *
         * A closure rather than an attribute list: the rule is about these ROUTES,
         * and enumerating attribute names would silently miss any field added later.
         */
        $middleware->trimStrings(except: [
            fn (Request $request): bool => $request->is('api/settings/*'),
        ]);

        /*
         * ConvertEmptyStringsToNull is deliberately LEFT ON. `''` becoming null
         * matches what the seeder's `text()` does on import, so a field cleared in
         * the form and a field absent from an export end up identical. Responses
         * render null back as `''`, which is what Creator shows.
         */
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
