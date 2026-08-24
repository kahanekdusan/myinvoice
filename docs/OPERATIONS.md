# Provozní model forku

## Větve

- `master` je beze změn shodný s `radekhulan/myinvoice:master`. Vlastní commity sem nepatří.
- `development` obsahuje všechny vlastní změny a pravidelně do sebe slučuje nový upstream `master`.
- `production` je přesně commit, který má být nasazen. Push do této větve nejprve sestaví neměnný GHCR image a potom spustí deployment na označeném self-hosted runneru.

Aktualizace upstreamu:

```powershell
git fetch upstream --tags
git switch master
git merge --ff-only upstream/master
git push origin master
git switch development
git merge --no-ff master
```

Po vyřešení konfliktů musí projít backendové testy, `pnpm exec vue-tsc --noEmit`, `pnpm test:pwa` a `pnpm build`. Vlastní databázové migrace používají řadu `9000+`, aby nekolidovaly s upstreamem.

Nasazení ověřeného development commitu:

```powershell
git switch production
git merge --ff-only development
git push origin production
```

## Docker stacky

Provozní soubory se secrets jsou mimo Git v `C:\docker\fakturace\stacks`:

| Projekt | Účel | Aplikace | DB | Image | Data |
| --- | --- | --- | --- | --- | --- |
| `myinvoice` | produkce | `0.0.0.0:8088` | `127.0.0.1:3310` | digest z `production` | stávající produkční volumes |
| `myinvoice_dev` | vlastní vývoj | `127.0.0.1:8090` | `127.0.0.1:3311` | lokální/development | stávající vývojové volumes |
| `myinvoice_master` | čistý upstream muster | `127.0.0.1:8100` | `127.0.0.1:3312` | `radekhulan/myinvoice:4.56.1` | kopie produkčních dat |

Master a development mají cron vypnutý a SMTP přesměrované na uzavřený lokální port. Reálná data ani `.env` se nikdy necommitují.

Ruční kontrola:

```powershell
Invoke-WebRequest http://127.0.0.1:8090/ -SkipHttpErrorCheck
Invoke-WebRequest http://127.0.0.1:8100/ -SkipHttpErrorCheck
Invoke-WebRequest https://faktury.dusankahanek.cz/ -SkipHttpErrorCheck
```

## Produkční ochrany

Deployment přijímá jen neměnný odkaz `ghcr.io/kahanekdusan/myinvoice@sha256:...`. Před změnou image vytvoří v `C:\docker\fakturace\stacks\production\backups` konzistentní SQL dump, archiv `/data` a SHA-256 součty. Po migraci musí lokální i veřejná adresa vrátit HTTP 200; jinak se aplikace vrátí na předchozí image. Produkční DB volume se nemaže ani nevytváří znovu.

První bezpečnostní snapshot před reorganizací je uložen mimo Git v `C:\docker\fakturace\_safety\20260824-180913`.
