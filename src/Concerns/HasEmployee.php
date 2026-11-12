<?php

namespace Nit\AgraniHr\Concerns;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Nit\AgraniHr\Models\Employee;

/**
 * For the host application's user model: the employee record of the user, if any.
 *
 *     class User extends \Nit\Master\Models\User { use HasEmployee; }
 *     auth()->user()->employee;
 */
trait HasEmployee
{
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }
}
