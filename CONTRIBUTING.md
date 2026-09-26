# Contributing

Issues and pull requests are welcome.

- Open an issue first for anything larger than a fix, so the approach can be agreed before the work.
- Keep the package vendor-neutral: it speaks the gateway's wire protocols, never an upstream's, and it
  ships no vendor SDK or vendor default.
- Every change carries its test. Run the unit suite, static analysis and the formatter before you push:

```bash
composer test
composer analyse
composer format
```

- The live suite runs the package against a real CLIProxyAPI with a stand-in upstream, so it needs no
  vendor key:

```bash
docker run -d --name cliproxy -p 8317:8317 --add-host=host.docker.internal:host-gateway \
  -v "$PWD/docs/deploy/config.yaml:/CLIProxyAPI/config.yaml" eceasy/cli-proxy-api:latest
CLIPROXY_LIVE_URL=http://127.0.0.1:8317 CLIPROXY_LIVE_KEY=change-me-client-key \
  CLIPROXY_LIVE_MANAGEMENT_KEY=change-me-management-key composer test:live
```

- Add a line under `## Unreleased` in `CHANGELOG.md` saying what changed for someone using the package.
