#!/usr/bin/env bash
# Canonical local-development lifecycle for macOS/Linux.
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
runtime_base="$project_root/.docker-local"
runtime_dir="$runtime_base/development"
runtime_env="$runtime_dir/runtime.env"
compose_file="$project_root/ops/docker/compose.local.yaml"
action="${1:-up}"

case "$action" in init|config|up|down|status) ;; *)
  printf 'Usage: %s [init|config|up|down|status]\n' "$0" >&2
  exit 2
esac

require_command() {
  command -v "$1" >/dev/null 2>&1 || { printf 'ERROR: %s is required\n' "$1" >&2; exit 1; }
}

random_hex() { openssl rand -hex "$1"; }
random_b64() { openssl rand -base64 "$1" | tr -d '\r\n'; }

assert_safe_runtime_path() {
  if [ -L "$runtime_base" ] || [ -L "$runtime_dir" ] || [ -L "$runtime_env" ]; then
    printf 'ERROR: local runtime path must not be a symlink\n' >&2
    exit 1
  fi
}

create_runtime() {
  assert_safe_runtime_path
  umask 077
  mkdir -p "$runtime_dir"
  if [ -L "$runtime_base" ] || [ -L "$runtime_dir" ]; then
    printf 'ERROR: local runtime path became a symlink\n' >&2
    exit 1
  fi
  chmod 700 "$runtime_dir"
  if [ ! -f "$runtime_env" ]; then
    tmp_env="$(mktemp "$runtime_dir/runtime.env.XXXXXX")"
    {
      printf 'APP_PORT=8090\n'
      printf 'DB_PORT=3311\n'
      printf 'DB_ROOT_PASSWORD=%s\n' "$(random_hex 24)"
      printf 'DB_PASSWORD=%s\n' "$(random_hex 24)"
      printf 'MYINVOICE_PEPPER=%s\n' "$(random_b64 32)"
      printf 'MYINVOICE_SECRET_KEY=%s\n' "$(random_b64 32)"
      printf 'LOCAL_GENERATION_ID=%s\n' "$(random_hex 16)"
    } >"$tmp_env"
    chmod 600 "$tmp_env"
    mv "$tmp_env" "$runtime_env"
  fi
  chmod 600 "$runtime_env"
}

read_value() {
  key="$1"
  awk -F= -v key="$key" '$1 == key { sub(/^[^=]*=/, ""); print }' "$runtime_env"
}

validate_runtime() {
  assert_safe_runtime_path
  chmod 700 "$runtime_dir"
  chmod 600 "$runtime_env"
  invalid_line="$(awk -F= '
    !/^(APP_PORT|DB_PORT|DB_ROOT_PASSWORD|DB_PASSWORD|MYINVOICE_PEPPER|MYINVOICE_SECRET_KEY|LOCAL_GENERATION_ID)=/ { print NR; exit }
  ' "$runtime_env")"
  [ -z "$invalid_line" ] || { printf 'ERROR: unknown or invalid runtime key at line %s\n' "$invalid_line" >&2; exit 1; }
  for key in APP_PORT DB_PORT DB_ROOT_PASSWORD DB_PASSWORD MYINVOICE_PEPPER MYINVOICE_SECRET_KEY LOCAL_GENERATION_ID; do
    count="$(awk -F= -v key="$key" '$1 == key { n++ } END { print n + 0 }' "$runtime_env")"
    [ "$count" -eq 1 ] || { printf 'ERROR: runtime key %s must occur exactly once\n' "$key" >&2; exit 1; }
    [ -n "$(read_value "$key")" ] || { printf 'ERROR: runtime key %s is empty\n' "$key" >&2; exit 1; }
  done

  app_port="$(read_value APP_PORT)"
  db_port="$(read_value DB_PORT)"
  for port in "$app_port" "$db_port"; do
    case "$port" in *[!0-9]*|'') printf 'ERROR: local ports must be integers\n' >&2; exit 1 ;; esac
    [ "$port" -ge 1024 ] && [ "$port" -le 65535 ] || {
      printf 'ERROR: local ports must be between 1024 and 65535\n' >&2; exit 1;
    }
  done
  [ "$app_port" -ne "$db_port" ] || { printf 'ERROR: APP_PORT and DB_PORT must differ\n' >&2; exit 1; }
  printf '%s' "$(read_value LOCAL_GENERATION_ID)" | grep -Eq '^[0-9a-f]{32}$' || {
    printf 'ERROR: LOCAL_GENERATION_ID must be 32 lowercase hexadecimal characters\n' >&2
    exit 1
  }
}

docker_clean() {
  env -u APP_PORT -u DB_PORT -u DB_ROOT_PASSWORD -u DB_PASSWORD \
    -u MYINVOICE_PEPPER -u MYINVOICE_SECRET_KEY -u LOCAL_GENERATION_ID \
    -u SKIP_FRONTEND_TYPECHECK -u COMPOSE_PROJECT_NAME \
    docker "$@"
}

assert_local_docker() {
  if [ -n "${DOCKER_HOST:-}" ] || [ -n "${DOCKER_CONTEXT:-}" ]; then
    printf 'ERROR: DOCKER_HOST/DOCKER_CONTEXT must be unset for local development\n' >&2
    exit 1
  fi
  context="$(docker_clean context show)"
  endpoint="$(docker_clean context inspect "$context" --format '{{ (index .Endpoints "docker").Host }}')"
  case "$endpoint" in
    unix://*|npipe://*) ;;
    *) printf 'ERROR: refusing non-local Docker endpoint %s\n' "$endpoint" >&2; exit 1 ;;
  esac
}

compose_run() {
  docker_clean compose --project-name myinvoice_dev --env-file "$runtime_env" -f "$compose_file" "$@"
}

validate_volume_identity() {
  expected="$(read_value LOCAL_GENERATION_ID)"
  for suffix in app-data db-data; do
    volume="myinvoice_dev_${expected}_${suffix}"
    docker_clean volume create \
      --label io.dusankahanek.myinvoice.scope=local-development \
      --label "io.dusankahanek.myinvoice.generation=$expected" \
      "$volume" >/dev/null
    actual="$(docker_clean volume inspect --format '{{ index .Labels "io.dusankahanek.myinvoice.generation" }}' "$volume")"
    scope="$(docker_clean volume inspect --format '{{ index .Labels "io.dusankahanek.myinvoice.scope" }}' "$volume")"
    if [ "$scope" != local-development ] || [ "$actual" != "$expected" ]; then
      printf 'ERROR: refusing existing untrusted volume %s\n' "$volume" >&2
      exit 1
    fi
  done
}

require_command docker
assert_local_docker
docker_clean compose version >/dev/null
case "$action" in
  init|config|up)
    require_command openssl
    create_runtime
    ;;
  down|status)
    [ -f "$runtime_env" ] || {
      printf 'ERROR: local stack is not initialized; nothing was changed\n' >&2
      exit 1
    }
    ;;
esac
validate_runtime

compose_run config --quiet

case "$action" in
  init|config)
    printf 'LOCAL_DEV_PREFLIGHT=GO;URL=http://localhost:%s\n' "$(read_value APP_PORT)"
    ;;
  up)
    validate_volume_identity
    compose_run up -d --build --wait app db
    printf 'LOCAL_DEV_UP=PASS;URL=http://localhost:%s\n' "$(read_value APP_PORT)"
    ;;
  down)
    compose_run down
    printf 'LOCAL_DEV_DOWN=PASS;VOLUMES=PRESERVED\n'
    ;;
  status)
    compose_run ps
    ;;
esac
