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
        Schema::create('admin_notification_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('create_account_noti')->default(0)->nullable();
            $table->boolean('create_new_program_noti')->default(0)->nullable();
            $table->boolean('receiving_review_noti')->default(0)->nullable();
            $table->boolean('offers_noti')->default(0)->nullable();
            $table->boolean('global_noti')->default(0)->nullable();

            $table->bigInteger('admin_id')->unsigned()->nullable();
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete('cascade')->onUpdate('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_notification_settings');
    }
};
