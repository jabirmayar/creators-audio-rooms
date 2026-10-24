<?php

use daacreators\CreatorsAudioRooms\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * creators-audio-rooms v1 tables.
 *
 * Participants are stored as string keys ("user:5", "staff:abc") with no
 * foreign keys to host user tables. Transient signaling never touches these
 * tables; it lives in the cache.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::create(Tables::rooms(), function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('tenant_key', 191)->nullable()->index();
            // Opaque, host-defined audience (e.g. type "subject", ref "74").
            $table->string('audience_type', 64)->nullable();
            $table->string('audience_ref', 191)->nullable();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('scheduled')->index();
            $table->string('created_by_key', 191)->index();
            $table->unsignedSmallInteger('max_hosts');
            $table->unsignedSmallInteger('max_speakers');
            $table->unsignedSmallInteger('max_listeners');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            // Bumped on every state change; used for optimistic locking and client sync.
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index(['audience_type', 'audience_ref']);
            $table->index(['status', 'tenant_key']);
        });

        Schema::create(Tables::members(), function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('room_id')->constrained(Tables::rooms())->cascadeOnDelete();
            $table->string('participant_key', 191);
            // Snapshot for display after the account changes or is deleted; anonymised on request.
            $table->string('display_name', 191);
            $table->string('avatar_url', 2048)->nullable();
            $table->string('role', 16)->default('listener');
            $table->string('state', 16)->default('joined');
            $table->timestamp('hand_raised_at')->nullable();
            // A moderator mute can only be lifted by the participant themselves.
            $table->boolean('moderator_muted')->default(false);
            $table->boolean('self_muted')->default(true);
            // Current browser connection; a new one replaces the old on reconnect.
            $table->string('connection_id', 64)->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            // One row per person per room: rejoining reuses it.
            $table->unique(['room_id', 'participant_key']);
            $table->index(['room_id', 'state', 'role']);
            $table->index('participant_key');
        });

        Schema::create(Tables::bans(), function (Blueprint $table) {
            $table->id();
            // A ban applies to one room, or to every room of a tenant when room_id is null.
            $table->foreignUlid('room_id')->nullable()->constrained(Tables::rooms())->cascadeOnDelete();
            $table->string('tenant_key', 191)->nullable();
            $table->string('participant_key', 191);
            $table->string('reason', 255)->nullable();
            $table->string('banned_by_key', 191);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['participant_key', 'room_id']);
            $table->index(['participant_key', 'tenant_key']);
        });

        // Moderation decisions only (approve, demote, mute, remove, ban, end), never audio events.
        Schema::create(Tables::moderationLog(), function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('room_id')->constrained(Tables::rooms())->cascadeOnDelete();
            $table->string('actor_key', 191);
            $table->string('target_key', 191)->nullable();
            $table->string('action', 32);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::moderationLog());
        Schema::dropIfExists(Tables::bans());
        Schema::dropIfExists(Tables::members());
        Schema::dropIfExists(Tables::rooms());
    }
};
