<?php

namespace Modules\KapsoWhatsApp\Services;

use App\User;

/**
 * A dedicated "robot" user (App\User::TYPE_ROBOT — "Workflows, teams, etc.")
 * that module-created threads are attributed to, the same mechanism the
 * bundled Workflows module uses for its own synthetic user
 * (Workflow::getUser(), fsworkflow@example.org).
 *
 * Core assumes every TYPE_MESSAGE/TYPE_NOTE thread has a creator:
 * Thread::createExtended() refuses to build one without a user id, and the
 * print view (resources/views/conversations/partials/thread.blade.php)
 * dereferences `$thread->created_by_user_cached->getFullName()` with no null
 * guard, which is fatal for a thread whose created_by_user_id is null. A
 * foreign send or a delivery failure recorded by ReconcileOutboundMessage was
 * not authored by any real FreeScout agent, so it is attributed to this
 * synthetic user instead of left null.
 */
class SystemUser
{
    const EMAIL = 'kapsowhatsapp@example.org';

    protected static $user;

    public static function get(): User
    {
        if (self::$user) {
            return self::$user;
        }

        self::$user = User::where('email', self::EMAIL)->first();

        if (!self::$user) {
            self::$user = User::create([
                'first_name' => 'WhatsApp',
                'last_name'  => '',
                'email'      => self::EMAIL,
                'password'   => bcrypt(\Str::random(25)),
                'status'     => User::STATUS_DELETED,
                'type'       => User::TYPE_ROBOT,
            ]);
        }

        return self::$user;
    }

    /**
     * Test-only. This class caches the resolved user in a plain PHP static,
     * which is correct and cheap in production (a real request/queue-worker
     * process either never calls get() or, once it has, the row it cached
     * persists in the database for the rest of that process's life) but
     * becomes stale inside a test suite that wraps each test in a rolled-back
     * transaction (DatabaseTransactions): the static would otherwise keep
     * pointing at a user id from a previous test that no longer exists,
     * silently reintroducing the exact null-created_by_user_cached failure
     * this class exists to prevent. Tests/TestCase.php calls this from
     * tearDown() for every test, the same way it resets KapsoClient's fake.
     */
    public static function clearCache(): void
    {
        self::$user = null;
    }
}
