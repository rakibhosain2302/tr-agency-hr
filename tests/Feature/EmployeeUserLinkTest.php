<?php

namespace Nit\AgraniHr\Tests\Feature;

use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Services\EmployeeService;
use Nit\AgraniHr\Tests\TestCase;
use Nit\Master\Models\User;

class EmployeeUserLinkTest extends TestCase
{
    public function test_an_employee_links_to_an_existing_user_without_a_second_users_table(): void
    {
        $account = $this->userWith([]);
        $employee = $this->hire($this->branch('Dhaka'), ['user_id' => $account->id]);

        $this->assertTrue($employee->user->is($account));
        $this->assertInstanceOf(User::class, $employee->user);
        $this->assertSame(['joined', 'user_linked'], $employee->history()->reorder('id')->pluck('event')->all());
    }

    public function test_a_user_belongs_to_one_employee_at_most(): void
    {
        $dhaka = $this->branch('Dhaka');
        $account = $this->userWith([]);
        $this->hire($dhaka, ['user_id' => $account->id]);
        $other = $this->hire($dhaka);

        $this->assertRefused('user_taken', fn () => app(EmployeeService::class)->linkUser($other, $account->id));
    }

    public function test_unknown_users_and_double_links_are_refused_and_unlinking_frees_the_user(): void
    {
        $dhaka = $this->branch('Dhaka');
        $account = $this->userWith([]);
        $employee = $this->hire($dhaka);
        $service = app(EmployeeService::class);

        foreach ([[fn () => $service->linkUser($employee, 999999), 'invalid_user'], [fn () => $service->unlinkUser($employee), 'user_not_linked']] as [$call, $reason]) {
            try {
                $call();
                $this->fail("Expected {$reason}.");
            } catch (HrException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }

        $service->linkUser($employee, $account->id);
        $service->unlinkUser($employee);
        $other = $this->hire($dhaka);
        $service->linkUser($other, $account->id);

        $this->assertNull($employee->fresh()->user_id);
        $this->assertSame($account->id, Employee::query()->whereKey($other->id)->value('user_id'));
    }
}
