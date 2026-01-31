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
        Schema::table('programs', function (Blueprint $table) {
            // Update the type enum to include onsite
            $table->enum('type', ['registered', 'live', 'onsite'])->nullable()->change();
            
            // Add new columns for onsite programs
            $table->text('address')->nullable()->after('type');
            $table->string('url')->nullable()->after('address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn(['address', 'url']);
            
            // Revert the type enum to original values
            $table->enum('type', ['registered', 'live'])->nullable()->change();
        });
    }
};
