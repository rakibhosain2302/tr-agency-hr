<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee advances: requested → approved / rejected → disbursed (posted to accounting) → settled by salary
 * deductions (in an approved payroll) or cash repayments. recovered_amount is the sum of the live settlements,
 * kept under a row lock; a reversed settlement gives the amount back to the balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_advances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('advance_no', 30)->unique();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->decimal('installment_amount', 18, 2)->nullable();
            $table->decimal('recovered_amount', 18, 2)->default(0);
            $table->date('request_date');
            $table->string('reason', 500)->nullable();
            $table->string('status', 10)->default('pending');
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->date('disbursement_date')->nullable();
            $table->string('settlement_account', 30)->nullable();
            $table->string('accounting_reference', 100)->nullable();
            $table->string('reversal_accounting_reference', 100)->nullable();
            $table->unsignedBigInteger('disbursed_by')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('hr_advance_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_advance_id')->constrained('hr_employee_advances')->restrictOnDelete();
            $table->string('kind', 20);
            $table->decimal('amount', 18, 2);
            $table->date('settlement_date');
            $table->foreignId('payroll_item_id')->nullable()->constrained('hr_payroll_items')->restrictOnDelete();
            $table->string('settlement_account', 30)->nullable();
            $table->string('accounting_reference', 100)->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_accounting_reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['employee_advance_id', 'payroll_item_id'], 'hr_advance_settlements_item_unique');
        });

        Schema::table('hr_payroll_item_lines', function (Blueprint $table) {
            $table->foreign('employee_advance_id')->references('id')->on('hr_employee_advances')->restrictOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE hr_employee_advances ADD CONSTRAINT hr_employee_advances_check CHECK (amount > 0 AND (installment_amount IS NULL OR installment_amount > 0) AND recovered_amount >= 0 AND recovered_amount <= amount AND status IN ('pending','approved','rejected','disbursed','settled','cancelled'))");
            DB::statement("ALTER TABLE hr_advance_settlements ADD CONSTRAINT hr_advance_settlements_check CHECK (amount > 0 AND kind IN ('salary_deduction','cash_repayment'))");
        }
    }

    public function down(): void
    {
        Schema::table('hr_payroll_item_lines', function (Blueprint $table) {
            $table->dropForeign(['employee_advance_id']);
        });
        Schema::dropIfExists('hr_advance_settlements');
        Schema::dropIfExists('hr_employee_advances');
    }
};
