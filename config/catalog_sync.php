<?php

return [
    'create_enabled' => env('CATALOG_SYNC_CREATE_ENABLED', true),
    'update_enabled' => env('CATALOG_SYNC_UPDATE_ENABLED', false),
    'sync_all_enabled' => env('CATALOG_SYNC_SYNC_ALL_ENABLED', false),
    'auto_enabled' => env('CATALOG_SYNC_AUTO_ENABLED', false),
];
