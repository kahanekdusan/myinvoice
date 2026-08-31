# Local development with Docker

The `development` branch uses exactly one local stack:
`ops/docker/compose.local.yaml`, always operated through the wrappers below.
It is intentionally separate from the srv01 production contract and from the
backward-compatible general installation Compose in the repository root.

The stack is safe by default:

- app and MariaDB listen only on `127.0.0.1`;
- the canonical application URL is derived from the same `APP_PORT` used by
  Docker, so login/logout Origin checks cannot drift;
- project, network and database identities use the `myinvoice_dev` namespace;
  volume names additionally contain a random local generation ID;
- built-in cron is disabled and SMTP/Graph delivery is forced to a closed local
  sink;
- app and database have healthchecks;
- passwords and application keys live only in the gitignored
  `.docker-local/development/runtime.env`.
- each local data volume has a random installation-specific name and identity;
  the bootstrap refuses a pre-existing volume with another or missing label.
- protected host environment variables are removed before Compose runs, so a
  production shell environment cannot override the generated local values.

Do not import production database dumps, `/data`, `cfg.php`, OAuth credentials
or customer documents. Development uses only local synthetic/test data.

## macOS or Linux

```bash
cmd/docker-local.sh init
cmd/docker-local.sh up
cmd/docker-local.sh status
cmd/docker-local.sh down
```

## Windows

Both Windows PowerShell 5.1 and PowerShell 7 use the same contract:

```powershell
.\cmd\docker-local.ps1 -Action init
.\cmd\docker-local.ps1 -Action up
.\cmd\docker-local.ps1 -Action status
.\cmd\docker-local.ps1 -Action down
```

`down` never passes `-v`; local volumes remain preserved. To change ports, stop
the stack and edit only `APP_PORT` or `DB_PORT` in the runtime file. The app URL
updates automatically from `APP_PORT` during the next Compose render.

Do not run the local Compose file directly. The wrappers enforce secret-path,
host-environment and volume-identity checks which raw `docker compose` skips.

If the wrapper reports an untrusted volume, stop. Do not delete or relabel that
volume: it may contain data from an older installation. Inspect only its labels
with `docker volume inspect <name>` and resolve it through a reviewed
export/import procedure. A newly generated runtime identity always uses new,
empty local-development volumes and leaves older volumes untouched.

The example file `ops/docker/local.env.example` documents key names only. Never
copy real secrets, production values or the generated runtime file into Git.
