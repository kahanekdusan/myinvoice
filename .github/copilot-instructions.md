# Práce s daty v My Invoice

- Dodržuj projektová pravidla v `AGENTS.md`. Git, PR, issues, Actions, logy, cache, balíčky, release přílohy i vrstvy image považuj za místa, kam se mohou dostat jen zkontrolované podklady.
- Používej schema-only migrace, minimální syntetická testovací data a schválené konfigurační šablony. Skutečné kontakty, faktury, klientské exporty, zálohy, produkční fixture, provozní konfigurace a přístupové údaje nepřenášej ani v přejmenované, komprimované nebo šifrované podobě. Do příkladů e-mailů používej vyhrazené domény jako `example.invalid`.
- Používej schválenou GitHub noreply identitu pro autora i committera. Skutečné kontaktní údaje neuváděj ani ve zprávách commitů, PR, komentářích, logu nebo snímcích obrazovky.
- Před commitem prověř index. Před pushem prověř také všechny odesílané commity, změny názvů a metadata. Použij lokální kontrolu `ops/security/delivery-check.py` s Gitleaks a zkontroluj změny bez výpisu citlivých hodnot. Při nálezu, neověřeném obsahu nebo nefunkční kontrole přenos zastav.
- Před uploadem sestav z čistého checkoutu jen povolené soubory a ověř skutečný obsah archivu a všech vrstev image. Pro automatické nasazení vyžaduj skutečně vynucené povinné CI a nezávislé review změn workflow, Dockerfile a release skriptů. Ověř stejný digest na stagingu před produkcí.
- Při podezření na zveřejnění zastav dotčené akce a nahlas pouze bezpečná metadata, například identifikátor běhu nebo commitu a kategorii problému.
