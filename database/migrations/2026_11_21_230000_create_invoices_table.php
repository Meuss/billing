<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('number', 16)->nullable()->unique();
            $table->string('status')->default('draft');
            $table->text('note')->nullable();
            $table->string('discount_type')->nullable();
            $table->unsignedInteger('discount_value')->nullable();
            $table->date('sent_on')->nullable()->index();
            $table->date('due_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->date('cancelled_on')->nullable();
            $table->string('language', 2);
            $table->string('pdf_path')->nullable();
            $table->integer('total_cents')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
