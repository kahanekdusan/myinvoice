# Fork operations

## Branches

- `master` mirrors `radekhulan/myinvoice:master`; custom commits do not belong
  there.
- `development` contains reviewed local development changes.
- `production` is updated only by a reviewed Pull Request with green required
  checks. A merge builds an immutable GHCR image on a GitHub-hosted runner.

Source moves between the desktop and Mac only through Git feature branches.
Runtime env files, cfg files, Docker volumes, database dumps and customer data
are never synchronized through Git.

## Local Docker development

The only supported local stack is `ops/docker/compose.local.yaml`, operated by
`cmd/docker-local.sh` on macOS/Linux or `cmd/docker-local.ps1` on Windows. See
`docs/LOCAL_DEVELOPMENT.md` for commands and guarantees.

Local development uses only synthetic/test data. It never mounts or imports the
production database, production `/data`, production cfg, Graph/OAuth secrets or
customer documents. The app and DB bind to loopback, cron is disabled and mail
is forced to a closed local sink.

Except for the explicit `ops/docker/compose.local.yaml` contract, historical
Windows/NUC files under `ops/docker/`, `ops/deploy/` and `ops/gateway/` are not
local-development or srv01 templates. Do not run them.

## Production

Production runs only on srv01. No source build and no long-lived development
slot runs there. Changes follow this path:

```text
feature branch -> Pull Request -> green CI -> production merge
-> immutable private GHCR digest -> restricted srv01 deployment gate
```

Direct edits, direct `docker compose up`, direct pushes to `production`, the
old NUC watcher and a self-hosted GitHub runner are outside the supported
production process. Runtime secrets remain root-owned on srv01 and outside Git.
The NUC remains an unchanged rollback source until a separate explicit decision.
