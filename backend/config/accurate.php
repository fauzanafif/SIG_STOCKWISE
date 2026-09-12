<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Accurate sync orchestration (docs/sync-architecture.md)
    |--------------------------------------------------------------------------
    | Laravel never connects to Firebird directly — it shells out to the
    | sync-service/ Python project (which holds the real Firebird credentials
    | in its own .env) to refresh the accurate_* staging tables, then does the
    | staging -> items upsert itself in PHP.
    */

    'sync_service_path' => env('ACCURATE_SYNC_SERVICE_PATH'),

    'python_bin' => env('ACCURATE_PYTHON_BIN', 'python'),

    'firebird_client_dir' => env('ACCURATE_FIREBIRD_CLIENT_DIR'),

    'process_timeout_seconds' => (int) env('ACCURATE_SYNC_TIMEOUT', 120),

];
