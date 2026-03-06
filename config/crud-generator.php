<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace where your Eloquent models live.
    |
    */
    'model_namespace' => 'App\\Models',

    /*
    |--------------------------------------------------------------------------
    | Controller Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace for generated API controllers.
    |
    */
    'controller_namespace' => 'App\\Http\\Controllers\\Api',

    /*
    |--------------------------------------------------------------------------
    | Request Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace for generated form request classes.
    |
    */
    'request_namespace' => 'App\\Http\\Requests\\Api',

    /*
    |--------------------------------------------------------------------------
    | Resource Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace for generated API resource classes.
    |
    */
    'resource_namespace' => 'App\\Http\\Resources\\Api',

    /*
    |--------------------------------------------------------------------------
    | Repository Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace for generated repository classes.
    |
    */
    'repository_namespace' => 'App\\Repositories',

    /*
    |--------------------------------------------------------------------------
    | DTO Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace for generated DTO classes (Spatie Laravel Data).
    |
    */
    'dto_namespace' => 'App\\DTO',

    /*
    |--------------------------------------------------------------------------
    | Route File
    |--------------------------------------------------------------------------
    |
    | The path to the API routes file where new routes will be appended.
    |
    */
    'route_file' => 'routes/api.php',

    /*
    |--------------------------------------------------------------------------
    | Service Provider Path
    |--------------------------------------------------------------------------
    |
    | The path to the AppServiceProvider where repository bindings are added.
    |
    */
    'service_provider' => 'app/Providers/AppServiceProvider.php',

    /*
    |--------------------------------------------------------------------------
    | Excluded Columns
    |--------------------------------------------------------------------------
    |
    | Columns that should be excluded from generated code (validation, resource, DTO).
    |
    */
    'excluded_columns' => [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
        'email_verified_at',
    ],

    /*
    |--------------------------------------------------------------------------
    | Use Spatie Query Builder
    |--------------------------------------------------------------------------
    |
    | Whether to generate controllers using Spatie Query Builder.
    | If false, controllers will use standard Eloquent queries.
    |
    */
    'use_query_builder' => true,

    /*
    |--------------------------------------------------------------------------
    | Use Spatie JSON API Paginate
    |--------------------------------------------------------------------------
    |
    | Whether to use jsonPaginate() from Spatie JSON API Paginate.
    | If false, controllers will use standard paginate().
    |
    */
    'use_json_api_paginate' => true,

    /*
    |--------------------------------------------------------------------------
    | Use Spatie Laravel Data for DTOs
    |--------------------------------------------------------------------------
    |
    | Whether to generate DTOs extending Spatie\LaravelData\Data.
    | If false, DTOs will be plain PHP classes.
    |
    */
    'use_spatie_data' => true,

];
