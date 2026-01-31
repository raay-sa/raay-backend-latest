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
        Schema::create('student_notification_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('live_program_noti')->default(0)->nullable();
            $table->boolean('certificate_noti')->default(0)->nullable();
            $table->boolean('offers_noti')->default(0)->nullable();
            $table->boolean('global_noti')->default(0)->nullable();

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
        Schema::dropIfExists('student_notification_settings');
    }
};
