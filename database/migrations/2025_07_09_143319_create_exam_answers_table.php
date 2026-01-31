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
        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->longText('text_answer')->nullable();

            $table->bigInteger('exam_student_id')->unsigned()->nullable();
            $table->foreign('exam_student_id')->references('id')->on('exam_student')->onDelete('cascade')->onUpdate('cascade');

            $table->bigInteger('exam_id')->unsigned()->nullable();
            $table->foreign('exam_id')->references('id')->on('exams')->onDelete('cascade')->onUpdate('cascade');

            $table->bigInteger('question_id')->unsigned()->nullable();
            $table->foreign('question_id')->references('id')->on('exam_questions')->onDelete('cascade')->onUpdate('cascade');

            $table->bigInteger('option_id')->unsigned()->nullable();
            $table->foreign('option_id')->references('id')->on('exam_question_options')->onDelete('cascade')->onUpdate('cascade');

            $table->boolean('is_correct')->nullable();
            $table->integer('points')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
    }
};
