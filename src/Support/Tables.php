<?php

namespace daacreators\CreatorsAudioRooms\Support;

/** Package table names, built from the configured prefix. */
final class Tables
{
    public static function name(string $table): string
    {
        return (string) config('creators-audio-rooms.table_prefix', 'audio_').$table;
    }

    public static function rooms(): string
    {
        return self::name('rooms');
    }

    public static function members(): string
    {
        return self::name('room_members');
    }

    public static function bans(): string
    {
        return self::name('room_bans');
    }

    public static function moderationLog(): string
    {
        return self::name('room_moderation_log');
    }

    public static function audiences(): string
    {
        return self::name('room_audiences');
    }

    public static function connection(): ?string
    {
        return config('creators-audio-rooms.database_connection') ?: null;
    }
}
