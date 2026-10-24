<?php

namespace daacreators\CreatorsAudioRooms\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

class Admin extends Authenticatable
{
    protected $table = 'admins';

    protected $guarded = [];
}
