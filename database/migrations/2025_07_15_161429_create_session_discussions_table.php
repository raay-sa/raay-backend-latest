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
        Schema::create('session_discussions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->tinyInteger('sender_id')->nullable();
            $table->string('sender_type')->comment('student | teacher')->nullable(); // student | teacher
            $table->tinyInteger('parent_id')->comment('لو رد على رسالة تانية من نفس الجدول')->nullable();

            $table->bigInteger('program_id')->unsigned()->nullable();
            $table->foreign('program_id')->references('id')->on('programs')->onDelete('cascade')->onUpdate('cascade');

            $table->bigInteger('session_id')->unsigned()->nullable();
            $table->foreign('session_id')->references('id')->on('program_sessions')->onDelete('cascade')->onUpdate('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_discussions');
    }
};
