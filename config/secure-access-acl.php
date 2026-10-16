<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Access Control List
    |--------------------------------------------------------------------------
    |
    | Enable database-managed access control for groups and permissions.
    | When enabled, permissions are resolved from the database at runtime.
    | Groups and permissions are initialized via database migration and seeding.
    |
    */

    'enabled' => env('SECURE_ACCESS_ACL_ENABLED', true),

];
