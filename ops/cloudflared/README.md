# Cloudflare Tunnel NUC-Invoices

Tento stack nahrazuje ručně spuštěný kontejner `interesting_bhaskara`. Token se
nesmí předávat argumentem příkazu ani ukládat do Gitu. Cloudflared jej čte z
lokálního souboru `tunnel-token`, který je v `.gitignore`.

## Bezpečná výměna tokenu

1. Připravte nový token do `ops/cloudflared/tunnel-token` jako jediný řádek bez
   uvozovek. Soubor nesmí být přidán do Gitu.
2. Ověřte konfiguraci pomocí `docker compose config --quiet`.
3. Spusťte nový stack pomocí `docker compose up -d` a v Cloudflare ověřte nový
   zdravý konektor. Oba konektory mohou krátce běžet současně.
4. Ověřte veřejné adresy všech publikovaných aplikací.
5. Teprve potom zastavte původní kontejner a znovu ověřte veřejné adresy.

Kontejner je připojen k síti `public-gateway`, aby dosáhl na
`myinvoice-gateway` a `dusankahanek-prod`. Síť `bridge` zachovává současné
síťové zapojení původního konektoru.
