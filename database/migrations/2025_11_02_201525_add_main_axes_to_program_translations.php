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
        Schema::table('program_translations', function (Blueprint $table) {
            $table->json('main_axes')->nullable()->after('requirement')->comment('المحاور الرئيسية في البرنامج');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('program_translations', function (Blueprint $table) {
            $table->dropColumn('main_axes');
        });
    }
};
