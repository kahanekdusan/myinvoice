#!/usr/bin/env sh
set -eu

if [ "${MYINVOICE_SKIP_MIGRATIONS:-0}" != "1" ]; then
  attempts="${MYINVOICE_MIGRATE_ATTEMPTS:-20}"
  delay="${MYINVOICE_MIGRATE_DELAY:-3}"
  current_attempt=1
  while :; do
    if php /var/www/html/api/bin/migrate.php; then
      break
    fi
    if [ "$current_attempt" -ge "$attempts" ]; then
      echo "Migration failed after $attempts attempts. Aborting startup." >&2
      exit 1
    fi
    echo "Migration attempt $current_attempt/$attempts failed. Retrying in ${delay}s..." >&2
    current_attempt=$((current_attempt + 1))
    sleep "$delay"
  done
fi

# Migrace běží jako root (entrypoint), takže mohou vytvořit log soubory s owner=root.
# Před startem Apache sjednotíme ownership/permissions, aby www-data mohl app logy appendovat.
DATA_DIR="${MYINVOICE_DATA_DIR:-/var/www/html}"
mkdir -p "$DATA_DIR/log" "$DATA_DIR/log/cron"
chown -R www-data:www-data "$DATA_DIR/log" 2>/dev/null || true
find "$DATA_DIR/log" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "$DATA_DIR/log" -type f -exec chmod 664 {} \; 2>/dev/null || true

# Vestavěný cron (default zapnutý; multi-replica deployment si dá MYINVOICE_ENABLE_CRON=0,
# jinak by úlohy běžely v každé replice). Spouští se PO migracích, aby schéma bylo hotové.
if [ "${MYINVOICE_ENABLE_CRON:-1}" != "0" ]; then
  # Cron v Debianu nedědí ENV kontejneru → vydumpujeme ho pro wrapper. Obsahuje tajemství
  # (DB heslo, SMTP, klíče), proto jen pro root + www-data (0640), ne world-readable.
  export -p > /etc/myinvoice-cron.env
  chmod 0640 /etc/myinvoice-cron.env
  chown root:www-data /etc/myinvoice-cron.env 2>/dev/null || true
  # Selhání cronu nesmí shodit kontejner (Apache poběží dál).
  if cron; then
    echo "[entrypoint] vestavěný cron spuštěn (logy v \${MYINVOICE_DATA_DIR}/log/cron)"
  else
    echo "[entrypoint] VAROVÁNÍ: cron se nepodařilo spustit — pokračuji bez něj" >&2
  fi
fi

exec apache2-foreground
