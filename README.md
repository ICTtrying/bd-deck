# BD Deck

Desktop-app van **Borgman Digital** voor het beheren van WordPress- en Laravel-sites: lokaal (DDEV) en live (WPMU DEV, Hostinger of elders) naast elkaar, met één klik ophalen, live zetten, terugdraaien en inloggen.

**Installeren op Linux Mint** (installeert ook Docker, DDEV, VS Code en je SSH-sleutel, en zet de app in je menu):

```bash
curl -fsSL https://raw.githubusercontent.com/ICTtrying/bd-deck/main/bin/install-mint | bash
```

De app werkt zichzelf daarna bij via de [releases](https://github.com/ICTtrying/bd-deck/releases).

BD Deck is een schil om het script **`wpopen`**. Alle logica voor bouwen, syncen, back-ups en live zetten staat in dat ene bash-script (`bin/wpopen`); de app roept het aan en laat zien wat er gebeurt. Wat de app kan, kan de terminal dus ook.

```
┌──────────────── BD Deck (Laravel 13 + Livewire 4 + NativePHP) ────────────────┐
│  Dashboard · Sitepagina · Push · Back-ups · WP-CLI · Kluis · Instellingen      │
│                    │                                                         │
│      App\Services\WpOpen\WpOpen  (Laravel Process, niet-interactief)          │
│                    │            queue: RunWpOpenCommand → live logboek        │
└────────────────────┼─────────────────────────────────────────────────────────┘
                     ▼
          ~/.local/bin/wpopen  (bron van waarheid, ook in je terminal)
                     │
     ddev · git · rsync · lftp · ssh · WP-CLI  →  ~/wp-sites/<site>  ⇄  live
```

## Inhoud

- [Wat het doet](#wat-het-doet)
- [Delen met collega's](#delen-met-collegas)
- [Laravel-sites](#laravel-sites)
- [Verhuizen en live zetten](#verhuizen-en-live-zetten)
- [Installeren](#installeren)
- [Starten](#starten)
- [De desktop-app bouwen](#de-desktop-app-bouwen)
- [wpopen in de terminal](#wpopen-in-de-terminal)
- [Hoe het in elkaar zit](#hoe-het-in-elkaar-zit)
- [Een nieuwe functie toevoegen](#een-nieuwe-functie-toevoegen)
- [Beveiliging](#beveiliging)
- [Tests](#tests)
- [macOS en Windows](#macos-en-windows)
- [Problemen oplossen](#problemen-oplossen)

## Wat het doet

**Per site**

- WP-admin openen, lokaal of live, met automatisch inloggen (eenmalige link, verloopt na 2 minuten).
- Website openen, project in VS Code, terminal in de projectmap, map in de bestandsbeheerder, SSH- of SFTP-sessie naar live.
- **Lokaal bouwen**: DDEV-site met dezelfde PHP-versie als live, code en database van live, URL's omgezet, uploads via een proxy van live, git-repo voor je eigen thema en plugins.
- **Ophalen**: code en/of database van live. De lokale database wordt eerst bewaard.
- **Naar live zetten**: proefrun met de bestandslijst, commitbericht, back-up van de huidige live-versies, controle of live intussen buiten BD Deck om is aangepast, upload, cache legen (Hummingbird en WPMU DEV-servercache). Optioneel nieuwe uploads en, bij SSH-sites, de database.
- **Alleen lokaal**: een nieuwe WordPress-site op je computer, zonder live-server. Als hij af is, zet je hem met één knop live.
- **Verhuizen / live zetten**: een lokale site, of een site van een oude host, in één keer naar een nieuwe WordPress-installatie bij WPMU DEV, Hostinger of elders. Daarna het definitieve domein omzetten. Zie [Verhuizen en live zetten](#verhuizen-en-live-zetten).
- **Herstellen**: site repareren, database opnieuw ophalen, terugzetten naar main (werk blijft in een backup-branch), volledig opnieuw migreren vanaf live.
- **Back-ups**: automatisch voor elke push, migratie en database-import; zelf maken; één klik terugzetten (ook een push ongedaan maken).
- **Verbindingstest**: SSH/SFTP, WP-CLI op de server, HTTP-status live en lokaal.
- **Updates**: beschikbare WordPress-, plugin- en thema-updates op live.
- **WP-CLI**: commando's lokaal of op live, met uitvoer.

**Algemeen**

- Dashboard als lijst met zoeken, filters (favorieten, lokaal gebouwd, klaar voor live, provider), favorieten en per site een menu met bewerken, verhuizen en verwijderen.
- Aan de slag: systeemcontrole, SSH-sleutel maken en stap voor stap uitleg om de sleutel bij WPMU DEV of Hostinger te zetten.
- Activiteitenlog met de volledige uitvoer van elk commando, live meelopend.
- Sites toevoegen (zelf laten zoeken of pad opgeven), bewerken en verwijderen. Bestaande verbindingen importeren uit `~/.ssh/config`, FileZilla en VS Code `sftp.json`.
- SSH-sleutels: publieke sleutels tonen en kopiëren, standaardsleutel kiezen, nieuwe ed25519-sleutel maken. Private sleutels worden nooit gelezen of getoond.
- Kluis voor wachtwoorden en API-sleutels, versleuteld met je hoofdwachtwoord.
- Instellingen: sites-map, editor, terminal, SSH-sleutel, taal (Nederlands/Engels), thema (licht/donker/systeem), auto-login, automatisch vergrendelen, meldingen, hoofdwachtwoord wijzigen, script opnieuw laden, systeemcontrole.
- Native menubalk met sneltoetsen (Ctrl+1–4, Ctrl+N, Ctrl+L, Ctrl+,), tray-icoon met favorieten, systeemmeldingen als een actie klaar is, snelzoeker (Ctrl+K).

## Delen met collega's

Laat een collega op Linux Mint de installatieregel bovenaan plakken (staat ook op de pagina *Aan de slag*). Het script `bin/install-mint`:

- installeert wat ontbreekt: git, rsync, ssh, lftp, curl, Docker (officiële pakketbron), DDEV + mkcert, VS Code, GitHub CLI en FUSE voor AppImages;
- zet de gebruiker in de groep `docker` (daarna één keer opnieuw inloggen);
- vraagt naam en e-mail voor git als die nog niet zijn ingesteld, en maakt `~/.ssh/id_ed25519` als die er nog niet is;
- downloadt de nieuwste AppImage van GitHub naar `~/Applications/BD-Deck.AppImage` en zet hem in het app-menu en op het bureaublad.

Het is veilig om opnieuw te draaien. Vanuit de app start *Aan de slag → Installeren in een terminal* hetzelfde script met de draaiende AppImage.

Iedereen werkt op zijn eigen computer met zijn eigen gegevens:

- Bij de eerste start kiest de collega een eigen hoofdwachtwoord; daarna leidt **Aan de slag** door de systeemcontrole, het maken van een SSH-sleutel en het plaatsen daarvan bij WPMU DEV of Hostinger.
- Sites, kluis, instellingen en SSH-sleutels staan in de eigen gebruikersmap (`~/.config/bd-deck`, `~/.config/wpsites`, `~/.ssh`). In de AppImage zit niets daarvan: geen database, geen sleutels, geen sitelijst. Het SSH-sleutelscherm toont dus alleen de sleutels van de computer waarop de app draait.
- De `APP_KEY` uit de meegeleverde `.env` wordt in de gebouwde app niet gebruikt: elke installatie maakt bij de eerste start een eigen sleutel in `~/.config/bd-deck/storage/app/installation.key` (rechten 600).
- Wat een collega nodig heeft staat onder [Installeren](#installeren); PHP, Composer en Node zijn alleen nodig om zelf te ontwikkelen.

## Laravel-sites

BD Deck herkent bij het toevoegen zelf of er WordPress (`wp-content`) of Laravel (`artisan` met `laravel/framework` in `composer.json`) op de server staat. Een Deployer/Envoyer-map `releases/<x>` wordt `current`. Voor Laravel:

| Actie | Wat er gebeurt |
| --- | --- |
| **Lokaal bouwen** | DDEV (type laravel, dezelfde PHP-versie als live, PostgreSQL als live dat gebruikt), code zonder `vendor`, `node_modules`, `.env` en `storage`, `composer install`, database van live, `storage:link`. De lokale `.env` is de live `.env` met DDEV-database, `APP_URL` lokaal, mail naar het log en betaal- en mailsleutels leeg (`APP_KEY` blijft gelijk). Bestanden onder `/storage/` komen via een proxy van live. |
| **Versiebeheer** | Het hele project in git (`main` = live, `dev` = jouw werk); de uitsluitingen van BD Deck staan in `.git/info/exclude`, niet in de `.gitignore` van het project. |
| **Naar live** | Alleen gewijzigde bestanden, met back-up en controle op wijzigingen op live. Daarna: `composer install --no-dev` als `composer.lock` veranderd is; bij gewijzigde frontendbestanden lokaal `npm run build` en `public/build` in z'n geheel mee; nieuwe migraties met `migrate --force` (live database eerst bewaard); `optimize:clear` en `queue:restart`. De database zelf gaat nooit naar live. |
| **Ophalen** | Code, database (`mysqldump`/`pg_dump` op de server, het wachtwoord leest de server zelf uit `.env`) en uploads (`storage/app/public`). |
| **Artisan** | Tabblad *Artisan*: lokaal of op live. Op live beantwoordt `--no-interaction` vragen met nee; geef zelf `--force` mee. |

Verhuizen naar een andere server en *Nieuwe lokale site* zijn er nog alleen voor WordPress.

## Verhuizen en live zetten

Eén systeem voor drie situaties:

| Situatie | Zo doe je het |
| --- | --- |
| Nieuwe site, nog niet online | *Site toevoegen → Nieuwe lokale site*. Bouw de site lokaal. Klaar? *Live zetten*. |
| Site staat bij een oude host (bv. Hostnet) en moet naar WPMU DEV of Hostinger | Koppel de oude site (*Site toevoegen*), bouw hem lokaal, en kies *Verhuizen*. |
| Site staat al goed, maar nog op een tijdelijk adres | *Verhuizen → Alleen het domein omzetten* (SSH-sites). |

Voor *Live zetten* en *Verhuizen* maak je eerst bij de nieuwe host een lege WordPress-site aan (een tijdelijk adres is prima) en zet je je SSH-sleutel erbij. Daarna doet BD Deck:

1. Bij een verhuizing: alle uploads van de oude server echt lokaal halen (normaal komen die via een proxy binnen).
2. De nieuwe server zoeken (wp-content, SSH of alleen SFTP) en controleren of er WordPress op staat.
3. Een back-up van de nieuwe server (database en wp-content), terug te zetten bij *Back-ups*.
4. wp-content uploaden (thema's, plugins, uploads), zonder lokale hulpbestanden, caches en git. Bestanden van de host zelf blijven staan.
5. De lokale database exporteren met alle adressen al omgezet (ook JSON-geëscapete), importeren met foreign-key-controles uit, en de tabelprefix in `wp-config.php` gelijkzetten. Bij alleen SFTP gaat de import via een tijdelijk PHP-bestand; de dump staat dan zo kort mogelijk op de server, buiten de webroot als dat kan.
6. De lokale gebruiker `dev` weghalen (is dat de enige beheerder, dan krijgt hij een willekeurig wachtwoord; log in via *WP-admin live* en maak je eigen account), permalinks en cache vernieuwen.
7. De site in de sitelijst laten wijzen naar de nieuwe server; git `main` en de tag `deployed` gelijkzetten, zodat de volgende *Naar live* alleen wijzigingen stuurt. De oude server blijft desgewenst bewaard als `<naam>-oud`.

Wijst de DNS nog naar de oude host, gebruik dan het tijdelijke adres van de nieuwe host en zet na de DNS-wissel het echte domein (`wpopen domain`).

## Installeren

Getest op **Linux Mint 22** (Cinnamon). Nodig:

| Onderdeel | Waarvoor |
| --- | --- |
| PHP 8.3+ met `sqlite3`, `mbstring`, `intl`, `zip`, `sodium` | alleen om te ontwikkelen; de gebouwde app heeft eigen PHP |
| Composer 2, Node 22+ | ontwikkelen en bouwen |
| git, rsync, ssh, python3, curl, lftp | gebruikt door `wpopen` |
| Docker + [DDEV](https://ddev.com) | lokale sites |
| VS Code (`code`) | optioneel, editor is instelbaar |
| `gh` | optioneel: privé GitHub-repo per site |

Alleen de app gebruiken: zie de installatieregel bovenaan. Om zelf te ontwikkelen draai je eerst `bash bin/install-mint` (alle programma's) en daarna:

```bash
git clone https://github.com/ICTtrying/bd-deck.git ~/Projects/bd-deck && cd ~/Projects/bd-deck
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run build
bash bin/wpopen install                 # zet wpopen + wplive in ~/.local/bin
```

`wpopen install` vervangt een bestaande `~/.local/bin/wpopen`. Je sitelijst (`~/.config/wpsites/sites`) blijft staan en wordt automatisch aangevuld met de nieuwe velden (modus en provider).

## Starten

**Als desktop-app (ontwikkelen)**

```bash
php artisan native:migrate   # eenmalig, en na nieuwe migraties
php artisan native:run
```

Bij de eerste start kies je een hoofdwachtwoord. NativePHP start zelf twee queue-workers: `default` voor lange acties (bouwen, migreren, pushen) en `quick` voor korte (test, updates, cache).

**In de browser (snel itereren)**

```bash
composer run dev             # server, queue (default + quick), logs en vite tegelijk
# of los:
php artisan serve
php artisan queue:work --queue=default,quick --timeout=7200
npm run dev
```

In de browser opent "Website openen" e.d. via `xdg-open`; in het desktopvenster via NativePHP. De browserversie gebruikt `database/database.sqlite`, de desktopversie `database/nativephp.sqlite` (in ontwikkeling) of `~/.config/bd-deck/` (gebouwd).

## De desktop-app bouwen

**Een release uitbrengen** (voor iedereen, via GitHub):

```bash
# 1. NATIVEPHP_APP_VERSION in .env ophogen (en WPO_VERSION in bin/wpopen als het script veranderd is)
# 2. alles committen
bin/release
```

`bin/release` draait de tests, pusht naar GitHub, bouwt met `php artisan native:publish linux x64` en zet de AppImage als release op [github.com/ICTtrying/bd-deck/releases](https://github.com/ICTtrying/bd-deck/releases). Het GitHub-token komt uit `gh auth token` en gaat alleen als omgevingsvariabele mee; het staat nooit in `.env` of in de app. Geïnstalleerde apps vinden de nieuwe versie zelf (NativePHP-updater, provider `github` in `config/nativephp.php`), en het installatiescript downloadt altijd de nieuwste.

**Alleen lokaal bouwen** (zonder te publiceren):

```bash
php artisan native:build linux x64
```

Resultaat in `nativephp/electron/dist/`:

- `BD Deck-<versie>.AppImage`: direct uitvoerbaar (`chmod +x`, dubbelklikken).
- `bd-deck_<versie>_amd64.deb`: installeren met `sudo apt install ./bd-deck_*.deb`; daarna staat **BD Deck** in het Mint-menu.

**Als app op je computer zetten (zonder sudo)**

```bash
bin/install-desktop            # of: bin/install-desktop --no-desktop
```

Dit zet de nieuwste AppImage in `~/Applications/BD-Deck.AppImage`, het icoon in `~/.local/share/icons`, en een starter in het app-menu (categorie Programmeren) en op het bureaublad. Draai het na elke nieuwe build opnieuw; een geopende app hoeft daarvoor niet dicht. Vastzetten in het paneel: rechtsklik op BD Deck in het menu → *Aan paneel toevoegen*.

Het app-icoon komt uit `public/icon.png` (je favicon), het tray-icoon uit `resources/images/menuBarIcon*.png`. De build:

- draait `npm run build` vooraf (`prebuild` in `config/nativephp.php`);
- haalt `APP_ENV`, `APP_DEBUG` en geheimen uit de meegeleverde `.env`, zodat de app in productiemodus draait;
- laat ontwikkeldatabases, logs en tests weg.

De gebouwde app levert `bin/wpopen` mee en installeert die bij het starten in `~/.local/bin` als de meegeleverde versie nieuwer is (alleen bij het standaardpad).

## wpopen in de terminal

```
wpopen setup [--json]                      controleert of alles geïnstalleerd is
wpopen import                              zoekt bestaande SSH/SFTP-verbindingen
wpopen add <naam> ssh -p 65002 u@host      site toevoegen (zoekt zelf wp-content)
wpopen add sftp://u@host --remote pad --mode sftp --provider wpmudev --url https://…
wpopen update <naam> --provider hostinger  gegevens wijzigen
wpopen remove <naam> [--purge]             uit de lijst halen (en lokaal weggooien)
wpopen list [--json] | info <naam> [--json]
wpopen new <naam> [--title "Titel"]        nieuwe lokale site zonder live-server

wpopen <naam> [--pull] [--dbpull]          lokaal openen (bouwt de 1e keer)
wpopen build|start|stop <naam>
wpopen pull <naam> [--code] [--db] [--uploads]
wpopen reset <naam> [--pull]               dev terug naar main
wpopen rebuild <naam>                      opnieuw migreren vanaf live
wpopen fix <naam> [--plugins]

wplive [naam] [-n] [-m "bericht"] [--db] [--uploads] [--force]
wpopen backup <naam> [--local] [--live-db] [--live-files]
wpopen backups <naam> [--json]
wpopen restore <naam> <back-up> [--local-db] [--live-db] [--live-files]

wpopen migrate <naam> [-p poort] user@host --url https://nieuw [--remote pad]
       [--mode ssh|sftp] [--provider p] [--keep-old] [--no-uploads]
wpopen domain <naam> https://klant.nl      live omzetten naar het definitieve domein (SSH)

wpopen test|updates|cache|login|wp|ssh <naam> …
```

Destructieve commando's vragen in de terminal om bevestiging; `--yes` slaat dat over (de app vraagt zelf en geeft `--yes` mee). `wpopen help` toont alles.

**Git-model per site** (`~/wp-sites/<site>/wp-content`): `main` is de laatst bekende live-staat, de tag `deployed` wijst daarop, `dev` is jouw werk. Ophalen legt live vast op `main` en voegt dat samen in `dev`; live zetten voegt `dev` samen in `main` en uploadt alleen de bestanden die sinds `deployed` veranderd zijn.

**Back-ups** staan in `~/wp-sites/.wpopen-backups/<site>/<tijd>-<soort>/` (map met rechten 700).

## Hoe het in elkaar zit

```
bin/wpopen                          het script: de enige plek met sync-/deploylogica
app/Services/WpOpen/
  WpOpen.php                        roept het script aan (omgeving, timeouts, JSON, fouten)
  WpOpenCommand.php                 bouwt elke aanroep als argumentenlijst (nooit shell-strings)
  CommandRunner.php                 zet een commando in de wachtrij als CommandRun
  SiteRegistry.php                  houdt de sites-tabel gelijk aan `wpopen list --json`
  ScriptInstaller.php               meegeleverde wpopen installeren/bijwerken
app/Jobs/RunWpOpenCommand.php       voert uit, schrijft uitvoer live weg, kan gestopt worden
app/Services/
  Vault.php                         kluis (Argon2id + AES-256-GCM)
  SshKeys.php                       publieke sleutels lezen en nieuwe maken
  DesktopLauncher.php               browser, editor, terminal, map (NativePHP of xdg-open)
  DesktopNotifier.php               systeemmelding na een actie
  AppSettings.php                   instellingen met standaardwaarden
app/Livewire/                       schermen (class-based components)
app/Providers/NativeAppServiceProvider.php   venster, menubalk, tray, script bijwerken
resources/views/components/         huisstijl: knop, badge, panel, modal, site-bridge, …
resources/css/app.css               kleuren en thema's (licht/donker) als CSS-variabelen
lang/en.json, lang/nl/              Engels (bron is Nederlands) en Nederlandse validatie
```

**Gegevens**

| Model | Wat | Waar de waarheid staat |
| --- | --- | --- |
| `Site` | naam, favoriet, notities, live-inlog, laatste test en updates, snapshot | verbindingsgegevens in `~/.config/wpsites/sites` (wpopen) |
| `CommandRun` | elke aanroep van wpopen met status, uitvoer en duur | app |
| `Credential` | kluisitem, geheim versleuteld | app |
| `Setting` | instellingen (sleutel/waarde) | app |
| `User` | de eigenaar, wachtwoordhash en ingepakte kluissleutel | app |

**Ontwerp**: kleuren komen uit het logo. De B (inkt, `#0B3D6E`) staat voor lokaal, de D (lagune, `#1BB5D8`) voor live. Het terugkerende element is de **brug** tussen die twee (`x-site-bridge`), met in het midden wat klaarstaat. Lettertypen: IBM Plex Sans en Plex Mono, bij het bouwen lokaal meegeleverd.

## Een nieuwe functie toevoegen

Voorbeeld: "plugins bijwerken op live".

1. **Script**: voeg een commando toe in `bin/wpopen` (functie `cmd_…` en een regel in de `case` onderaan). Hoog `WPO_VERSION` op, zodat de app de nieuwe versie installeert. Gebruik `step`, `ok`, `note` en `die` voor de uitvoer: de app toont regels die met `→` beginnen als voortgangsstappen.
2. **Actie**: voeg een case toe aan `App\Enums\WpOpenAction` met `label()`, `icon()` en eventueel `locksSite()`.
3. **Commando**: voeg een named constructor toe aan `WpOpenCommand` die de argumenten bouwt en invoer valideert.
4. **Scherm**: roep in een Livewire-component `$this->queueCommand($runner, WpOpenCommand::…())` aan (trait `InteractsWithSites`). Het logboek, de voortgang, de meldingen en het verversen van de site gaan vanzelf.
5. **Vertaling**: nieuwe teksten schrijf je in het Nederlands met `__()`; voeg de Engelse versie toe aan `lang/en.json`.
6. **Tests**: een test voor de argumenten in `tests/Feature/WpOpenCommandTest.php`, en als het script iets naar live stuurt een test in `tests/Feature/WpOpenScriptTest.php`. Die draait het echte script tegen een nep-server (zie `tests/Support/FakeServer.php`).

Raakt de actie live? Laat dan een bevestiging zien (zie `Sites\Show::confirmations()`), en bij destructieve acties het intypen van de sitenaam.

## Beveiliging

- **Installatiesleutel**: de gebouwde app gebruikt niet de `APP_KEY` uit de meegeleverde `.env` (die zit in elke gedeelde AppImage), maar een eigen sleutel per installatie. Anders kon iedereen met de AppImage een sessiebestand (met de ontgrendelde kluissleutel) lezen.
- **Sessies**: bij elke start van de app worden alle sessies weggegooid en de sessiecookie verloopt bij het sluiten, dus na een herstart altijd opnieuw het hoofdwachtwoord.
- **Programma's starten**: browser, editor, terminal en `wpopen` krijgen een omgeving zonder de AppImage-paden (`LD_LIBRARY_PATH`, `XDG_DATA_DIRS` e.d.), anders starten ze soms niet.
- **Inloggen**: hoofdwachtwoord bij elke start, maximaal 5 pogingen per minuut, automatisch vergrendelen na een instelbaar aantal minuten zonder muis of toetsenbord (Ctrl+L of het tray-menu vergrendelt meteen).
- **Kluis**: een willekeurige datasleutel versleutelt de geheimen (AES-256-GCM). Die sleutel staat in de database alleen ingepakt met een sleutel die met Argon2id van je hoofdwachtwoord is afgeleid, en ontsleuteld alleen in de (versleutelde) sessie. Een gekopieerde database is zonder je wachtwoord onleesbaar, en een sessiebestand zonder de installatiesleutel ook. Wachtwoord wijzigen pakt alleen de datasleutel opnieuw in, dus bewaarde geheimen blijven geldig. Wachtwoord vergeten betekent dat de kluis niet meer te openen is.
- **SSH**: alleen publieke sleutels worden getoond. Een SSH-wachtwoord voor het eenmalig plaatsen van je sleutel gaat via `SSH_ASKPASS` en een omgevingsvariabele naar `ssh-copy-id`, nooit als argument en nooit in de proceslijst. In de wachtrij staat het versleuteld (`ShouldBeEncrypted`).
- **Live**:
  - Alles wat live overschrijft vraagt om bevestiging; een database-push of het terugzetten van een live-back-up vraagt ook de sitenaam.
  - Voor elke push worden de live-versies van de bestanden bewaard, en de push stopt als live buiten BD Deck om is aangepast.
  - Bij een database-push blijft de lijst actieve plugins van live staan, en de lokale `dev/dev`-gebruiker wordt van live verwijderd (een echte live-gebruiker `dev` houdt zijn wachtwoord).
- **Tijdelijke PHP-bestanden** (sites met alleen SFTP: database-export, updates, cache, auto-login): willekeurige bestandsnaam, token van 48 tekens, verloopt snel en verwijdert zichzelf.
- **Invoer**: de app bouwt nooit shell-strings; argumenten gaan los naar het script. Namen, paden, SSH-opties en back-up-ID's worden gevalideerd.
- **Verwijderen** vraagt alleen een bevestiging: de live site blijft altijd staan, en bij het weggooien van een lokale site wordt eerst een back-up gemaakt. Acties die live overschrijven (database pushen, live back-up terugzetten, verhuizen) vragen wel de sitenaam.
- **Eén gebruiker**: de app kent één eigenaar en geen rollen. Elke pagina vereist een ingelogde, ontgrendelde sessie.

## Tests

```bash
php artisan test --compact
```

- **Servicelaag**: commando-opbouw, procesomgeving, foutmeldingen, de job met live log en annuleren, kluis en SSH-sleutels.
- **Script end-to-end** (`WpOpenScriptTest`): het echte `bin/wpopen` tegen een nep-server, waarbij nep-versies van `ssh`, `rsync` en `wp` naar een tijdelijke map sturen. Het test push, back-up, de controle op live-wijzigingen, `--force`, rollback, reset, commit tijdens een push, bevestigen, test en WP-CLI, plus de sitelijst (migratie, toevoegen, bijwerken, validatie).
- **Schermen**: instellen, inloggen en rate limiting, vergrendelen, dashboard, push-paneel (bericht en bevestiging), kluis, instellingen en taal.

## macOS en Windows

- **macOS**: de app en het script werken op macOS (bash, DDEV, lftp via Homebrew). Bouwen moet op een Mac: `php artisan native:build mac`. Zonder Apple-ondertekening moet je bij de eerste start rechtsklikken en "Open" kiezen. Twee dingen zijn nog Linux-specifiek en moeten voor macOS worden aangepast: in `DesktopLauncher` start `setsid -f` programma's, en `TerminalApp` kent nog geen Terminal.app of iTerm (het tweede werkt pas na die aanpassing).
- **Windows**: niet ondersteund. `wpopen` heeft bash, DDEV en lftp nodig. Via WSL2 kan het script draaien, maar de app zou dan alles via `wsl.exe` moeten aanroepen.

## Problemen oplossen

| Probleem | Oplossing |
| --- | --- |
| BD Deck staat niet in het menu | `bin/install-desktop` draaien; eventueel uit- en inloggen zodat Cinnamon het menu opnieuw inleest. |
| Een actie blijft "In de wachtrij" | Er loopt al een actie op die site (acties per site lopen na elkaar), of de queue-worker draait niet. In de browserversie: `php artisan queue:work --queue=default,quick --timeout=7200`. |
| "Unable to locate file in Vite manifest" | `npm run build`. |
| Lokaal bouwen vraagt om je computerwachtwoord | Je netwerk of DNS (bv. NetBird, of een router met DNS-rebind-bescherming) lost `*.ddev.site` niet op. BD Deck zet de naam dan zelf in `/etc/hosts` en vraagt daarvoor één keer per site je wachtwoord. Handmatig: `sudo ddev-hostname <site>.ddev.site 127.0.0.1`. |
| WP-admin-knop doet niets | Een inloglink maken duurt een paar seconden (de knop draait dan). Gebeurt er daarna niets, kijk dan in Activiteit of de lokale site draait. |
| Verbindingstest: "geen domein bekend" | De site is toegevoegd met een IP-adres. Vul het live-adres in bij *Gegevens*; nieuwe SSH-sites krijgen het adres automatisch uit WordPress. |
| Lokale site start traag | `ddev start` kan de eerste keer of na een Docker-herstart een minuut duren. |
| "Live is aangepast buiten wpopen om" | Iemand heeft op live bestanden gewijzigd. Haal eerst op (Ophalen → Code), of push met "Ook als live intussen is aangepast"; de live-versies staan dan in de back-up. |
| Wijziging niet zichtbaar op live | Leeg de live cache. Staat de tekst in de database (Enfold-pagina's, menu's, widgets), dan is een bestandspush niet genoeg; zie hoe `borgman_digital_maybe_apply_phone_number()` in het borgmandigital-thema dat met een eenmalige migratie oplost. |
| Inloggen op een server mislukt | Zet je publieke sleutel (pagina SSH-sleutels → Kopiëren) in de WPMU DEV Hub of het SSH-scherm van Hostinger, of geef bij het toevoegen het SSH-wachtwoord op. |
| Hoofdwachtwoord vergeten | De kluis is dan niet te openen. Verwijder de app-database (gebouwde app: `~/.config/bd-deck/database/database.sqlite`, ontwikkeling: `database/nativephp.sqlite`) en stel opnieuw in; je sites komen terug uit wpopen. |
