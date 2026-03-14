<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:ingestion' => 60,
        'redis:telemetry-side-effects' => 60,
        'redis:automation' => 60,
        'redis-simulations:simulations' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'simple',
            'processes' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        'supervisor-ingestion' => [
            'connection' => 'redis',
            'queue' => ['ingestion'],
            'balance' => 'simple',
            'processes' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],

        'supervisor-side-effects' => [
            'connection' => 'redis',
            'queue' => ['telemetry-side-effects'],
            'balance' => 'simple',
            'processes' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],

        'supervisor-automation' => [
            'connection' => 'redis',
            'queue' => ['automation'],
            'balance' => 'simple',
            'processes' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],

        'supervisor-simulations' => [
            'connection' => 'redis-simulations',
            'queue' => ['simulations'],
            'balance' => 'simple',
            'processes' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 240,
            'nice' => 0,
        ],
    ],

    'auto_balancing' => [
        'enabled' => (bool) env('HORIZON_AUTO_BALANCING_ENABLED', false),
        'strategy' => env('HORIZON_AUTO_SCALING_STRATEGY', 'time'),
        'balance_max_shift' => (int) env('HORIZON_AUTO_BALANCE_MAX_SHIFT', 1),
        'balance_cooldown' => (int) env('HORIZON_AUTO_BALANCE_COOLDOWN', 3),
        'supervisors' => [
            'default' => [
                'max_processes' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', env('HORIZON_DEFAULT_PROCESSES', 3)),
            ],
            'ingestion' => [
                'max_processes' => (int) env('HORIZON_INGESTION_MAX_PROCESSES', env('HORIZON_INGESTION_PROCESSES', 4)),
            ],
            'side_effects' => [
                'max_processes' => (int) env('HORIZON_SIDE_EFFECTS_MAX_PROCESSES', env('HORIZON_SIDE_EFFECTS_PROCESSES', 4)),
            ],
            'automation' => [
                'max_processes' => (int) env('HORIZON_AUTOMATION_MAX_PROCESSES', env('HORIZON_AUTOMATION_PROCESSES', 4)),
            ],
            'simulations' => [
                'max_processes' => (int) env('HORIZON_SIMULATION_MAX_PROCESSES', env('HORIZON_SIMULATION_PROCESSES', 4)),
            ],
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'processes' => (int) env('HORIZON_DEFAULT_PROCESSES', 10),
            ],

            'supervisor-ingestion' => [
                'processes' => (int) env('HORIZON_INGESTION_PROCESSES', 8),
            ],

            'supervisor-side-effects' => [
                'processes' => (int) env('HORIZON_SIDE_EFFECTS_PROCESSES', 8),
            ],

            'supervisor-automation' => [
                'processes' => (int) env('HORIZON_AUTOMATION_PROCESSES', 8),
            ],

            'supervisor-simulations' => [
                'processes' => (int) env('HORIZON_SIMULATION_PROCESSES', 8),
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'processes' => (int) env('HORIZON_DEFAULT_PROCESSES', 3),
            ],

            'supervisor-ingestion' => [
                'processes' => (int) env('HORIZON_INGESTION_PROCESSES', 4),
            ],

            'supervisor-side-effects' => [
                'processes' => (int) env('HORIZON_SIDE_EFFECTS_PROCESSES', 4),
            ],

            'supervisor-automation' => [
                'processes' => (int) env('HORIZON_AUTOMATION_PROCESSES', 4),
            ],

            'supervisor-simulations' => [
                'processes' => (int) env('HORIZON_SIMULATION_PROCESSES', 4),
            ],
        ],
    ],
];
