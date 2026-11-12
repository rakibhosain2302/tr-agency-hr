<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What belongs to an employee: personal information (1:1), branch assignments (dated; one primary at a time),
 * documents (the file itself is a nit-master "files" row) and the history of changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_personal_infos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('hr_employees')->restrictOnDelete();
            $table->string('father_name', 150)->nullable();
            $table->string('mother_name', 150)->nullable();
            $table->string('spouse_name', 150)->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->string('religion', 50)->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('personal_email', 191)->nullable();
            $table->string('personal_phone', 25)->nullable();
            $table->string('present_address', 500)->nullable();
            $table->string('permanent_address', 500)->nullable();
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_relation', 50)->nullable();
            $table->string('emergency_contact_phone', 25)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_employee_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'end_date']);
            $table->index(['branch_id', 'end_date']);
        });

        Schema::create('hr_employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('document_type', 40);
            $table->string('title', 191)->nullable();
            $table->string('document_number', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'document_type']);
        });

        Schema::create('hr_employee_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('event', 30);
            $table->string('field', 50)->nullable();
            $table->string('old_value', 191)->nullable();
            $table->string('new_value', 191)->nullable();
            $table->date('effective_date');
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            $table->index(['employee_id', 'effective_date']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE hr_employee_branch_assignments ADD CONSTRAINT hr_employee_branch_assignments_dates_check CHECK (end_date IS NULL OR end_date >= start_date)');
            DB::statement('ALTER TABLE hr_employee_documents ADD CONSTRAINT hr_employee_documents_dates_check CHECK (expiry_date IS NULL OR issue_date IS NULL OR expiry_date >= issue_date)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_histories');
        Schema::dropIfExists('hr_employee_documents');
        Schema::dropIfExists('hr_employee_branch_assignments');
        Schema::dropIfExists('hr_employee_personal_infos');
    }
};
