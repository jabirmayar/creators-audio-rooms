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
        Schema::table(Tables::rooms(), function (Blueprint $table) {
            // Set when the room starts under lifecycle.max_room_minutes; null means no limit.
            $table->unsignedSmallInteger('time_limit_minutes')->nullable()->after('ended_at');
            $table->timestamp('ends_at')->nullable()->after('time_limit_minutes');
            $table->timestamp('extended_at')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::rooms(), function (Blueprint $table) {
            $table->dropColumn(['time_limit_minutes', 'ends_at', 'extended_at']);
        });
    }
};
