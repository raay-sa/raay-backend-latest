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
        Schema::create('program_translations', function (Blueprint $table) {
            $table->string('locale', 5);
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('learning')->nullable()->comment('ما سيتعمله في هذا البرنامج');
            $table->json('requirement')->nullable()->comment('متظلبات البرنامج');

            $table->bigInteger('parent_id')->unsigned();
            $table->foreign('parent_id')->references('id')->on('programs')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_translations');
    }
};
