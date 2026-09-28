<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => DB::table('idempotency_keys')->where('expires_at', '<', now())->delete())
    ->name('prune-idempotency-keys')
    ->hourly()
    ->onOneServer();

Schedule::command('queue:prune-batches --hours=72 --unfinished=72')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
