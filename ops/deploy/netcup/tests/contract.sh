#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CANDIDATE=$ROOT/netcup-myinvoice-deploy
PROMOTE=$ROOT/netcup-myinvoice-promote
GATE=$ROOT/netcup-myinvoice-ssh-gate
BOOTSTRAP=$ROOT/bootstrap.sh

for script in "$CANDIDATE" "$PROMOTE" "$GATE" "$BOOTSTRAP"; do
  /bin/sh -n "$script"
done

if /usr/bin/grep -Fq '"$STATE_DIR"/*' "$CANDIDATE"; then
  printf 'candidate validation must not reject unrelated root-owned state directories\n' >&2
  exit 1
fi

for deployment_script in "$CANDIDATE" "$PROMOTE"; do
  for database_contract in \
    'for database_candidate in myinvoice-db myinvoice-db-green' \
    'multiple database services are running' \
    'db_id=$(resolve_running_database)'
  do
    if ! /usr/bin/grep -Fq "$database_contract" "$deployment_script"; then
      printf 'deployment script must support both approved production database service names: %s\n' \
        "$database_contract" >&2
      exit 1
    fi
  done

  if /usr/bin/grep -Fq 'db_id=$(container_id_running myinvoice-db)' "$deployment_script"; then
    printf 'deployment script must not assume only the legacy database service name\n' >&2
    exit 1
  fi

  if /usr/bin/grep -Fq '"$PREFLIGHT" "$RUNTIME_ENV"' "$deployment_script"; then
    printf 'deployment script must not invoke the migration-only platform preflight\n' >&2
    exit 1
  fi
done

if ! /usr/bin/grep -Fq 'compose config --quiet' "$PROMOTE"; then
  printf 'promotion must validate the resolved Compose model before switching the gateway\n' >&2
  exit 1
fi

for network_contract in \
  '"myinvoice-db-blue", "myinvoice-db-green"' \
  'database network is missing the myinvoice-db alias' \
  '$DOCKER network connect "$database_network" "$candidate_id"' \
  'container_has_network "$candidate_id" "$database_network"'
do
  if ! /usr/bin/grep -Fq "$network_contract" "$CANDIDATE"; then
    printf 'candidate must join the single approved active database network: %s\n' \
      "$network_contract" >&2
    exit 1
  fi
done

tmp=$(/usr/bin/mktemp -d)
trap '/bin/rm -rf "$tmp"' EXIT HUP INT TERM

/bin/cp "$GATE" "$tmp/netcup-myinvoice-ssh-gate.test"
capture=$tmp/capture
fake_sudo=$tmp/fake-sudo
cat > "$fake_sudo" <<'EOF'
#!/bin/sh
printf '%s\n' "$@" > "$NETCUP_MYINVOICE_GATE_TEST_CAPTURE"
EOF
/bin/chmod 0700 "$fake_sudo"

image=ghcr.io/kahanekdusan/myinvoice@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
revision=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb

run_gate() {
  NETCUP_MYINVOICE_GATE_TEST_MODE=1 \
  NETCUP_MYINVOICE_GATE_TEST_SUDO=$fake_sudo \
  NETCUP_MYINVOICE_GATE_TEST_CAPTURE=$capture \
  SSH_ORIGINAL_COMMAND=$1 \
    /bin/sh "$tmp/netcup-myinvoice-ssh-gate.test"
}

run_gate "sudo /usr/local/sbin/netcup-myinvoice-deploy candidate '$image' '$revision'"
cat > "$tmp/expected" <<EOF
-n
/usr/local/sbin/netcup-myinvoice-deploy
candidate
$image
$revision
EOF
/usr/bin/cmp -s "$tmp/expected" "$capture"

run_gate "sudo /usr/local/sbin/netcup-myinvoice-promote promote '$image' '$revision'"
cat > "$tmp/expected" <<EOF
-n
/usr/local/sbin/netcup-myinvoice-promote
promote
$image
$revision
EOF
/usr/bin/cmp -s "$tmp/expected" "$capture"

for invalid in \
  "sudo /usr/local/sbin/netcup-myinvoice-deploy promote '$image' '$revision'" \
  "sudo /usr/local/sbin/netcup-myinvoice-promote candidate '$image' '$revision'" \
  "sudo /usr/local/sbin/netcup-myinvoice-deploy candidate '$image' '$revision' extra" \
  "sudo /usr/local/sbin/netcup-myinvoice-deploy candidate 'ghcr.io/other/image@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' '$revision'"
do
  if run_gate "$invalid" >/dev/null 2>&1; then
    printf 'gate accepted invalid command: %s\n' "$invalid" >&2
    exit 1
  fi
done

multiline=$(printf "sudo /usr/local/sbin/netcup-myinvoice-deploy candidate '%s' '%s'\nuname" "$image" "$revision")
if run_gate "$multiline" >/dev/null 2>&1; then
  printf 'gate accepted a multiline command\n' >&2
  exit 1
fi

printf 'netcup deploy contract tests passed\n'
