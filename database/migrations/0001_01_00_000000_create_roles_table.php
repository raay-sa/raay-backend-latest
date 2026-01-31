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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['admin', 'user'])->default('user');

            $table->enum('role_show', ['on', 'off'])->default('off')->nullable();
            $table->enum('role_create', ['on', 'off'])->default('off')->nullable();
            $table->enum('role_edit', ['on', 'off'])->default('off')->nullable();
            $table->enum('role_delete', ['on', 'off'])->default('off')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
