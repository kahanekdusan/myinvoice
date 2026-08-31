#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
compose="$root/ops/docker/compose.local.yaml"
shell_script="$root/cmd/docker-local.sh"
ps_script="$root/cmd/docker-local.ps1"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

cat >"$tmp/runtime.env" <<'EOF'
APP_PORT=18090
DB_PORT=13311
DB_ROOT_PASSWORD=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
DB_PASSWORD=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
MYINVOICE_PEPPER=YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=
MYINVOICE_SECRET_KEY=YmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmI=
LOCAL_GENERATION_ID=0123456789abcdef0123456789abcdef
EOF

docker compose --env-file "$tmp/runtime.env" -f "$compose" config --format json >"$tmp/render.json"
python3 - "$tmp/render.json" <<'PY'
import json, sys

d = json.load(open(sys.argv[1], encoding="utf-8"))
assert d["name"] == "myinvoice_dev"
assert set(d["services"]) == {"app", "db"}
app, db = d["services"]["app"], d["services"]["db"]
assert app["ports"] == [{"mode": "ingress", "target": 80, "published": "18090", "protocol": "tcp", "host_ip": "127.0.0.1"}]
assert db["ports"] == [{"mode": "ingress", "target": 3306, "published": "13311", "protocol": "tcp", "host_ip": "127.0.0.1"}]
env = app["environment"]
assert env["MYINVOICE_APP_ENV"] == "development"
assert env["MYINVOICE_APP_URL"] == "http://localhost:18090"
assert env["MYINVOICE_ENABLE_CRON"] == "0"
assert env["MYINVOICE_SESSION_COOKIE_SECURE"] == "false"
assert env["MYINVOICE_SMTP_TRANSPORT"] == "smtp"
assert env["MYINVOICE_SMTP_HOST"] == "127.0.0.1"
assert env["MYINVOICE_SMTP_PORT"] == "1"
assert env["MYINVOICE_SMTP_AUTH"] == "false"
assert env["MYINVOICE_DB_HOST"] == "db"
assert env["MYINVOICE_DB_NAME"] == "myinvoice_dev"
assert app.get("healthcheck", {}).get("test")
assert db.get("healthcheck", {}).get("test")
assert d["networks"]["db-net"]["internal"] is True
assert d["volumes"]["app-data"]["name"] == "myinvoice_dev_0123456789abcdef0123456789abcdef_app-data"
assert d["volumes"]["db-data"]["name"] == "myinvoice_dev_0123456789abcdef0123456789abcdef_db-data"
for volume in ("app-data", "db-data"):
    labels = d["volumes"][volume]["labels"]
    assert labels["io.dusankahanek.myinvoice.scope"] == "local-development"
    assert labels["io.dusankahanek.myinvoice.generation"] == "0123456789abcdef0123456789abcdef"
render = json.dumps(d)
assert "faktury.dusankahanek.cz" not in render
assert "myinvoice-production" not in render
PY

if env -u MYINVOICE_PEPPER docker compose --env-file /dev/null -f "$compose" config --quiet >/dev/null 2>&1; then
  echo "missing secrets unexpectedly accepted" >&2
  exit 1
fi

APP_PORT=not-a-port DB_PORT=13311 \
DB_ROOT_PASSWORD=a DB_PASSWORD=b MYINVOICE_PEPPER=c MYINVOICE_SECRET_KEY=d LOCAL_GENERATION_ID=e \
  docker compose -f "$compose" config --quiet >/dev/null 2>&1 && {
    echo "invalid application port unexpectedly accepted" >&2
    exit 1
  }

bash -n "$shell_script"
grep -Fq -- '--env-file "$runtime_env"' "$shell_script"
grep -Fq -- '-u DB_PASSWORD' "$shell_script"
grep -Fq -- "'APP_PORT', 'DB_PORT'" "$ps_script"
grep -Fq 'SetAccessRuleProtection' "$ps_script"
grep -Fxq '/.docker-local' "$root/.dockerignore"
grep -Fq 'docker-local.sh' "$root/docs/LOCAL_DEVELOPMENT.md"
grep -Fq 'docker-local.ps1' "$root/docs/LOCAL_DEVELOPMENT.md"

# Exercise the Bash bootstrap in an isolated mirror with a fake Docker CLI.
mirror="$tmp/mirror"
mkdir -p "$mirror/cmd" "$mirror/ops/docker" "$tmp/fake-bin"
cp "$shell_script" "$mirror/cmd/docker-local.sh"
cp "$compose" "$mirror/ops/docker/compose.local.yaml"
cat >"$tmp/fake-bin/docker" <<'SH'
#!/usr/bin/env bash
printf 'APP_PORT=%s;DB_PASSWORD=%s;COMPOSE_PROJECT_NAME=%s;ARGS=%s\n' "${APP_PORT-unset}" "${DB_PASSWORD-unset}" "${COMPOSE_PROJECT_NAME-unset}" "$*" >>"$FAKE_DOCKER_LOG"
if [ "${1:-} ${2:-}" = "context show" ]; then
  printf 'default\n'
elif [ "${1:-} ${2:-}" = "context inspect" ]; then
  printf 'unix:///var/run/docker.sock\n'
elif [ "${1:-} ${2:-}" = "volume inspect" ]; then
  volume="${!#}"
  if [ "${3:-}" = "--format" ]; then
    if [ "${FAKE_VOLUME_MODE:-}" = mismatch ]; then
      case "${4:-}" in *generation*) printf 'wrong-generation\n' ;; *) printf 'local-development\n' ;; esac
    else
      case "${4:-}" in *generation*) cat "$FAKE_DOCKER_STATE/$volume.generation" ;; *) printf 'local-development\n' ;; esac
    fi
  elif [ "${FAKE_VOLUME_MODE:-}" = mismatch ] || [ -f "$FAKE_DOCKER_STATE/$volume.generation" ]; then
    exit 0
  else
    exit 1
  fi
elif [ "${1:-} ${2:-}" = "volume create" ]; then
  volume="${!#}"
  generation=''
  for argument in "$@"; do
    case "$argument" in io.dusankahanek.myinvoice.generation=*) generation="${argument#*=}" ;; esac
  done
  [ -n "$generation" ] || exit 2
  mkdir -p "$FAKE_DOCKER_STATE"
  printf '%s\n' "$generation" >"$FAKE_DOCKER_STATE/$volume.generation"
  printf '%s\n' "$volume"
fi
exit 0
SH
chmod +x "$tmp/fake-bin/docker"
FAKE_DOCKER_LOG="$tmp/docker.log" PATH="$tmp/fake-bin:$PATH" APP_PORT=18091 DB_PASSWORD=production-value COMPOSE_PROJECT_NAME=production-project \
  "$mirror/cmd/docker-local.sh" config >"$tmp/bootstrap.out"
grep -Fq 'LOCAL_DEV_PREFLIGHT=GO;URL=http://localhost:8090' "$tmp/bootstrap.out"
if grep -v 'APP_PORT=unset;DB_PASSWORD=unset;COMPOSE_PROJECT_NAME=unset' "$tmp/docker.log" >/dev/null; then
  echo "host environment reached Docker Compose" >&2
  exit 1
fi
grep -Fq 'ARGS=compose --project-name myinvoice_dev --env-file' "$tmp/docker.log"
if stat -c '%a' "$mirror/.docker-local/development/runtime.env" >/dev/null 2>&1; then
  runtime_mode="$(stat -c '%a' "$mirror/.docker-local/development/runtime.env")"
else
  runtime_mode="$(stat -f '%Lp' "$mirror/.docker-local/development/runtime.env")"
fi
[ "$runtime_mode" = 600 ] || {
  echo "runtime.env mode is $runtime_mode, expected 600" >&2
  exit 1
}

if FAKE_DOCKER_LOG="$tmp/docker-remote.log" PATH="$tmp/fake-bin:$PATH" DOCKER_HOST=ssh://srv01.example \
  "$mirror/cmd/docker-local.sh" config >/dev/null 2>&1; then
  echo "remote Docker endpoint unexpectedly accepted" >&2
  exit 1
fi
test ! -s "$tmp/docker-remote.log"

FAKE_DOCKER_LOG="$tmp/docker-up.log" FAKE_DOCKER_STATE="$tmp/docker-state" FAKE_VOLUME_MODE=fresh PATH="$tmp/fake-bin:$PATH" \
  APP_PORT=18091 DB_PASSWORD=production-value COMPOSE_PROJECT_NAME=production-project \
  "$mirror/cmd/docker-local.sh" up >"$tmp/up.out"
grep -Fq 'LOCAL_DEV_UP=PASS;URL=http://localhost:8090' "$tmp/up.out"
grep -Fq 'ARGS=volume create --label io.dusankahanek.myinvoice.scope=local-development' "$tmp/docker-up.log"
grep -Fq 'ARGS=compose --project-name myinvoice_dev' "$tmp/docker-up.log"
grep -Fq ' up -d --build --wait app db' "$tmp/docker-up.log"
if grep -v 'APP_PORT=unset;DB_PASSWORD=unset;COMPOSE_PROJECT_NAME=unset' "$tmp/docker-up.log" >/dev/null; then
  echo "protected environment reached a Docker volume/up command" >&2
  exit 1
fi

if FAKE_DOCKER_LOG="$tmp/docker-mismatch.log" FAKE_DOCKER_STATE="$tmp/docker-state" FAKE_VOLUME_MODE=mismatch PATH="$tmp/fake-bin:$PATH" \
  "$mirror/cmd/docker-local.sh" up >/dev/null 2>&1; then
  echo "untrusted local volume unexpectedly accepted" >&2
  exit 1
fi
if grep -Fq ' up -d --build --wait app db' "$tmp/docker-mismatch.log"; then
  echo "Compose up ran after volume identity rejection" >&2
  exit 1
fi

# A symlinked runtime base must fail before any secret is written through it.
mirror_link="$tmp/mirror-link"
mkdir -p "$mirror_link/cmd" "$mirror_link/ops/docker" "$tmp/outside"
cp "$shell_script" "$mirror_link/cmd/docker-local.sh"
cp "$compose" "$mirror_link/ops/docker/compose.local.yaml"
ln -s "$tmp/outside" "$mirror_link/.docker-local"
if FAKE_DOCKER_LOG="$tmp/docker-link.log" PATH="$tmp/fake-bin:$PATH" "$mirror_link/cmd/docker-local.sh" init >/dev/null 2>&1; then
  echo "symlinked runtime base unexpectedly accepted" >&2
  exit 1
fi
test -z "$(find "$tmp/outside" -mindepth 1 -print -quit)"

printf 'local-development-contract: PASS\n'
