<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Excel source files
    |--------------------------------------------------------------------------
    | Folder containing the 9 company Excel files (see docs/excel-data-mapping.md).
    | Default: the repo root (one level above the Laravel app). Override with
    | STOCKWISE_IMPORT_PATH in .env, e.g. storage/app/private/imports.
    */

    'import_path' => env('STOCKWISE_IMPORT_PATH', dirname(base_path())),

    'files' => [
        'ppb_ri' => '1. PPB - RI.xlsx',
        'npbg' => '2. NPBG.xlsx',
        'borrow_lend' => '3. Tracking Borrow & Lend.xlsx',
        'stpp' => '4. Tracking STPP.xlsx',
        'ban_luar' => '5. Tracking Ban Luar.xlsx',
        'maintenance' => '6. Tracking Maintenance Assets.xlsx',
        'manufaktur' => '7. Tracking Manufaktur & Assembly.xlsx',
        'pengembalian_bekas' => '8. Tracking Pengembalian Bekas.xlsx',
        'master' => 'DATA.xlsx',
    ],

    /*
    |--------------------------------------------------------------------------
    | Calculation engine parameters (docs/calculation-engine.md)
    |--------------------------------------------------------------------------
    */

    'engine' => [
        'lead_time_threshold_fallback' => (int) env('STOCKWISE_LT_THRESHOLD_FALLBACK', 14),
        'reservation_expiry_days' => (int) env('STOCKWISE_RESERVATION_EXPIRY_DAYS', 14),
    ],

];
