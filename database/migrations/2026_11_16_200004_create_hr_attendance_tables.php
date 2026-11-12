<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance: one row per employee and working day with check-in / check-out and the minutes worked, late
 * and overtime (calculated by AttendanceService from the schedule in config('hr.attendance')). Corrections
 * are requested and approved; the approved values replace the record's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('work_date');
            $table->string('status', 12)->default('present');
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->time('scheduled_start')->nullable();
            $table->unsignedSmallInteger('scheduled_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('source', 12)->default('manual');
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index(['branch_id', 'work_date']);
        });

        Schema::create('hr_attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('hr_attendances')->nullOnDelete();
            $table->date('work_date');
            $table->string('requested_status', 12)->nullable();
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->string('reason', 500);
            $table->string('status', 10)->default('pending');
            $table->string('old_status', 12)->nullable();
            $table->dateTime('old_check_in_at')->nullable();
            $table->dateTime('old_check_out_at')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE hr_attendances ADD CONSTRAINT hr_attendances_status_check CHECK (status IN ('present','absent','half_day','leave','holiday','weekend'))");
            DB::statement('ALTER TABLE hr_attendances ADD CONSTRAINT hr_attendances_times_check CHECK (check_out_at IS NULL OR (check_in_at IS NOT NULL AND check_out_at >= check_in_at))');
            DB::statement("ALTER TABLE hr_attendance_corrections ADD CONSTRAINT hr_attendance_corrections_status_check CHECK (status IN ('pending','approved','rejected'))");
            DB::statement('ALTER TABLE hr_attendance_corrections ADD CONSTRAINT hr_attendance_corrections_times_check CHECK (check_out_at IS NULL OR check_in_at IS NULL OR check_out_at >= check_in_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_corrections');
        Schema::dropIfExists('hr_attendances');
    }
};
