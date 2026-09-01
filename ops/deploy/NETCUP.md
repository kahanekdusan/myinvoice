# netcup production delivery

The `production` branch is the only production release source. A push or a
manual dispatch on that branch builds an immutable GHCR image through
`build-netcup-production.yml`. srv01 always consumes the digest emitted by that
build, never the mutable `production` tag.

Delivery runs automatically after every successful push to `production`:

1. `deploy-candidate` pulls the digest into the inactive blue/green slot,
   verifies its OCI revision and health, and leaves the gateway and database
   unchanged.
2. `promote-production` requires the exact candidate produced by the preceding
   job, runs idempotent migrations, switches the gateway, verifies health, and
   restores the previous gateway target if the switch fails.

The same sequence can be rerun manually on the `production` branch through
`workflow_dispatch`. No application files are transferred over FTP or SFTP:
srv01 pulls the immutable image directly from GHCR.

Protect `production` against direct pushes. The normal path is a feature or
development branch, pull request into `production`, required `CI` checks and
review, then merge. Candidate deployment also refuses to run unless an exact
successful `ci.yml` push run exists for the production commit.

## Repository configuration

Create GitHub environments `netcup-myinvoice-candidate` and
`netcup-myinvoice-production` without required reviewers so the production push
can complete unattended. Configure repository variables:

- `NETCUP_MYINVOICE_CANDIDATE_ENABLED=true` after the candidate server gate is
  installed and reviewed;
- `NETCUP_MYINVOICE_PROMOTE_ENABLED=true` after the production gate is installed
  and rollback-tested;
- `NETCUP_MYINVOICE_DEPLOY_HOST`;
- optional `NETCUP_MYINVOICE_DEPLOY_PORT` (default `22`);
- optional `NETCUP_MYINVOICE_DEPLOY_USER` (default `deploy-myinvoice`).

Configure repository secrets:

- `NETCUP_MYINVOICE_SSH_PRIVATE_KEY` for the dedicated deploy account;
- `NETCUP_MYINVOICE_SSH_HOST_KEY` containing only the independently verified
  srv01 `known_hosts` line.

Do not reuse an administrator key, a generic shell-capable account, or a GHCR
write token on the server. `deploy-myinvoice` must not belong to the `docker`
group. Its public key must use OpenSSH `restrict` and the root-owned forced
command:

```text
command="/usr/local/libexec/netcup-myinvoice-ssh-gate",restrict
```

The gate validates `SSH_ORIGINAL_COMMAND` as one line against the exact
allowlist below. Port, agent, X11 and PTY forwarding remain disabled.

## Required server contract

The only accepted remote commands are:

```text
sudo /usr/local/sbin/netcup-myinvoice-deploy candidate 'IMAGE@sha256:DIGEST' 'SOURCE_SHA'
sudo /usr/local/sbin/netcup-myinvoice-promote promote 'IMAGE@sha256:DIGEST' 'SOURCE_SHA'
```

The corresponding root-owned source files are versioned in `ops/deploy/netcup/`:

- `netcup-myinvoice-deploy` → `/usr/local/sbin/netcup-myinvoice-deploy` mode
  `0755`, owner `root:root`;
- `netcup-myinvoice-promote` → `/usr/local/sbin/netcup-myinvoice-promote` mode
  `0755`, owner `root:root`;
- `netcup-myinvoice-ssh-gate` →
  `/usr/local/libexec/netcup-myinvoice-ssh-gate` mode `0755`, owner `root:root`;
- `sudoers-netcup-myinvoice` → `/etc/sudoers.d/netcup-myinvoice` mode `0440`,
  owner `root:root`, validated with `visudo -cf` before replacement.

`bootstrap.sh` performs the one-time server installation from a pinned source
commit. It downloads and checksums the four files above, validates sudoers,
backs up the previous contract under root-only `state/`, and only then installs
the replacements. Normal releases never run this bootstrap and transfer no
application files over FTP or SFTP.

Both commands independently restrict images to
`ghcr.io/kahanekdusan/myinvoice@sha256:<64 lowercase hex>` and revisions to a
40-character lowercase commit SHA.

Candidate mode deploys only the inactive slot with
`MYINVOICE_ENABLE_CRON=0`, `MYINVOICE_SKIP_MIGRATIONS=1`, no published host
port, and verified shared-data/config mounts. It records the exact healthy
candidate in root-only `state/candidate.env`. It must not switch the gateway or
run migrations.

Promote mode accepts only that recorded candidate and only when it is still the
inactive slot. It rechecks the container image, OCI revision, safety environment
and health, runs `api/bin/migrate.php`, updates `ACTIVE_UPSTREAM`, recreates only
the gateway, and verifies both the gateway and promoted application. If the
gateway stage fails, it restores the previous `runtime.env` and gateway target.
The former application slot remains running as the rollback slot.

The workflow accepts manual dispatch only on `production`; another ref skips
the build. Both deployment jobs remain disabled unless their repository feature
flags are explicitly `true`. Once enabled, a production push performs the full
build, candidate verification, promotion and public health-check sequence.

Run the local/CI contract before installing server files:

```sh
sh ops/deploy/netcup/tests/contract.sh
```
