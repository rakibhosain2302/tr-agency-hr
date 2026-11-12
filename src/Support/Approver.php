<?php

namespace Nit\AgraniHr\Support;

use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Employee;

/**
 * Maker-checker, as nit-master's Approvals: nobody decides their own request (their own leave or advance,
 * or a request they made); super admins are not limited.
 */
final class Approver
{
    public static function ensureNotOwn(?Employee $employee, ?int $requestedBy): void
    {
        $user = auth()->user();
        if ($user === null || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return;
        }

        if ((int) $user->getAuthIdentifier() === (int) $requestedBy || ($employee?->user_id !== null && $employee->user_id === (int) $user->getAuthIdentifier())) {
            throw HrException::invalid(__('You cannot decide your own request.'), 'own_request');
        }
    }

    public static function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user !== null && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }
}
