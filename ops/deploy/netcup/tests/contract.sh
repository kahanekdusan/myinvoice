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
