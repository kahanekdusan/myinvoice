# netcup production delivery

The `production` branch remains the only production release source. A push to
that branch builds an immutable GHCR image on a GitHub-hosted runner. The
optional `deploy-candidate` job can then ask `srv01` to pull that exact digest
into the inactive slot.

The job is deliberately disabled until all server-side gates below are met. It
does not switch the gateway, enable cron, migrate production data, change
Cloudflare, or stop the NUC.

Protect `production` against direct pushes. The normal path is a feature or
development branch, pull request into `production`, required `CI` checks and
review, then merge. Candidate deployment additionally refuses to run unless an
exact successful `ci.yml` push run exists for the merged production commit.

## Repository configuration

Create the `netcup-myinvoice-candidate` GitHub environment and require manual
approval. Configure:

- variable `NETCUP_MYINVOICE_CANDIDATE_ENABLED=true` only after server review;
- variable `NETCUP_MYINVOICE_DEPLOY_HOST`;
- optional variables `NETCUP_MYINVOICE_DEPLOY_PORT` and
  `NETCUP_MYINVOICE_DEPLOY_USER`;
- secret `NETCUP_MYINVOICE_SSH_PRIVATE_KEY` for the dedicated deploy account;
- secret `NETCUP_MYINVOICE_SSH_HOST_KEY` containing only the verified srv01
  host-key line.

Do not reuse an administrator key, a shell-capable generic deploy account, or a
GHCR write token on the server.

## Required server contract

`deploy-myinvoice` must be restricted to exactly this command through sudo:

```text
/usr/local/sbin/netcup-myinvoice-deploy candidate IMAGE@sha256:DIGEST SOURCE_SHA
```

The root-owned command must independently reject anything that is not
`ghcr.io/kahanekdusan/myinvoice@sha256:<64 lowercase hex>` and a 40-character
commit SHA. It must pull the digest, verify the OCI revision label, run the
platform preflight, deploy only the inactive profile with
`MYINVOICE_ENABLE_CRON=0`, and perform internal health checks. It must never
switch the gateway, run a scheduler, touch Cloudflare, or stop/delete NUC
objects in `candidate` mode.

Until that command is installed and independently reviewed,
`NETCUP_MYINVOICE_CANDIDATE_ENABLED` must remain absent or false. Production
gateway switching and database cutover are separate manually approved gates.
