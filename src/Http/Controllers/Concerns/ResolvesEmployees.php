<?php

namespace Nit\AgraniHr\Http\Controllers\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Employee;

/**
 * Requests made for oneself or for others. Without $othersAbility (the area's view ability) a user acts only
 * for their own employee record; with it, for any employee they can see.
 */
trait ResolvesEmployees
{
    protected function employeeOrSelf(?string $uuid, ?string $othersAbility = null): Employee
    {
        $self = $this->ownEmployee();
        if ($uuid === null || $uuid === '' || $uuid === $self?->uuid) {
            return $self ?? throw HrException::invalid(__('Your user is not linked to an employee.'), 'no_employee');
        }
        $this->ensureMayActForOthers($othersAbility);

        return Employee::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** The record must be the user's own unless they hold $othersAbility. */
    protected function ensureOwnOr(int $employeeId, ?string $othersAbility): void
    {
        if ($this->ownEmployee()?->id !== $employeeId) {
            $this->ensureMayActForOthers($othersAbility);
        }
    }

    private function ensureMayActForOthers(?string $ability): void
    {
        if ($ability !== null && ! Gate::allows($ability)) {
            throw new AuthorizationException(__('You may only do this for yourself.'));
        }
    }

    private function ownEmployee(): ?Employee
    {
        return auth()->id() === null ? null : Employee::query()->withoutGlobalScope('nit_branch')->withoutGlobalScope('hr_branch_assigned')->where('user_id', auth()->id())->first();
    }
}
