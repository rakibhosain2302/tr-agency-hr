<?php

namespace Nit\AgraniHr\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nit\AgraniHr\Console\InstallCommand;
use Nit\AgraniHr\Contracts\HrAccounting;
use Nit\AgraniHr\Exceptions\HrException;
use Nit\AgraniHr\HrServiceProvider;
use Nit\AgraniHr\Models\Employee;
use Nit\AgraniHr\Models\EmployeeStatus;
use Nit\AgraniHr\Models\PayrollPeriod;
use Nit\AgraniHr\Models\SalaryComponent;
use Nit\AgraniHr\Models\SalaryStructure;
use Nit\AgraniHr\Services\EmployeeAdvanceService;
use Nit\AgraniHr\Services\EmployeeService;
use Nit\AgraniHr\Services\PayrollService;
use Nit\AgraniHr\Services\SalaryStructureService;
use Nit\AgraniHr\Tests\Fixtures\RecordingHrAccounting;
use Nit\AgraniHr\Tests\Fixtures\User;
use Nit\Master\Database\Seeders\RoleTableSeeder;
use Nit\Master\MasterServiceProvider;
use Nit\Master\Models\Branch;
use Nit\Master\Models\Role;
use Nit\Master\Support\ApiTokens;
use Nit\Master\Support\Branches;
use Nit\Master\Support\Nit;
use Nit\Master\Support\RoleSync;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * agrani-hr on a bare Laravel app with only nit/master: no host application code.
 */
#[WithMigration]
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [MasterServiceProvider::class, HrServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('nit.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('nit.api.enabled', true);

        // sqlite in memory by default; MySQL / MariaDB (with the CHECK constraints) when a database is named:
        // HR_TEST_DB_DATABASE=nit_hr_testing HR_TEST_DB_USERNAME=… HR_TEST_DB_PASSWORD=… vendor/bin/phpunit
        if (env('HR_TEST_DB_DATABASE')) {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', array_merge($app['config']->get('database.connections.mysql'), [
                'host' => env('HR_TEST_DB_HOST', '127.0.0.1'),
                'port' => env('HR_TEST_DB_PORT', '3306'),
                'database' => env('HR_TEST_DB_DATABASE'),
                'username' => env('HR_TEST_DB_USERNAME', 'root'),
                'password' => env('HR_TEST_DB_PASSWORD', ''),
            ]));
        } else {
            $app['config']->set('database.default', 'testing');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['rolepermission' => [], 'ipermissions' => []]);
        (new RoleTableSeeder)->run();
        InstallCommand::seedStatuses();
        Branches::flush();
    }

    protected function superAdmin(): User
    {
        return $this->user(Nit::superAdminRole());
    }

    /** @param  list<string>  $abilities  "Group::ability" or "Group::*" */
    protected function userWith(array $abilities): User
    {
        $role = Role::query()->create(['uuid' => (string) Str::uuid(), 'title' => 'Role '.Str::random(6), 'alias' => 'role_'.Str::lower(Str::random(8))]);
        [$keys, $unknown] = RoleSync::expand($abilities);
        $this->assertSame([], $unknown, 'Unknown abilities: '.implode(', ', $unknown));
        RoleSync::apply($role, $keys);

        return $this->user($role->id);
    }

    protected function user(int $roleId): User
    {
        return User::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => 'Person '.Str::random(5),
            'email' => Str::lower(Str::random(10)).'@example.test',
            'password' => Hash::make('secret-password'),
            'role_id' => $roleId,
        ]);
    }

    protected function branch(string $name, ?string $code = null): Branch
    {
        return Branch::query()->create(['name' => $name, 'code' => $code ?? strtoupper(substr($name, 0, 3)), 'active' => true]);
    }

    protected function inBranches(User $user, Branch ...$branches): User
    {
        $user->branches()->sync(array_map(fn (Branch $branch): int => $branch->id, $branches));
        Branches::flush();

        return $user;
    }

    /** @return array<string, string> */
    protected function bearer(User $user): array
    {
        [, $plain] = ApiTokens::issue($user, 'test');

        return ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    }

    /** The call is refused with this HrException reason. */
    protected function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (HrException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());

            return;
        }
        $this->fail("Expected the call to be refused ({$reason}).");
    }

    protected function employeeStatus(string $code): EmployeeStatus
    {
        return EmployeeStatus::query()->where('code', $code)->firstOrFail();
    }

    protected function accounting(): RecordingHrAccounting
    {
        if (! app(HrAccounting::class) instanceof RecordingHrAccounting) {
            $this->app->instance(HrAccounting::class, new RecordingHrAccounting);
            foreach ([PayrollService::class, EmployeeAdvanceService::class] as $service) {
                $this->app->forgetInstance($service);
            }
        }

        return app(HrAccounting::class);
    }

    protected function salaryComponent(string $code, string $kind = SalaryComponent::EARNING, string $calculation = SalaryComponent::FIXED, ?string $postingKey = null, ?string $default = null): SalaryComponent
    {
        return SalaryComponent::query()->firstOrCreate(['code' => $code], [
            'name' => ucwords(str_replace('_', ' ', $code)), 'kind' => $kind, 'calculation' => $calculation,
            'posting_key' => $postingKey ?? ($kind === SalaryComponent::EARNING ? 'salary_expense' : $code), 'default_value' => $default,
        ]);
    }

    /**
     * A salary from $from: basic, house rent (percent of basic), medical (fixed) and provident fund (percent
     * of basic, deduction).
     */
    protected function salary(Employee $employee, string $from, string $basic, string $housePercent = '50', string $medical = '1500', string $pfPercent = '10'): SalaryStructure
    {
        $lines = [['salary_component_id' => $this->salaryComponent(SalaryComponent::BASIC)->id, 'amount' => $basic]];
        if ($housePercent !== '0') {
            $lines[] = ['salary_component_id' => $this->salaryComponent('house_rent', calculation: SalaryComponent::PERCENT_OF_BASIC)->id, 'percent' => $housePercent];
        }
        if ($medical !== '0') {
            $lines[] = ['salary_component_id' => $this->salaryComponent('medical')->id, 'amount' => $medical];
        }
        if ($pfPercent !== '0') {
            $lines[] = ['salary_component_id' => $this->salaryComponent('provident_fund', SalaryComponent::DEDUCTION, SalaryComponent::PERCENT_OF_BASIC)->id, 'percent' => $pfPercent];
        }

        return app(SalaryStructureService::class)->create($employee, $from, $lines);
    }

    protected function period(string $month = '2026-07'): PayrollPeriod
    {
        $start = Carbon::parse($month.'-01');

        return PayrollPeriod::query()->firstOrCreate(['code' => $month], ['name' => $start->format('F Y'), 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString()]);
    }

    /** Hire someone in the branch (as a super admin when nobody is signed in). @param  array<string, mixed>  $data */
    protected function hire(Branch $branch, array $data = [], array $personal = []): Employee
    {
        return app(EmployeeService::class)->hire($data + [
            'branch_id' => $branch->id,
            'first_name' => 'Rahim',
            'last_name' => 'Uddin',
            'joining_date' => '2026-01-01',
        ], $personal);
    }
}
