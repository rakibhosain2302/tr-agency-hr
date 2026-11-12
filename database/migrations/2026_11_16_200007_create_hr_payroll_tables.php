<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll: periods, one payroll per period and branch (draft → approved → paid, or reversed), and a payslip
 * (item) per employee with its earning and deduction lines.
 *
 * run_key ("period:branch") is UNIQUE while the payroll is live and cleared when it is reversed, so a period
 * and branch has one live payroll and a reversed one can be replaced by a corrected run. The accounting
 * references are the host's posting ids; the posting itself is the accounting package's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10)->default('open');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['start_date', 'end_date']);
        });

        Schema::create('hr_payrolls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('payroll_no', 30)->unique();
            $table->foreignId('payroll_period_id')->constrained('hr_payroll_periods')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 10)->default('draft');
            $table->string('run_key', 60)->nullable()->unique();
            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('gross_total', 18, 2)->default(0);
            $table->decimal('deduction_total', 18, 2)->default(0);
            $table->decimal('net_total', 18, 2)->default(0);
            $table->json('skipped')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('accounting_reference', 100)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('settlement_account', 30)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('payment_accounting_reference', 100)->nullable();
            $table->unsignedSmallInteger('payment_count')->default(0);
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->string('reversal_accounting_reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['payroll_period_id', 'branch_id']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('hr_payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('hr_payrolls')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('payslip_no', 40)->unique();
            $table->unsignedSmallInteger('period_days');
            $table->decimal('payable_days', 6, 2);
            $table->decimal('unpaid_leave_days', 5, 1)->default(0);
            $table->decimal('absent_days', 5, 1)->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('gross', 18, 2);
            $table->decimal('deductions', 18, 2);
            $table->decimal('net', 18, 2);
            $table->timestamps();
            $table->unique(['payroll_id', 'employee_id']);
            $table->index('employee_id');
        });

        Schema::create('hr_payroll_item_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained('hr_payroll_items')->cascadeOnDelete();
            $table->foreignId('salary_component_id')->nullable()->constrained('hr_salary_components')->restrictOnDelete();
            $table->unsignedBigInteger('employee_advance_id')->nullable()->index();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('kind', 10);
            $table->string('source', 15);
            $table->string('posting_key', 60);
            $table->decimal('amount', 18, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE hr_payroll_periods ADD CONSTRAINT hr_payroll_periods_check CHECK (end_date >= start_date AND status IN ('open','closed'))");
            DB::statement("ALTER TABLE hr_payrolls ADD CONSTRAINT hr_payrolls_status_check CHECK (status IN ('draft','approved','paid','reversed'))");
            DB::statement('ALTER TABLE hr_payrolls ADD CONSTRAINT hr_payrolls_totals_check CHECK (net_total = gross_total - deduction_total AND net_total >= 0)');
            DB::statement('ALTER TABLE hr_payroll_items ADD CONSTRAINT hr_payroll_items_totals_check CHECK (net = gross - deductions AND net >= 0)');
            DB::statement("ALTER TABLE hr_payroll_item_lines ADD CONSTRAINT hr_payroll_item_lines_check CHECK (amount >= 0 AND kind IN ('earning','deduction'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_item_lines');
        Schema::dropIfExists('hr_payroll_items');
        Schema::dropIfExists('hr_payrolls');
        Schema::dropIfExists('hr_payroll_periods');
    }
};
