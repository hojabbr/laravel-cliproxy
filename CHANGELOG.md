# Changelog

Semantic Versioning. Below `1.0.0` a breaking change lands in a new minor, so pin with `^0.1` and read
this file before moving between minors.

## Unreleased

## 0.1.0 - 2026-09-26

### Added

- `cliproxy` laravel/ai provider: text over `chat-completions` (default) or `messages`, image generation
  over `chat/completions` with the image modality, documents sent as `file` parts on `chat-completions`,
  and a gateway 500 treated as a failover.
- `Ai\Concerns\CachesTrailingMessage`: a prompt-cache breakpoint that follows an agent loop on the
  `messages` protocol, opted into through provider options.
- Routing: `CliProxy::route()` and `CliProxy::routeTo()` answer a laravel/ai provider map, gateway first
  when it is switched on and serves the model, then the fallback.
- `Readiness`: `/v1/models`, cached; `CliProxy::isUp()`, `serves()`, `models()`.
- `Management`: client keys, credentials, quota providers and windows, config.
- Batches: create, retrieve, results, cancel and list, run as queued calls with retry, backoff, expiry,
  events and pruning.
- `cliproxy:status` and `cliproxy:probe`.
- `CliProxy::fake()`.
