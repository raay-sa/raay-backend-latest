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
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('image')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('program_id')->nullable()->constrained('programs')->onDelete('set null');
            $table->integer('min_students')->default(5);
            $table->integer('max_students')->nullable();
            $table->enum('status', ['active', 'inactive', 'linked'])->default('active');
            $table->timestamp('linked_at')->nullable(); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
