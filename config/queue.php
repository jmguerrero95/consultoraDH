<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // Must stay above the longest job timeout (IMPORT_TIMEOUT, 600 s)
            // plus the worker's shutdown margin. Otherwise the queue releases
            // a job for a second worker while the first one is still running.
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 900),
            'after_commit' => (bool) env('QUEUE_AFTER_COMMIT', true),
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // 600 s import job + 300 s margin. A value below the import
            // timeout makes Redis hand the same job to another worker while
            // the first one is still parsing the workbook.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 900),
            'block_for' => null,
            // Never make a row/job visible to a worker before the surrounding
            // database transaction commits. Without this a worker can pop an
            // automation, import or report job and read state the request has
            // not committed yet.
            'after_commit' => (bool) env('QUEUE_AFTER_COMMIT', true),
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Monitoring
    |--------------------------------------------------------------------------
    |
    | `php artisan queue:health` reports pending, delayed and reserved jobs plus
    | the age of the oldest pending job for every queue listed here. A monitor
    | can run it on a schedule and alert on a non-zero exit code with --strict.
    |
    */

    'monitoring' => [
        'queues' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'QUEUE_MONITORED_QUEUES',
                'default,imports,support,notifications,automations,ai-tasks',
            )),
        ))),
        'max_backlog' => (int) env('QUEUE_MAX_BACKLOG', 1000),
        'max_age_seconds' => (int) env('QUEUE_MAX_AGE_SECONDS', 300),
        'max_failed' => (int) env('QUEUE_MAX_FAILED', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
