<?php

namespace Nit\AgraniHr\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Nit\AgraniHr\Models\EmployeeStatus;
use Nit\AgraniHr\Models\SalaryComponent;

/**
 * php artisan hr:install [--no-migrate]
 *
 * Runs the migrations and adds the two system employee statuses and the "basic" salary component. Safe to
 * run again. Departments, designations, employment types, leave types and other salary components are the
 * business's own and are not created here.
 */
class InstallCommand extends Command
{
    protected $signature = 'hr:install {--no-migrate : Do not run the migrations}';

    protected $description = 'Install agrani-hr: migrations, the system employee statuses and the basic salary component';

    /** @var array<string, array{name: string, is_employed: bool, sort_order: int}> */
    public const SYSTEM_STATUSES = [
        EmployeeStatus::ACTIVE => ['name' => 'Active', 'is_employed' => true, 'sort_order' => 10],
        EmployeeStatus::SEPARATED => ['name' => 'Separated', 'is_employed' => false, 'sort_order' => 90],
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('branches')) {
            $this->error('nit/master is not installed yet: run php artisan nit:install first.');

            return self::FAILURE;
        }

        if (! $this->option('no-migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        self::seedStatuses();

        $this->info('agrani-hr is installed. Add departments, designations, employment types, leave types and salary components, then give roles the HR abilities.');

        return self::SUCCESS;
    }

    public static function seedStatuses(): void
    {
        foreach (self::SYSTEM_STATUSES as $code => $status) {
            EmployeeStatus::query()->firstOrCreate(['code' => $code], $status + ['is_system' => true]);
        }

        SalaryComponent::query()->firstOrCreate(['code' => SalaryComponent::BASIC], [
            'name' => 'Basic salary', 'kind' => SalaryComponent::EARNING, 'calculation' => SalaryComponent::FIXED,
            'posting_key' => 'salary_expense', 'is_system' => true, 'sort_order' => 1,
        ]);
    }
}
