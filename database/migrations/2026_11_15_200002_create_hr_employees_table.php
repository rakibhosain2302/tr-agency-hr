<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employees. user_id links the employee to nit-master's user (sign-in account), if they have one; the user
 * table is never copied. branch_id is the employee's main branch; further branches are assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('employee_code', 30)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('hr_designations')->restrictOnDelete();
            $table->foreignId('employment_type_id')->nullable()->constrained('hr_employment_types')->restrictOnDelete();
            $table->foreignId('employee_status_id')->constrained('hr_employee_statuses')->restrictOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 25)->nullable()->index();
            $table->string('email', 191)->nullable()->index();
            $table->string('national_id', 30)->nullable();
            $table->date('joining_date');
            $table->date('termination_date')->nullable();
            $table->string('termination_reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'employee_status_id']);
            $table->index(['last_name', 'first_name']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE hr_employees ADD CONSTRAINT hr_employees_termination_check CHECK (termination_date IS NULL OR termination_date >= joining_date)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employees');
    }
};
