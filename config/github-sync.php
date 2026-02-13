<?php

return [

    'enabled' => env('GITHUB_SYNC_ENABLED', false),

    'repository' => env('GITHUB_SYNC_REPOSITORY', ''),

    'branch' => env('GITHUB_SYNC_BRANCH', 'main'),

    'token' => env('GITHUB_SYNC_TOKEN', ''),

    'dispatch_delay' => env('GITHUB_SYNC_DISPATCH_DELAY', 2),

    'queue_connection' => env('GITHUB_SYNC_QUEUE_CONNECTION'),

    'committer' => [
        'name' => env('GITHUB_SYNC_COMMITTER_NAME', 'Statamic CMS'),
        'email' => env('GITHUB_SYNC_COMMITTER_EMAIL', 'cms@example.com'),
    ],

    'use_authenticated' => true,

    'paths' => [
        'content',
        'resources/blueprints',
        'resources/fieldsets',
        'resources/forms',
        'resources/users',
    ],

    'ignored_events' => [],

];
