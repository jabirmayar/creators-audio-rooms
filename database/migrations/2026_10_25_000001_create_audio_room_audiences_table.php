<?php

use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a room is for: one row per chosen audience (a class, a group, a team…).
 * A room without rows is open to everyone allowed to discover rooms.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::create(Tables::audiences(), function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('room_id')->constrained(Tables::rooms())->cascadeOnDelete();
            $table->string('audience_type', 64);
            $table->string('audience_ref', 191);
            // Label at the time the room was created, so the room still reads well if names change.
            $table->string('label', 191)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['room_id', 'audience_type', 'audience_ref']);
            $table->index(['audience_type', 'audience_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::audiences());
    }
};
