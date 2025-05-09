<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your closure based console
| commands and also register your scheduled tasks. Commands defined
| in this file will be registered with the console kernel.
|
*/

// Generate Redis traffic every minute
// Default: 5MB write, 1MB read
Schedule::command('redis:generate-traffic --kb-write=5000 --kb-read=1000')
    ->everyMinute();
