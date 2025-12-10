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
        Schema::create('flash_payments', function (Blueprint $table) {
            $table->id();
            $table->string('mch_order_no')->unique()->nullable(false);
            $table->string('pay_order_id')->nullable()->index();
            $table->string('mch_no')->nullable();
            $table->string('app_id')->nullable();
            $table->string('way_code')->nullable();
            $table->integer('amount')->default(0); // in cents
            $table->string('currency')->nullable();
            $table->string('status')->default('created'); // created, paying, success, failed, closed
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flash_payments');
    }
};
