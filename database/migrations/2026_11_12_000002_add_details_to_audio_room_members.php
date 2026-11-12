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
            // A short line about the member for viewers the app allows (details_visible_to_types).
            $table->string('details', 191)->nullable()->after('profile_url');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::members(), function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
