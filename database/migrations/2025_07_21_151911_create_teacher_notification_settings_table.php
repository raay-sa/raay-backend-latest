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
        Schema::create('teacher_notification_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('receiving_review_noti')->default(0)->nullable(); //  إشعار عند الحصول على تقييم
            $table->boolean('receiving_assignments_noti')->default(0)->nullable(); // استلام مهام واختبارات
            $table->boolean('global_noti')->default(0)->nullable(); // إشعارات عامة

            $table->bigInteger('teacher_id')->unsigned()->nullable();
            $table->foreign('teacher_id')->references('id')->on('teachers')->onDelete('cascade')->onUpdate('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_notification_settings');
    }
};
