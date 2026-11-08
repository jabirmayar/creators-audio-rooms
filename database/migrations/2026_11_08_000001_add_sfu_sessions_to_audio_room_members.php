<?php

use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::table(Tables::members(), function (Blueprint $table) {
            $table->string('sfu_publisher_session_id', 128)->nullable();
            $table->string('sfu_publisher_mid', 16)->nullable();
            $table->boolean('sfu_publisher_ready')->default(false);
            $table->string('sfu_receiver_session_id', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(Tables::members(), function (Blueprint $table) {
            $table->dropColumn(['sfu_publisher_session_id', 'sfu_publisher_mid', 'sfu_publisher_ready', 'sfu_receiver_session_id']);
        });
    }
};
