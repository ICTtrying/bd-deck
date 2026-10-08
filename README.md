# BD Deck

Desktop-app van **Borgman Digital** voor het beheren van WordPress-sites: lokaal (DDEV) en live (WPMU DEV, Hostinger of elders) naast elkaar, met één klik ophalen, live zetten, terugdraaien en inloggen.

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
- **Herstellen**: site repareren, database opnieuw ophalen, terugzetten naar main (werk blijft in een backup-branch), volledig opnieuw migreren vanaf live.
- **Back-ups**: automatisch voor elke push, migratie en database-import; zelf maken; één klik terugzetten (ook een push ongedaan maken).
- **Verbindingstest**: SSH/SFTP, WP-CLI op de server, HTTP-status live en lokaal.
- **Updates**: beschikbare WordPress-, plugin- en thema-updates op live.
- **WP-CLI**: commando's lokaal of op live, met uitvoer.

**Algemeen**

- Dashboard met zoeken, filters (favorieten, lokaal gebouwd, klaar voor live, provider) en favorieten.
- Activiteitenlog met de volledige uitvoer van elk commando, live meelopend.
- Sites toevoegen (zelf laten zoeken of pad opgeven), bewerken en verwijderen. Bestaande verbindingen importeren uit `~/.ssh/config`, FileZilla en VS Code `sftp.json`.
- SSH-sleutels: publieke sleutels tonen en kopiëren, standaardsleutel kiezen, nieuwe ed25519-sleutel maken. Private sleutels worden nooit gelezen of getoond.
- Kluis voor wachtwoorden en API-sleutels, versleuteld met je hoofdwachtwoord.
- Instellingen: sites-map, editor, terminal, SSH-sleutel, taal (Nederlands/Engels), thema (licht/donker/systeem), auto-login, automatisch vergrendelen, meldingen, hoofdwachtwoord wijzigen, script opnieuw laden, systeemcontrole.
- Native menubalk met sneltoetsen (Ctrl+1–4, Ctrl+N, Ctrl+L, Ctrl+,), tray-icoon met favorieten, systeemmeldingen als een actie klaar is, snelzoeker (Ctrl+K).

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

```bash
sudo apt install -y git rsync python3 curl lftp docker.io
sudo usermod -aG docker $USER          # daarna uit- en inloggen
curl -fsSL https://ddev.com/install.sh | bash

git clone <repo> ~/Projects/bd-deck && cd ~/Projects/bd-deck
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

```bash
# versie ophogen in .env (NATIVEPHP_APP_VERSION) bij elke release: daarop draaien de migraties
php artisan native:build linux x64
```

Resultaat in `nativephp/electron/dist/`:

- `BD Deck-<versie>.AppImage`: direct uitvoerbaar (`chmod +x`, dubbelklikken).
- `bd-deck_<versie>_amd64.deb`: installeren met `sudo apt install ./bd-deck_*.deb`; daarna staat **BD Deck** in het Mint-menu.

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

wpopen <naam> [--pull] [--dbpull]          lokaal openen (bouwt de 1e keer)
wpopen build|start|stop <naam>
wpopen pull <naam> [--code] [--db]
wpopen reset <naam> [--pull]               dev terug naar main
wpopen rebuild <naam>                      opnieuw migreren vanaf live
wpopen fix <naam> [--plugins]

wplive [naam] [-n] [-m "bericht"] [--db] [--uploads] [--force]
wpopen backup <naam> [--local] [--live-db] [--live-files]
wpopen backups <naam> [--json]
wpopen restore <naam> <back-up> [--local-db] [--live-db] [--live-files]

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

- **Inloggen**: hoofdwachtwoord bij elke start, maximaal 5 pogingen per minuut, automatisch vergrendelen na een instelbaar aantal minuten zonder muis of toetsenbord (Ctrl+L of het tray-menu vergrendelt meteen).
- **Kluis**: een willekeurige datasleutel versleutelt de geheimen (AES-256-GCM). Die sleutel staat in de database alleen ingepakt met een sleutel die met Argon2id van je hoofdwachtwoord is afgeleid, en ontsleuteld alleen in de (versleutelde) sessie. Een gekopieerde database is zonder je wachtwoord onleesbaar. Wachtwoord wijzigen pakt alleen de datasleutel opnieuw in, dus bewaarde geheimen blijven geldig. Wachtwoord vergeten betekent dat de kluis niet meer te openen is.
- **SSH**: alleen publieke sleutels worden getoond. Een SSH-wachtwoord voor het eenmalig plaatsen van je sleutel gaat via `SSH_ASKPASS` en een omgevingsvariabele naar `ssh-copy-id`, nooit als argument en nooit in de proceslijst. In de wachtrij staat het versleuteld (`ShouldBeEncrypted`).
- **Live**:
  - Alles wat live overschrijft vraagt om bevestiging; een database-push of het terugzetten van een live-back-up vraagt ook de sitenaam.
  - Voor elke push worden de live-versies van de bestanden bewaard, en de push stopt als live buiten BD Deck om is aangepast.
  - Bij een database-push blijft de lijst actieve plugins van live staan, en de lokale `dev/dev`-gebruiker wordt van live verwijderd (een echte live-gebruiker `dev` houdt zijn wachtwoord).
- **Tijdelijke PHP-bestanden** (sites met alleen SFTP: database-export, updates, cache, auto-login): willekeurige bestandsnaam, token van 48 tekens, verloopt snel en verwijdert zichzelf.
- **Invoer**: de app bouwt nooit shell-strings; argumenten gaan los naar het script. Namen, paden, SSH-opties en back-up-ID's worden gevalideerd.
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
| Een actie blijft "In de wachtrij" | Er loopt al een actie op die site (acties per site lopen na elkaar), of de queue-worker draait niet. In de browserversie: `php artisan queue:work --queue=default,quick --timeout=7200`. |
| "Unable to locate file in Vite manifest" | `npm run build`. |
| Lokale site start traag | `ddev start` kan de eerste keer of na een Docker-herstart een minuut duren. |
| "Live is aangepast buiten wpopen om" | Iemand heeft op live bestanden gewijzigd. Haal eerst op (Ophalen → Code), of push met "Ook als live intussen is aangepast"; de live-versies staan dan in de back-up. |
| Wijziging niet zichtbaar op live | Leeg de live cache. Staat de tekst in de database (Enfold-pagina's, menu's, widgets), dan is een bestandspush niet genoeg; zie hoe `borgman_digital_maybe_apply_phone_number()` in het borgmandigital-thema dat met een eenmalige migratie oplost. |
| Inloggen op een server mislukt | Zet je publieke sleutel (pagina SSH-sleutels → Kopiëren) in de WPMU DEV Hub of het SSH-scherm van Hostinger, of geef bij het toevoegen het SSH-wachtwoord op. |
| Hoofdwachtwoord vergeten | De kluis is dan niet te openen. Verwijder de app-database (gebouwde app: `~/.config/bd-deck/database/database.sqlite`, ontwikkeling: `database/nativephp.sqlite`) en stel opnieuw in; je sites komen terug uit wpopen. |
