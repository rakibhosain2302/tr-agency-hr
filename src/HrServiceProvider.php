<?php

namespace Nit\AgraniHr;

use Illuminate\Support\ServiceProvider;
use Nit\AgraniHr\Console\InstallCommand;
use Nit\AgraniHr\Contracts\EmployeeCodeGenerator;
use Nit\AgraniHr\Contracts\HrAccounting;
use Nit\AgraniHr\Services\AttendanceService;
use Nit\AgraniHr\Services\EmployeeAdvanceService;
use Nit\AgraniHr\Services\EmployeeDocumentService;
use Nit\AgraniHr\Services\EmployeeService;
use Nit\AgraniHr\Services\LeaveService;
use Nit\AgraniHr\Services\PayrollCalculator;
use Nit\AgraniHr\Services\PayrollService;
use Nit\AgraniHr\Services\SalaryStructureService;
use Nit\AgraniHr\Support\DatabaseEmployeeCodeGenerator;
use Nit\AgraniHr\Support\NullHrAccounting;

/**
 * agrani-hr: employees, attendance, leave and payroll on nit/master (users, branches, roles, files). Registers
 * the config, migrations, API routes, services, the default employee numbering and the (empty) accounting
 * connection.
 */
class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hr.php', 'hr');

        // A host application binds its own numbering with bind() to replace this default.
        $this->app->bindIf(EmployeeCodeGenerator::class, DatabaseEmployeeCodeGenerator::class);
        // The host application binds its accounting connection; without one HR posts nothing.
        $this->app->bindIf(HrAccounting::class, NullHrAccounting::class);

        foreach ([EmployeeService::class, EmployeeDocumentService::class, AttendanceService::class, LeaveService::class, SalaryStructureService::class, PayrollCalculator::class, PayrollService::class, EmployeeAdvanceService::class, \Nit\AgraniHr\Reports\HrReportService::class, \Nit\AgraniHr\Reports\HrReportExporter::class] as $service) {
            $this->app->singletonIf($service);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'hr');

        $this->publishes([__DIR__.'/../config/hr.php' => config_path('hr.php')], 'hr-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'hr-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
    }
}
