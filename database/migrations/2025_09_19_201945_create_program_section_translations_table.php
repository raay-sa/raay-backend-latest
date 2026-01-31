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
        Schema::create('program_section_translations', function (Blueprint $table) {
            $table->string('locale', 5);
            $table->string('title')->nullable();

            $table->bigInteger('parent_id')->unsigned();
            $table->foreign('parent_id')->references('id')->on('program_sections')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_section_translations');
    }
};
