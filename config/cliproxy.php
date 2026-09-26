<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The gateway
    |--------------------------------------------------------------------------
    |
    | Where CLIProxyAPI answers, without a trailing `/v1`, and the client key
    | it knows this application by (one of its `api-keys`). The management key
    | is optional: it unlocks `CliProxy::management()` and the quota columns of
    | `cliproxy:status`, and nothing else in this package needs it.
    |
    */

    'url' => env('CLIPROXY_URL', 'http://127.0.0.1:8317'),

    'key' => env('CLIPROXY_KEY'),

    'management_key' => env('CLIPROXY_MANAGEMENT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | In seconds. A long answer is written before the gateway sends anything on
    | a non-streaming call, so `timeout` bounds the slowest answer you expect,
    | not the network. `connect_timeout` is what makes a dead gateway fail fast
    | enough for a fallback to be worth having.
    |
    */

    'timeout' => (int) env('CLIPROXY_TIMEOUT', 600),

    'connect_timeout' => (int) env('CLIPROXY_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Readiness
    |--------------------------------------------------------------------------
    |
    | `CliProxy::serves($model)` asks the gateway's `/v1/models` and remembers
    | the answer for `ttl` seconds in the given cache store (null is the default
    | store), so a route decision costs nothing per call and a dead gateway is
    | asked once a minute rather than once a request.
    |
    */

    'readiness' => [
        'ttl' => (int) env('CLIPROXY_READINESS_TTL', 60),
        'timeout' => 3,
        'store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | The laravel/ai provider
    |--------------------------------------------------------------------------
    |
    | The name this package registers under `ai.providers` when you have not
    | declared it yourself. Its URL and key default to the ones above, read when
    | the provider is first resolved, so an application that overlays them at
    | boot still reaches the right gateway.
    |
    | Declare the entry yourself to choose its wire protocol and default models:
    |
    |   'cliproxy' => [
    |       'driver' => 'cliproxy',
    |       'protocol' => 'chat-completions', // or 'messages'
    |       'models' => ['text' => ['default' => 'my-alias'], 'image' => ['default' => 'my-art']],
    |   ],
    |
    | Both protocols reach every upstream the gateway serves; `messages` also
    | carries explicit prompt-cache breakpoints, document blocks and thinking.
    |
    */

    'provider' => 'cliproxy',

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | A route names a model on the gateway and, optionally, where to go when
    | the gateway is switched off or cannot serve it. `CliProxy::route('name')`
    | answers the laravel/ai provider map for it:
    |
    |   'routes' => [
    |       'chat' => [
    |           'enabled' => env('CLIPROXY_CHAT', false),
    |           'model' => 'my-alias',
    |           'fallback' => ['my-direct-provider' => 'its-model'],
    |       ],
    |   ],
    |
    */

    'routes' => [],

    /*
    |--------------------------------------------------------------------------
    | Batches
    |--------------------------------------------------------------------------
    |
    | CLIProxyAPI has no batch endpoint, so `CliProxy::batches()` keeps one row
    | per request and runs each as a queued call in `protocol` (each request's
    | params are a body for that endpoint). `timeout` must
    | stay under your queue connection's `retry_after`. A retryable failure
    | (connection, 429, 5xx) is tried up to `tries` times, waiting `backoff`
    | seconds between attempts; a batch older than `expires_after_hours` expires
    | whatever is left in it.
    |
    */

    'batches' => [
        'protocol' => env('CLIPROXY_BATCH_PROTOCOL', 'chat-completions'),
        'table' => 'cliproxy_batch_requests',
        'connection' => env('CLIPROXY_BATCH_CONNECTION'),
        'queue' => env('CLIPROXY_BATCH_QUEUE'),
        'timeout' => 600,
        'tries' => 5,
        'backoff' => [30, 120, 300, 900],
        'expires_after_hours' => 24,
        'prune_after_days' => 7,
    ],

];
