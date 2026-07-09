<img src="https://r2cdn.perplexity.ai/pplx-full-logo-primary-dark%402x.png" style="height:64px;margin-right:32px"/>

# Projdi dokumentaci idoklad a sepis vse co tam je o cenovych nabidkach, od nastaveni po práci s ni cenovou nabidkou.

Vytvro mi  z toho zadani pro githubcopilot jako MD soubory k vytvoreni v me fakturacnim systemu. reps mnou upravenme systemu od hulana myinvoice.

Zde jsou všechna zadání pro GitHub Copilot – 14 MD souborů + tar.gz archiv s celou sadou.

***

## Co bylo prozkoumáno z iDoklad dokumentace

Z oficiální dokumentace iDokladu  bylo extrahováno vše relevantní k cenovým nabídkám:[^1][^2][^3]

**Nastavení**: Platnost nabídek (výchozí 14 dní), formát číselné řady s proměnnými `{N}`, `{D}`, `{M}`, `{R}`, formát názvu PDF souboru, způsob úhrady a bankovní účet.[^3][^4]

**Stavy nabídky**: Pět stavů – Vytvořena, Odeslána, Objednána, Vyfakturována, Zamítnuta. Tři se nastavují automaticky (Odeslána po e-mailu, Objednána při generování zálohy, Vyfakturována při generování faktury), Zamítnuta pouze ručně.[^1]

**Karta nabídky**: Číslo dokladu, šablona, štítky, odběratel, dodací adresa, stav, číslo objednávky, popis, datum vystavení, platnost do, způsob úhrady, bankovní účet, měna + kurz (cizí měna), interní poznámka, text před/za položkami.[^2]

**Filtrování**: Záložky Všechny / Schválené / V jednání / Expirované; pokročilé filtry dle období vystavení, platnosti a cenového rozsahu.[^1]

**E-mailové šablony**: Samostatná šablona „Cenová nabídka" se zástupnými znaky v `{}` pro číslo, popis, částku, měnu atd.[^5][^6]

**Šablony nabídek**: Typ dokladu = Cenová nabídka; extra pole Název šablony, Pevný VS, Pevný kurz.[^7]

**Konverze na fakturu**: Přenáší se odběratel, způsob úhrady, bankovní účet, objednávka, všechny položky a texty před/za položkami. Z jedné nabídky lze vystavit více zálohových faktur.[^1]

***

## Struktura vygenerovaných souborů

| Soubor | Obsah |
| :-- | :-- |
| `00_overview.md` | Index celé sady, priorita implementace, tech stack |
| `01_db_schema.md` | SQL migrace pro `quotes`, `quote_items`, `quote_templates`, `quote_attachments` |
| `02_model_and_enums.md` | PHP modely `Quote`, `QuoteItem`, enum `QuoteStatus` s helpers |
| `03_settings.md` | Rozšíření Nastavení → Prodej o platnost nabídek, PDF formát, číslování |
| `04_create_edit_quote.md` | Controller, FormRequest, QuoteService (create/update/copy), view tlačítka |
| `05_quote_items.md` | Položky – ruční + z ceníku, slevy na položku, JS přepočet live |
| `06_quote_statuses.md` | Stavový automat, diagram přechodů, badge komponenta, toolbar dle stavu |
| `07_list_and_filters.md` | Index controller, záložky s počty, pokročilé filtry, hromadné akce |
| `08_email_templates.md` | QuoteMailService, Mailable, šablona s proměnnými, odesílací route |
| `09_quote_to_invoice.md` | Konverze na vydanou/zálohovou fakturu, co se přenáší, related docs |
| `10_templates.md` | Šablony nabídek, aplikace šablony přes JS, API pro data šablony |
| `11_pdf_export.md` | QuotePdfService, PDF route, layout úpravy oproti faktuře |
| `12_attachments.md` | Upload/download příloh, max 5 × 2 MB, UI záložka |
| `13_api_endpoints.md` | REST API routes, QuoteResource, ApiController |
| `14_mobile_considerations.md` | Card layout, FAB, responsivní CSS, mobilní Blade karta |

<span style="display:none">[^10][^11][^12][^13][^14][^15][^16][^17][^18][^19][^20][^21][^22][^23][^24][^25][^26][^27][^28][^29][^30][^31][^32][^33][^34][^35][^36][^37][^38][^39][^40][^8][^9]</span>

<div align="center">⁂</div>

[^1]: https://www.idoklad.cz/podpora/cenove-nabidky

[^2]: https://www.idoklad.cz/podpora/karta-cenove-nabidky

[^3]: https://www.idoklad.cz/podpora/nastaveni-prodej

[^4]: https://www.idoklad.sk/podpora/nastavenie-predaj/

[^5]: https://www.idoklad.cz/podpora/nastaveni-e-maily-a-komunikace

[^6]: https://www.idoklad.cz/blog/nastavte-si-idoklad-od-z-5-dil-e-maily

[^7]: https://www.idoklad.cz/podpora/sablony

[^8]: https://www.idoklad.cz/blog/novinky-v-aplikaci-cenove-nabidky-vytvorite-upravite-odeslete-primo-z-mobilu

[^9]: https://www.idoklad.cz/blog/vytvorit-zaslat-mit-dokonaly-prehled-cenove-nabidky-nove-v-idokladu

[^10]: https://www.idoklad.cz/api-a-doplnky-k-idokladu

[^11]: https://www.idoklad.cz/podpora/nastaveni-aplikace-idoklad

[^12]: https://www.idoklad.cz/podpora

[^13]: https://www.idoklad.sk/blog/cenove-ponuky-odteraz-v-idoklade/

[^14]: https://www.idoklad.cz/blog/novinky-prosinec-2019

[^15]: https://www.idoklad.sk/cennik/

[^16]: https://www.idoklad.cz/cenik-op

[^17]: https://www.idoklad.cz/podpora/ovladani-aplikace

[^18]: https://www.idoklad.sk/podpora/sablony/

[^19]: https://www.idoklad.cz/blog/carove-kody-lepsi-orientace-v-cerpani-api-propojeni-s-dalsimi-bankami-srpnove-novinky-urychluji-praci-v-idokladu

[^20]: https://github.com/mervit/iDoklad-v3

[^21]: https://github.com/Holicz/idoklad-api-php-client

[^22]: https://launcheurope.eu/en/business/products/digital/idoklad

[^23]: https://github.com/Solitea/IdokladSdk

[^24]: https://flencery.cz/idoklad

[^25]: https://github.com/malcanek/iDoklad-v2

[^26]: https://apps.make.com/idoklad

[^27]: https://api.idoklad.cz/Help/v3/en/

[^28]: https://developer.idoklad.cz/

[^29]: https://github.com/malcanek/iDoklad/blob/master/README.md

[^30]: https://www.idoklad.cz/blog/nejcastejsi-otazky-uzivatelu-dokladu-uzivatel-dph

[^31]: https://play.google.com/store/apps/details?id=cz.csw.iDoklad\&hl=en

[^32]: https://www.apideck.com/accounting-software/idoklad

[^33]: https://napoveda.rocketoo.cz/rocketoo/rozsireni/idoklad

[^34]: https://www.qinvoice.cz/en/api/

[^35]: https://test22.idoklad.sk/pub/Help/1_novinky_vo_verzii.html

[^36]: https://psmoduly.cz/idokladcz-sk-v3-automaticke-napojeni-vystavovani-posilani-faktur-57/ucetnictvi-a-fakturace.htm

[^37]: https://myinvoice.cz/manual/16_Importy.html

[^38]: https://rozsireni.idoklad.cz/produkt/php-knihovna-api-v3/

[^39]: https://rozsireni.idoklad.cz/kategorie-produktu/propojeni/

[^40]: https://www.nuget.org/packages/IdokladSdk

