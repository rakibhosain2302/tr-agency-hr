<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave: types, applications (pending → approved / rejected / cancelled) and a leave ledger per employee,
 * type and calendar year. The balance is the sum of the ledger (entitlement + carry forward + adjustments −
 * taken + restored), so every change is history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->decimal('days_per_year', 5, 1)->nullable();
            $table->boolean('is_paid')->default(true);
            $table->decimal('carry_forward_max', 5, 1)->default(0);
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_leave_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('half_day')->default(false);
            $table->decimal('days', 5, 1);
            $table->string('reason', 500)->nullable();
            $table->string('status', 10)->default('pending');
            $table->unsignedBigInteger('applied_by')->nullable()->index();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'start_date']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('hr_leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('kind', 15);
            $table->decimal('days', 6, 1);
            $table->foreignId('leave_application_id')->nullable()->constrained('hr_leave_applications')->restrictOnDelete();
            $table->string('unique_key', 120)->nullable()->unique();
            $table->date('entry_date');
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['employee_id', 'leave_type_id', 'year']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE hr_leave_applications ADD CONSTRAINT hr_leave_applications_status_check CHECK (status IN ('pending','approved','rejected','cancelled'))");
            DB::statement('ALTER TABLE hr_leave_applications ADD CONSTRAINT hr_leave_applications_dates_check CHECK (end_date >= start_date AND days > 0)');
            DB::statement("ALTER TABLE hr_leave_ledger ADD CONSTRAINT hr_leave_ledger_kind_check CHECK (kind IN ('entitlement','carry_forward','adjustment','taken','restored'))");
            DB::statement('ALTER TABLE hr_leave_types ADD CONSTRAINT hr_leave_types_days_check CHECK ((days_per_year IS NULL OR days_per_year >= 0) AND carry_forward_max >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_ledger');
        Schema::dropIfExists('hr_leave_applications');
        Schema::dropIfExists('hr_leave_types');
    }
};
