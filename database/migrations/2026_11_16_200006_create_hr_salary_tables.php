<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salary: components (basic, allowances, deductions; a fixed monthly amount or a percentage of basic) and
 * each employee's salary structure from an effective date. A new structure ends the previous one the day
 * before, so the structure of any day is known and a change in the middle of a month is prorated.
 *
 * posting_key names, for the accounting connection, where the component goes (e.g. salary_expense,
 * provident_fund); the host application maps the keys to its ledger accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('kind', 10);
            $table->string('calculation', 20)->default('fixed');
            $table->decimal('default_value', 18, 4)->nullable();
            $table->string('posting_key', 60);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'effective_from']);
        });

        Schema::create('hr_salary_structure_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained('hr_salary_structures')->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained('hr_salary_components')->restrictOnDelete();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('percent', 7, 4)->nullable();
            $table->timestamps();
            $table->unique(['salary_structure_id', 'salary_component_id'], 'hr_salary_lines_component_unique');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE hr_salary_components ADD CONSTRAINT hr_salary_components_kind_check CHECK (kind IN ('earning','deduction') AND calculation IN ('fixed','percent_of_basic'))");
            DB::statement('ALTER TABLE hr_salary_structures ADD CONSTRAINT hr_salary_structures_dates_check CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE hr_salary_structure_lines ADD CONSTRAINT hr_salary_lines_value_check CHECK ((amount IS NULL OR amount >= 0) AND (percent IS NULL OR (percent >= 0 AND percent <= 1000)) AND (amount IS NOT NULL OR percent IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_salary_structure_lines');
        Schema::dropIfExists('hr_salary_structures');
        Schema::dropIfExists('hr_salary_components');
    }
};
