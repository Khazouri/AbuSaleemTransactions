<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\TestUserSeeder;
use Illuminate\Http\JsonResponse;

/**
 * The seeded test accounts, for the login screen's one-click sign-in panel.
 *
 * WHY IT EXISTS: TEST_PLAN.md's happy path needs four different people
 * (R02 -> R05 -> R03 -> R05 -> R06 -> R07) before a transaction reaches
 * `archived`, so a manual QA pass means signing in and out a dozen times.
 *
 * WHY IT IS SAFE: it answers 404 anywhere that is not APP_ENV=local. A 404
 * rather than a 403 because the two say different things — 403 admits the
 * endpoint exists and that something is behind it, which is not information a
 * production deployment should hand out about a list of known-password logins.
 *
 * The check is made here, per request, rather than by registering the route
 * only in local. Route definitions can be cached (`php artisan route:cache`)
 * on one machine and deployed to another, which would carry a boot-time
 * condition along with them; reading the environment when the request arrives
 * cannot be baked in that way.
 *
 * This is deliberately the ONLY endpoint in the system that enumerates
 * accounts — AuthController::login() goes out of its way not to (see its
 * identical-message comment), and that stays true everywhere this one is a 404.
 */
class DevTestUserController extends Controller
{
    public function index(): JsonResponse
    {
        abort_unless(app()->environment('local'), 404);

        // One query for all twelve, so an unseeded database is reported as such
        // instead of turning every click into a "bad credentials" mystery.
        $seeded = User::query()
            ->whereIn('email', array_column(TestUserSeeder::TEST_USERS, 0))
            ->pluck('email')
            ->all();

        $users = [];

        foreach (TestUserSeeder::TEST_USERS as [$email, $nameAr, $nameEn, $roleCodes, , , $isActive]) {
            $users[] = [
                'email' => $email,
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'roles' => $roleCodes,
                // The seeder's intent, not the row's current state: an account
                // suspended mid-test should still be listed as the one whose
                // sign-in is EXPECTED to be refused.
                'is_active' => $isActive,
                'seeded' => in_array($email, $seeded, true),
            ];
        }

        // Hand-built rather than wrapped in an API Resource: the payload is the
        // seeder's static definition plus one existence flag, not a shaped
        // model — no User attribute is ever read out of the database here.
        return response()->json([
            'password' => TestUserSeeder::PASSWORD,
            'seed_command' => 'php artisan db:seed --class=TestUserSeeder',
            'users' => $users,
        ]);
    }
}
