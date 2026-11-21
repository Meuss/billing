<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('supplier');
            $table->string('description');
            $table->unsignedInteger('cost_cents');
            $table->unsignedInteger('billed_amount_cents');
            $table->string('original_amount_note')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('status')->default('unbilled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
