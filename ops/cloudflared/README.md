# Cloudflare Tunnel NUC-Invoices

Tento stack nahrazuje ručně spuštěný kontejner `interesting_bhaskara`. Šablona
je v `ops/cloudflared`, nasazená kopie v
`C:\docker\fakturace\stacks\cloudflared`. Token se nesmí předávat argumentem
příkazu ani ukládat do Gitu. Cloudflared jej čte z lokálního souboru
`tunnel-token`, který je v `.gitignore`.

## Bezpečná výměna tokenu

1. V Cloudflare otočte token a uložte novou hodnotu do runtime souboru
   `C:\docker\fakturace\stacks\cloudflared\tunnel-token` jako jediný řádek bez
   uvozovek. Soubor nesmí být přidán do Gitu ani vypsán do logu.
2. V runtime adresáři ověřte konfiguraci pomocí `docker compose config --quiet`.
3. Spusťte nový stack pomocí `docker compose up -d` a v Cloudflare ověřte nový
   zdravý konektor. Oba konektory mohou krátce běžet současně.
4. Ověřte veřejné adresy všech publikovaných aplikací.
5. Teprve potom zastavte původní kontejner a znovu ověřte veřejné adresy.

Kontejner je připojen k síti `public-gateway`, aby dosáhl na
`myinvoice-gateway` a `dusankahanek-prod`.
