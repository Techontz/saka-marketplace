<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Failure notification routing (mail/Slack) is deliberately left to the
        // operator rather than hard-coded here — see docs/DEPLOYMENT.md.
    }

    /**
     * Who may open the Horizon dashboard outside `local`.
     *
     * The stock scaffold ships `in_array($user->email, [])`, which is false for
     * everybody: Horizon would have been unreachable in production while
     * looking configured. Gating on the permission system instead means Horizon
     * access follows the same role matrix as the rest of the admin surface, and
     * revoking an operator's role revokes their dashboard access in the same
     * action rather than requiring a code change and a deploy.
     *
     * The permission chosen is `settings.manage`, which only `super_admin`
     * holds. That is deliberate and stricter than the rest of the admin
     * surface: Horizon renders raw job PAYLOADS, and those carry inquiry
     * bodies, seller emails and phone numbers. A moderator who may moderate a
     * listing has no business reading the message queue. It is also consistent
     * with platform settings already being super-admin only.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            return $user?->hasPermission(Permission::SettingsManage) ?? false;
        });
    }
}
