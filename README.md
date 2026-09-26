# laravel-cliproxy

[CLIProxyAPI](https://github.com/router-for-me/CLIProxyAPI) for Laravel.

CLIProxyAPI is a self-hosted AI gateway. It serves the common API formats on one endpoint and
routes each model to whatever you configure behind it: API keys, OpenAI-compatible providers, and
the logins its panel supports, with pools, cooldowns and fallbacks. This package makes that gateway
a first-class citizen of a Laravel application:

- **A [laravel/ai](https://github.com/laravel/ai) provider**, `cliproxy`, for text (prompts,
  streaming, tools, structured output, image and document attachments) and image generation.
- **Routing with a fallback**: send a call through the gateway when it is switched on and serves
  the model, and to any other laravel/ai provider when it is not.
- **Readiness**: whether the gateway answers and which models it serves, cached.
- **The management API**: client keys, credentials, quota windows and config.
- **Batches over a gateway that has none**: create, poll, collect and cancel, run as queued calls.
- **`cliproxy:status` and `cliproxy:probe`**: see what the gateway serves, and prove what a model
  behind it can actually do before you point work at it.

The package is vendor-neutral. It speaks the gateway's wire protocols, never an upstream's; which
vendor serves a model is decided in your gateway, not here.

## Requirements

- PHP 8.3 or newer, Laravel 12 or 13, laravel/ai 1.x
- A running CLIProxyAPI (see [Running a gateway](#running-a-gateway)). Supported and tested:
  v7.3.x.

## Install

```bash
composer require hojabbr/laravel-cliproxy
```

```dotenv
CLIPROXY_URL=http://127.0.0.1:8317
CLIPROXY_KEY=one-of-the-gateway-api-keys
# Optional: unlocks CliProxy::management() and the credential table of cliproxy:status
CLIPROXY_MANAGEMENT_KEY=the-gateway-management-key
```

Publish the config when you want to change a default, and the migration when you use batches:

```bash
php artisan vendor:publish --tag=cliproxy-config
php artisan vendor:publish --tag=cliproxy-migrations
php artisan migrate
```

## Text and images through laravel/ai

The package registers a `cliproxy` entry under `ai.providers` when you have not declared one. Its
URL and key come from `cliproxy.url` and `cliproxy.key` when the provider is first resolved, so
values your application sets during boot are honoured.

```php
use App\Ai\Agents\SupportAgent;

$response = (new SupportAgent)->prompt('Summarise this ticket.', provider: 'cliproxy', model: 'my-alias');
```

Model names are whatever your gateway serves (often an alias you defined in its panel). To give
the provider defaults, and to pick its wire protocol, declare the entry yourself in `config/ai.php`:

```php
'providers' => [
    'cliproxy' => [
        'driver' => 'cliproxy',
        'protocol' => 'chat-completions', // or 'messages'
        'models' => [
            'text' => ['default' => 'my-alias', 'cheapest' => 'my-fast-alias'],
            'image' => ['default' => 'my-art-alias'],
        ],
    ],
],
```

### Choosing a protocol

The gateway accepts both and translates either to the upstream that serves the model.

| | `chat-completions` (default) | `messages` |
| --- | --- | --- |
| Endpoint | `/v1/chat/completions` | `/v1/messages` |
| Text, streaming, tools, images | yes | yes |
| Structured output | yes | depends on the upstream (see below) |
| Documents (PDF) | as `file` parts | as document blocks, depends on the upstream |
| Explicit prompt-cache breakpoints | no (upstreams that cache do so on the prefix) | yes, including `#[CacheInstructions]` and the moving breakpoint below |

**Known gateway limit (CLIProxyAPI v7.3.18).** A `messages` request bound for an
*openai-compatibility* upstream loses its document blocks and its structured-output format without
an error; text, images, tools and streaming survive. `chat-completions` carries both to every
upstream. Run `cliproxy:probe` for each model and protocol you depend on; the package's scheduled
live suite tracks this against the newest gateway.

### A cache breakpoint that follows the conversation

On the `messages` protocol, an agent loop re-sends the whole conversation every step. Ask for a
breakpoint on the last message and each step's cache becomes a prefix of the next:

```php
use Hojabbr\CliProxy\Ai\Gateways\MessagesGateway;

public function providerOptions(Lab|string $provider): array
{
    return [MessagesGateway::CACHE_TRAILING_MESSAGE => true];
}
```

The option never reaches the wire. The trait behind it, `Ai\Concerns\CachesTrailingMessage`, works
on any subclass of laravel/ai's gateway for this format.

### Images

```php
use Laravel\Ai\Ai;

$image = Ai::imageProvider('cliproxy')->image('A lighthouse at dusk', model: 'my-art-alias', size: '16:9', quality: 'high');
```

Images use `chat/completions` with the image modality (the OpenRouter convention), which the
gateway forwards to an `openai-compatibility` upstream flagged `image: true`.

### Failover

A gateway failure that another provider could answer (no connection, 429, 500, 502, 503, 504)
raises laravel/ai's failover exceptions, so a provider map moves on:

```php
$agent->prompt($question, provider: ['cliproxy' => 'my-alias', 'backup' => 'its-model']); // any laravel/ai provider
```

The gateway answers 500 when every credential in a pool failed, so this package treats 500 as a
failover where laravel/ai's own gateways do not.

## Routing: gateway first, fallback of your choice

A route decides, per call, whether the gateway takes it: only when the route is **on** and the
gateway currently **serves** the model. Otherwise the call goes to the fallback.

```php
// config/cliproxy.php
'routes' => [
    'chat' => [
        'enabled' => env('CLIPROXY_CHAT', false),
        'model' => 'my-alias',
        'fallback' => ['backup' => 'its-model'], // any laravel/ai provider
    ],
],
```

```php
use Hojabbr\CliProxy\Facades\CliProxy;

$route = CliProxy::route('chat');
$agent->prompt($question, provider: $route->providers());

// Or ad hoc, with a switch your application owns:
$route = CliProxy::routeTo('my-alias', ['backup' => 'its-model'], enabled: $settings->gatewayOn);
$route->usesGateway(); // bool
```

A route that is on for a model the gateway does not list, with no fallback, still goes to the
gateway, so you see the gateway's own error. A route that is off with no fallback throws
`RouteUnavailable`.

## Readiness

```php
CliProxy::isUp();              // does the gateway answer?
CliProxy::serves('my-alias');  // does it list this model?
CliProxy::models();            // everything it lists
CliProxy::readiness()->forget();
```

The answer comes from `/v1/models` and is cached for `cliproxy.readiness.ttl` seconds (60), so a
route decision costs a cache read and a dead gateway is asked once a minute.

## The management API

```php
$management = CliProxy::management();

$management->apiKeys();              // client keys the gateway accepts
$management->setApiKeys([...]);     // replace them
$management->credentials();          // upstream credentials and their status
$management->quotaProviders();
$management->quota($authIndex);      // one credential's quota windows, fetched now
$management->config();
$management->request()->get('...');  // anything else under /v0/management
```

Answers are arrays exactly as the gateway sends them: this API moves with CLIProxyAPI's releases.

## Batches

CLIProxyAPI has no batch endpoint. `CliProxy::batches()` keeps one row per request and runs each as
a queued call in `cliproxy.batches.protocol`, with the lifecycle you know from vendor batch APIs:

```php
$batch = CliProxy::batches()->create([
    ['custom_id' => 'ticket-1', 'params' => ['model' => 'my-alias', 'messages' => [['role' => 'user', 'content' => '...']]]],
    ['custom_id' => 'ticket-2', 'params' => ['model' => 'my-alias', 'messages' => [['role' => 'user', 'content' => '...']]]],
]);

$status = CliProxy::batches()->retrieve($batch->id);  // processingStatus, requestCounts
foreach (CliProxy::batches()->results($batch->id) as $result) {
    $result->customId; $result->type; $result->text(); $result->message; $result->error;
}
CliProxy::batches()->cancel($batch->id);
CliProxy::batches()->list();
```

- A retryable failure (no connection, 408, 409, 429, 5xx) goes back on the queue after
  `backoff`, up to `tries`; anything else is stored as `errored` with its type and message.
- A request its batch outlived (`expires_after_hours`) expires; `cancel()` stops what has not
  started, and a running request finishes and keeps its answer.
- `BatchRequestFinished` fires per request, `BatchEnded` once per batch.
- Finished rows are prunable: schedule `model:prune --model="Hojabbr\CliProxy\Batches\BatchRequest"`.
- `cliproxy.batches.timeout` must stay under your queue connection's `retry_after`. Throughput is
  your workers': size the queue named in `cliproxy.batches.queue` for the gateway's limits.

There is no batch discount to be had through a gateway: a batch costs what its calls cost.

## Commands

```bash
php artisan cliproxy:status            # reachability, models, and credentials with a management key
php artisan cliproxy:probe my-alias    # one real call per feature, pass or fail, with timings
php artisan cliproxy:probe my-alias --image-model=my-art-alias --long --json
```

The probe checks listing, text, streaming, tools, structured output, image input, PDF input and
caching (advisory), and optionally a long answer and image generation. It exits non-zero when a
required check fails, so it can gate a deploy.

## Testing your application

```php
use Hojabbr\CliProxy\Facades\CliProxy;

$gateway = CliProxy::fake(['my-alias']);   // readiness from a fixed list
$gateway->down();                          // ...then watch your routes fall back
```

Fake the calls themselves with `Http::fake()` or laravel/ai's own fakes.

## Running a gateway

`docs/deploy/` has two examples with the recommended first config:

- `compose.yaml`: CLIProxyAPI next to your application, config and login tokens in Postgres.
- `kubernetes.yaml`: one replica (login tokens refresh in place and must not race), non-root, read-only root filesystem.

With `PGSTORE_DSN` set, CLIProxyAPI copies `config.example.yaml` into Postgres only while its config
table is empty, so mount your seed at that path: from the first boot on, the panel owns the config
and a restart never overwrites an edit.

The recommended seed sets `disable-claude-cloak-mode: true`, so client traffic is never presented
as another client. Check your upstreams' terms before connecting a subscription login.

## Versioning

Semantic Versioning. Below 1.0.0 a breaking change lands in a new minor, so pin with `^0.1` and read
[CHANGELOG.md](CHANGELOG.md) before moving between minors.

## License

MIT. See [LICENSE.md](LICENSE.md).
