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
        Schema::create('program_sessions', function (Blueprint $table) {
            $table->id();
            // $table->string('title')->nullable();
            $table->enum('type', ['registered', 'live'])->nullable();
            $table->string('url')->nullable();
            $table->text('video')->nullable();
            $table->string('video_duration')->nullable();
            $table->json('files')->nullable();
            $table->string('sort')->default(1)->nullable(); // ترتيب الجلسات عشان يكون لكل جلسه رقم حتي لو دخلت في سكشن تاني

            $table->bigInteger('section_id')->unsigned()->nullable();
            $table->foreign('section_id')->references('id')->on('program_sections')->onDelete('cascade')->onUpdate('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('program_section_sessions');
    }
};
