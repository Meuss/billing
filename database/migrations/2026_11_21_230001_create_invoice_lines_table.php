<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('task_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity_minutes')->nullable();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->integer('unit_price_cents');
            $table->unsignedInteger('position')->default(0);
            // Set when a sent invoice is cancelled: the line stays for history but no longer holds its Task/Expense.
            $table->boolean('released')->default(false);
            // A Task/Expense sits on at most one unreleased line; released lines keep their reference.
            $table->unsignedBigInteger('active_task_id')->nullable()->storedAs('if(released, null, task_id)')->unique();
            $table->unsignedBigInteger('active_expense_id')->nullable()->storedAs('if(released, null, expense_id)')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
