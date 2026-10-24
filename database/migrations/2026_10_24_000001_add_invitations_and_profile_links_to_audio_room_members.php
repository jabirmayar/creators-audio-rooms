<?php

use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to speak (a listener accepts or declines) and profile links
 * shown on participant cards.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::table(Tables::members(), function (Blueprint $table) {
            $table->timestamp('invited_to_speak_at')->nullable()->after('hand_raised_at');
            $table->string('invited_by_key', 191)->nullable()->after('invited_to_speak_at');
            $table->string('profile_url', 2048)->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::members(), function (Blueprint $table) {
            $table->dropColumn(['invited_to_speak_at', 'invited_by_key', 'profile_url']);
        });
    }
};
