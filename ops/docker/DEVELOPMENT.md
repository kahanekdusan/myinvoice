# Lokální vývoj na jednom portu

Vývojový override `compose.development.yaml` se používá společně se
základním `compose.local.yaml`. Databáze i aplikační data zůstávají v externích
volume určených `LOCAL_GENERATION_ID` v lokálním `.env`; recreate nebo rebuild
služby `app` je nemaže. Použij identifikátor existujících volume, nevytvářej nový.
`APP_PORT=8800` určuje společný port aplikace, API i veřejných odkazů na faktury.

Nejprve sestav frontend v `web/` příkazem `pnpm build`, potom spusť aplikaci:

```bash
docker compose \
  --env-file .env \
  -f ops/docker/compose.local.yaml \
  -f ops/docker/compose.development.yaml \
  up -d --build --no-deps app
```

- `http://localhost:8800` — aplikace pro vystavování faktur i klientský náhled;
- `/invoices` — přihlášená správa faktur;
- `/invoice/{token}` — veřejný náhled a stažení konkrétní faktury;
- samostatný Vite server není potřeba;
- po změnách v `web/src` spusť `pnpm build`; Apache servíruje připojený `web/dist`;
- změny v `api/src`, `api/templates`, `api/public`, `api/bin`, `styles/`
  a `.htaccess` vidí Apache z bind mountu;
- změna Composer závislostí nebo přidání nové PHP třídy vyžaduje rebuild `app`;
- změna `cfg.docker.php` vyžaduje pouze restart/recreate `app`, ne DB;
- při změně `APP_PORT` proveď recreate `app`; e-mailové odkazy používají stejný port.

Staré e-maily již obsahují původní adresu a zpětně se nemění. Při lokálním
ověřování starého odkazu změň pouze port, token ponech stejný. Odkazy s `localhost`
jsou určeny jen pro tento počítač; produkce používá svou veřejnou doménu.

Bezpečný rebuild pouze aplikace:

```bash
docker compose \
  --env-file .env \
  -f ops/docker/compose.local.yaml \
  -f ops/docker/compose.development.yaml \
  up -d --build --no-deps app
```

Nepoužívej `docker compose down -v`; přepínač `-v` maže databázové volume.
