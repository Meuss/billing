<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('postal_code', 20);
            $table->string('city');
            $table->char('country', 2)->default('CH');
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();
            $table->string('language', 2)->default('fr');
            $table->text('notes')->nullable();
            $table->string('invoice_ninja_id')->nullable()->unique();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
