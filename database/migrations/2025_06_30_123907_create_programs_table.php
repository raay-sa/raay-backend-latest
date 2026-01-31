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
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->nullable();
            $table->string('image')->nullable();
            $table->double('price')->nullable();
            $table->string('level')->nullable();
            $table->enum('type', ['registered', 'live'])->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->integer('duration')->nullable();
            $table->time('time')->nullable();
            $table->boolean('is_live')->default(false)->nullable();
            $table->tinyInteger('have_certificate')->default(0);
            $table->tinyInteger('status')->default(0);
            $table->tinyInteger('is_approved')->default(0);
            $table->string('user_type')->default('student')->nullable();

            $table->bigInteger('category_id')->unsigned()->nullable();
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade')->onUpdate('cascade');

            $table->bigInteger('teacher_id')->unsigned()->nullable();
            $table->foreign('teacher_id')->references('id')->on('teachers')->onDelete('cascade')->onUpdate('cascade');

            $table->softDeletes(); // يضيف عمود deleted_at
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
