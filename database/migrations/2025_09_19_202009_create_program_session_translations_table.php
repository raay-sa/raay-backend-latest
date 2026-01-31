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
        Schema::create('program_session_translations', function (Blueprint $table) {
            $table->string('locale', 5);
            $table->string('title')->nullable();

            $table->bigInteger('parent_id')->unsigned();
            $table->foreign('parent_id')->references('id')->on('program_sessions')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_session_translations');
    }
};
