#!/bin/sh
set -eu
set -f

SOURCE_COMMIT=4b23da9b7d8faebfeffb98a6f20b0babc0049fa3
BASE_URL=https://raw.githubusercontent.com/kahanekdusan/myinvoice/$SOURCE_COMMIT/ops/deploy/netcup
PLATFORM_ROOT=/opt/docker/myinvoice

[ -f "$PLATFORM_ROOT/compose.yml" ] || {
  printf 'bootstrap: MyInvoice platform not found at %s\n' "$PLATFORM_ROOT" >&2
  exit 1
}

stage=$(mktemp -d /tmp/myinvoice-cicd.XXXXXX)
curl -fsSLo "$stage/netcup-myinvoice-deploy" "$BASE_URL/netcup-myinvoice-deploy"
curl -fsSLo "$stage/netcup-myinvoice-promote" "$BASE_URL/netcup-myinvoice-promote"
curl -fsSLo "$stage/netcup-myinvoice-ssh-gate" "$BASE_URL/netcup-myinvoice-ssh-gate"
curl -fsSLo "$stage/sudoers-netcup-myinvoice" "$BASE_URL/sudoers-netcup-myinvoice"

printf '%s  %s\n' \
  19842e0c3187aac2b1f6cd31354c3ccd08730faf2a7346797ae38b808bc1335c "$stage/netcup-myinvoice-deploy" \
  8b8fd1f0700f8d34f2e38cea980aaedb1f9c1c957729902edb1aa84dba712efd "$stage/netcup-myinvoice-promote" \
  8ec3445bbc896e63d3de5c4853c7e3b5875eb6811de43c02fa91af859abfd82c "$stage/netcup-myinvoice-ssh-gate" \
  e197513b2ed9980943c9c5c063846a5825d44fbdc5081ae9e475554f172460dc "$stage/sudoers-netcup-myinvoice" \
  > "$stage/SHA256SUMS"
sha256sum -c "$stage/SHA256SUMS"

sudo -v
sudo visudo -cf "$stage/sudoers-netcup-myinvoice"
backup=$(sudo mktemp -d "$PLATFORM_ROOT/state/bootstrap-$SOURCE_COMMIT.XXXXXX")

test ! -e /usr/local/sbin/netcup-myinvoice-deploy || \
  sudo cp -a /usr/local/sbin/netcup-myinvoice-deploy "$backup/"
test ! -e /usr/local/sbin/netcup-myinvoice-promote || \
  sudo cp -a /usr/local/sbin/netcup-myinvoice-promote "$backup/"
test ! -e /usr/local/libexec/netcup-myinvoice-ssh-gate || \
  sudo cp -a /usr/local/libexec/netcup-myinvoice-ssh-gate "$backup/"
test ! -e /etc/sudoers.d/netcup-myinvoice || \
  sudo cp -a /etc/sudoers.d/netcup-myinvoice "$backup/"

sudo install -o root -g root -m 0755 \
  "$stage/netcup-myinvoice-deploy" /usr/local/sbin/netcup-myinvoice-deploy
sudo install -o root -g root -m 0755 \
  "$stage/netcup-myinvoice-promote" /usr/local/sbin/netcup-myinvoice-promote
sudo install -o root -g root -m 0755 \
  "$stage/netcup-myinvoice-ssh-gate" /usr/local/libexec/netcup-myinvoice-ssh-gate
sudo install -o root -g root -m 0440 \
  "$stage/sudoers-netcup-myinvoice" /etc/sudoers.d/netcup-myinvoice

sudo sh -n /usr/local/sbin/netcup-myinvoice-deploy
sudo sh -n /usr/local/sbin/netcup-myinvoice-promote
sudo sh -n /usr/local/libexec/netcup-myinvoice-ssh-gate
sudo visudo -cf /etc/sudoers.d/netcup-myinvoice
sudo -l -U deploy-myinvoice

printf 'NETCUP_CICD_BOOTSTRAP_OK %s backup=%s\n' "$SOURCE_COMMIT" "$backup"
