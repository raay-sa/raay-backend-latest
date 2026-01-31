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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_id')->uniqid()->nullable();
            $table->string('transaction_id')->uniqid()->nullable();
            $table->string('payment_id')->uniqid()->nullable();
            $table->string('merchant_id')->uniqid()->nullable();
            $table->string('holder_name')->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->string('currency')->default('SAR');
            $table->string('payment_brand')->nullable();

            $table->string('status')->nullable();
            $table->string('result_status')->nullable();
            $table->string('result_code')->nullable();
            $table->text('result_message')->nullable();

            $table->string('capture_status')->nullable();
            $table->string('capture_code')->nullable();
            $table->text('capture_message')->nullable();

            $table->string('reverse_status')->nullable();
            $table->string('reverse_code')->nullable();
            $table->text('reverse_message')->nullable();

            $table->json('reference')->nullable();

            $table->bigInteger('student_id')->unsigned()->nullable();
            $table->foreign('student_id')->references('id')->on('students')->onDelete('cascade')->onUpdate('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
