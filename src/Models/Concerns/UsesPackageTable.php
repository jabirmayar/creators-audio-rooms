<?php

namespace daacreators\CreatorsAudioRooms\Models\Concerns;

use daacreators\CreatorsAudioRooms\Support\Tables;

/** Resolves the table name and connection from package config at runtime. */
trait UsesPackageTable
{
    abstract protected static function packageTable(): string;

    public function getTable(): string
    {
        return static::packageTable();
    }

    public function getConnectionName(): ?string
    {
        return Tables::connection() ?? parent::getConnectionName();
    }
}
