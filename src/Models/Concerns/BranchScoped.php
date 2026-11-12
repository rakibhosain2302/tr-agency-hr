<?php

namespace Nit\AgraniHr\Models\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Nit\Master\Models\Concerns\BelongsToBranch;
use Nit\Master\Support\Branches;

/**
 * Branch-wise records (column branch_id) on top of nit-master's BelongsToBranch, which shows the signed-in
 * user only the records of the branch(es) in view and gives new records the current branch. Added here:
 *
 * - a user who is not a super admin and works in no branch sees no records (nit-master would show all);
 * - a record can only be created in, moved to, moved out of, or deleted from a branch the user may pick.
 *
 * Super admins, guests and console commands (e.g. queued posting) are not limited, as in nit-master.
 */
trait BranchScoped
{
    use BelongsToBranch;

    public static function bootBranchScoped(): void
    {
        static::addGlobalScope('hr_branch_assigned', function (Builder $query): void {
            if (static::branchlessUser(auth()->user())) {
                $query->whereRaw('1 = 0');
            }
        });

        // Runs before BelongsToBranch's own creating listener, so the default branch is filled here first.
        static::creating(function (self $record): void {
            if (empty($record->branch_id) && auth()->check()) {
                $record->branch_id = Branches::defaultFor(auth()->user());
            }

            $record->ensureBranchIsAllowed($record->branch_id);
        });

        static::updating(function (self $record): void {
            $record->ensureBranchIsAllowed($record->getOriginal('branch_id'));
            $record->ensureBranchIsAllowed($record->branch_id);
        });

        static::deleting(function (self $record): void {
            $record->ensureBranchIsAllowed($record->getOriginal('branch_id'));
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureBranchIsAllowed(int|string|null $branchId): void
    {
        $user = auth()->user();

        if ($user === null || ! Branches::enabled() || static::isSuperAdmin($user)) {
            return;
        }

        $allowed = static::branchlessUser($user) ? [] : Branches::forUser($user)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($branchId === null || ! in_array((int) $branchId, $allowed, true)) {
            throw new AuthorizationException(__('You may not work with records of this branch.'));
        }
    }

    protected static function branchlessUser(?Authenticatable $user): bool
    {
        return $user !== null && Branches::enabled() && ! static::isSuperAdmin($user) && Branches::assignedIds($user) === [];
    }

    protected static function isSuperAdmin(Authenticatable $user): bool
    {
        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }
}
