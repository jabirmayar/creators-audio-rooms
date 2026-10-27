<?php

use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an open invitation is for: speaking (null) or becoming a co-host.
 * Nobody takes either role until they accept.
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
            $table->string('invited_role', 20)->nullable()->after('invited_by_key');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::members(), function (Blueprint $table) {
            $table->dropColumn('invited_role');
        });
    }
};
