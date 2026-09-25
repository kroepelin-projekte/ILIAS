# PO/MO-Übersetzungspilot: `TermsOfService` (`tos`)

Dieses Verzeichnis ist **Piloten-Tooling**, kein finales Werkzeug. Es zeigt am Beispiel der
Komponente `TermsOfService` (Modul-Präfix `tos`), wie die ILIAS-Sprachverwaltung von den
`.lang`/DB-basierten Dateien auf gettext-PO/MO-Dateien umgestellt werden kann, bevor auf weitere
Komponenten skaliert wird. Dieses README beschreibt den **aktuellen Stand**; die Entwicklung steht
am Ende unter "Änderungshistorie".

Der Ansatz orientiert sich am ILIAS-Feature-Request ["PO-Files for improving language
handling"](https://docu.ilias.de/go/wiki/wpage_8951_1357) — mit einer bewussten, unten begründeten
Abweichung bei nativem PHP-`gettext()`.

Einziger Konsument ist derzeit `TermsOfService` (`TermsOfService.php` kontribuiert
`$contribute[LanguageFileDirectory::class]` mit einer `ComponentLanguageFileDirectory` für `tos`).

## Überblick: Datenstände pro migriertem Modul

| Stand | Ort | Geschrieben von |
|---|---|---|
| Shipped-`.po` (versioniert, maßgebliche Auslieferung) | `components/ILIAS/TermsOfService/lang/tos_<lang>.po`, `tos.pot` (Dateiname nach Namensschema, siehe unten) | nur `convert_module_to_po.php` |
| Build-Artefakt `.mo` (kompilierter, bereinigter Shipped-Stand, gitignored) | `artifacts/language/<lang>/<modul>.mo`, Index `artifacts/language/index.json` | `php cli/setup.php build` (`ShippedLanguageFilesCompiledObjective`), läuft bei `composer install`/`composer du` |
| Overlay `.po`+`.mo` (pro Installation, **nur das Delta** = lokale Änderungen) | `<client_data_dir>/lang/<Pfad der Directory><modul>_<lang>.po/.mo` (+ `.lock`) | Admin-Edits, Customizing-Werte bei Install/Update, "Lokale Änderungen anwenden" |
| `lng_data`/`lng_modules` (Fallback, Dual-Write) | Datenbank | dieselben Schreibpfade wie bisher; für migrierte Module mit den **bereinigten** Shipped-Werten |

Zur Laufzeit gilt: **Text eines Moduls = Shipped-Stand + Overlay** (`array_replace`, Overlay gewinnt).
Eine Sprache zu installieren schreibt für migrierte Module **keine Datei** mehr.

Alle Pfade setzt `ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths` zusammen; kein
anderer Code baut sie selbst.

## Das Konvertierungswerkzeug: `convert_module_to_po.php`

```bash
php components/ILIAS/Language/tools/po-migration/convert_module_to_po.php tos de components/ILIAS/TermsOfService/lang
```

Aufruf: `convert_module_to_po.php <modul> [referenzSprache] [ausgabeOrdner]`. Ohne drittes Argument
landet die Ausgabe in `tools/po-migration/output/<modul>/`.

Das Skript liest alle `lang/ilias_<sprache>.lang`-Dateien, extrahiert die Zeilen des Moduls (jede
Zeile wird wie im ILIAS-Installer getrimmt) und schreibt über `TranslationCatalog::toPoString()`, also
mit `gettext/gettext` (siehe "Gettext-Bibliothek"):

- ein POT-Template (`<modul>.pot`) — Keys ohne Übersetzung,
- je Sprache eine PO-Datei (`<modul>_<sprache>.po`) mit Standard-Headern (`Content-Type`,
  `Language`, `X-Domain` usw.). Header einer bereits vorhandenen Zieldatei (z. B. `Plural-Forms`)
  werden übernommen.

Es schreibt **keine `.mo`** und nichts in die Datenbank. Anschließend liest es jede geschriebene
`.po` mit `TranslationCatalog::fromPoFile()` (`StrictPoLoader`) wieder ein und vergleicht jeden Wert
mit der Quelle (Exit-Code 1 bei Abweichung). Doppelte Identifier eines Moduls in einer `.lang`-Datei
führen zum Abbruch, bevor irgendetwas geschrieben wird.

Das Skript definiert keine benannten Klassen oder Funktionen (nur Closures), damit es nicht in
Composers Autoload-Classmap landet und von der Setup-Klassensuche eingebunden wird. Es nutzt die
Bibliothek bewusst über denselben Adapter wie die Laufzeit (nicht direkt `Gettext\…`), damit Tool und
Laufzeit identische Dateien schreiben und die Absicherungen des Adapters (z. B. der Wert `"0"`) auch
für geshippte Dateien gelten.

Das Skript schreibt **kein `msgctxt`** mehr und kennt `--pattern=<schema>` (Standard `<modul>_%s`,
geprüft mit `MigratedLanguageFilePaths::assertValidShippedFileNamePattern()`); die POT heißt nach
dem Schema ohne `%s` (z. B. `tos.pot`, `ilias.pot`). Die `tos`-Dateien sind damit neu erzeugt (nur
die `msgctxt "tos"`-Zeilen entfallen; Werte, Flags, Kommentare und Header unverändert).

### Design-Entscheidungen

- **`msgid` ist der ILIAS-Sprachkey** (z. B. `tos_agreement`), nicht der englische Text —
  entspricht dem `$lng->txt($key)`-Modell.
- **Kein `msgctxt`** (früher `msgctxt` = Modul): Jedes Modul hat eigene Dateien, ein Eintrag ohne
  `msgctxt` gehört zum Modul seines Verzeichnisses (siehe "Kontext-Regel"); Dateien mit `msgctxt` =
  Modul (Altbestand) werden weiter gelesen. So bleibt eine Datei auch bei einem umbenannten Modul
  gültig (Plugin → Komponente).
- **Kommentar-Klassifizierung:** ein `###`-Kommentar, der ein "new variable"/"add new
  translation"-Marker ist, wird als `#, fuzzy` exportiert (Platzhalter, noch nicht übersetzt);
  alle anderen Kommentare als `#.`-Kommentar.
- **Fuzzy-Einträge werden in die `.mo` mitkompiliert** (anders als der `msgfmt`-Standard): Nutzer
  einer unübersetzten Sprache sehen heute den englischen Platzhaltertext; ein Weglassen würde daraus
  `-key-` machen. Leere Übersetzungen werden dagegen weggelassen.

### Nebenbefund: Übersetzungsstand von `tos`

`de`/`en`/`es`/`hu`/`ja` sind vollständig (0 von 17 fuzzy). `ar`, `da`, `el`, `fa`, `ka`, `lt`,
`ro`, `sk`, `sq`, `sr`, `tr`, `uk`, `vi`, `zh` sind für `tos` zu 100 % unübersetzt (17 von 17
fuzzy).

## Dateinamen und Kontext

### Namensschema der Shipped-Dateien

`getPrefix()` einer `LanguageFileDirectory` ist **ausschließlich der Modulname** (Schlüssel in
`lng_data`, Artefakt, Overlay, `txt()`/`loadLanguageModule()`). Der Dateiname der Shipped-`.po` ist
davon entkoppelt:

- `ComponentLanguageFileDirectory` hat einen optionalen 4. Konstruktorparameter
  `?string $shipped_file_name_pattern` (ohne `.po`, `%s` = Sprach-Key), z. B. `'ilias_%s'` für
  `ilias_<lang>.po`. Standard ist `'<prefix>_%s'` — `TermsOfService` bleibt damit unverändert.
- Das Schema wird über das optionale Zusatz-Interface `NamesShippedLanguageFiles`
  (`getShippedFileNamePattern()`) bereitgestellt; `LanguageFileDirectory` selbst ist unverändert.
- Validierung (`MigratedLanguageFilePaths::assertValidShippedFileNamePattern()`): genau ein `%s`,
  sonst nur `[A-Za-z0-9_.-]`, kein `/`, kein `..`, kein weiteres `%`.
- Gesucht wird über `MigratedLanguageFilePaths::findShippedPoFiles()` (Glob plus Regex nach dem
  Schema, nur zweistellige Sprach-Keys; `tos_pt_BR.po` wird z. B. nicht gelistet).
- Overlay und Artefakt werden **nach dem Modul** benannt, nie nach dem Schema.

### Kontext-Regel (`msgctxt`)

`MigratedLanguageFileSync::moduleEntries()`/`findModuleEntry()`, genutzt beim Lesen, im Build und im
Delta-Vergleich:

- Einträge mit `msgctxt` = Modul gehören zum Modul.
- Einträge **ohne** `msgctxt` (Kontext `null`) gehören ebenfalls zum Modul des Verzeichnisses.
- Ein ausdrückliches `msgctxt ""` ist ein eigener gettext-Kontext und wird wie jeder fremde Kontext
  ignoriert.
- Gibt es für einen Key beide Varianten, gewinnt der Eintrag mit Modulkontext.

## Gettext-Bibliothek (`gettext/gettext`)

PO/MO werden mit der Bibliothek [`gettext/gettext`](https://github.com/php-gettext/Gettext) gelesen
und geschrieben (direkte Abhängigkeit in der Root-`composer.json`, `^5.7.3`; bis dahin nur transitiv
über `simplesamlphp/simplesamlphp` installiert). Verwendet werden ausschließlich
`Gettext\Translations`, `Gettext\Translation`, `Loader\StrictPoLoader`, `Loader\MoLoader`,
`Generator\PoGenerator` und `Generator\MoGenerator`. **Der `Scanner`-Teil der Bibliothek (Extraktion
aus Quellcode) wird nicht verwendet und darf nicht verwendet werden** — nur dort betrifft der noch
unveröffentlichte PHP-8.5-Fix der Bibliothek Code. Mit v5.7.3 bestehen unter PHP 8.4.25 und 8.5.4 die
eigene Testsuite der Bibliothek und ein PO/MO-Roundtrip (Kontext, Plural, Flags, Kommentare,
Mehrzeiler/Escapes) ohne Deprecations/Notices.

Die Bibliothek ist hinter einem schmalen Adapter gekapselt; kein anderer Code der Komponente
importiert etwas aus `Gettext\`, ein Versionswechsel bleibt also lokal:

- `TranslationCatalog` — Katalog (Header + Einträge) und einziger Ort, der die Dateiformate liest
  und schreibt (`fromPoFile()`/`fromPoString()`, `toPoString()`, `toMoString()`,
  `readMoTranslations()`, `readMoTranslationsFromString()`). `clone` erzeugt eine unabhängige Kopie
  (`__clone()` klont die gettext-Einträge und Header mit),
- `TranslationEntry` — ein Eintrag (Kontext, ID, Übersetzung, `#`-/`#.`-Kommentare, Flags),
- `AtomicFileWriter` — keine gettext-Funktionalität: Tempdatei im selben Verzeichnis + `rename()`;
  mit `$flush_to_disk = true` (Standard) vorher `fsync()`, für Build-Artefakte `false`.

Was die Bibliothek selbst leistet und was der Adapter absichert:

- **Kaputte `.po` wird erkannt.** `StrictPoLoader` folgt den Regeln der GNU-gettext-Tools und wirft
  bei Syntaxfehlern (fehlendes `msgstr`, offene Anführungszeichen, doppelte Einträge, Lücken in
  `msgstr[n]` …); der tolerante `PoLoader` wird nicht verwendet. Der Adapter macht daraus — ebenso
  aus unlesbaren Dateien und aus PHP-Warnings/-Fehlern der Bibliothek — eine `RuntimeException`
  (Deprecations nicht: sie sagen etwas über die Bibliothek, nicht über die Datei). Ein UTF-8-BOM wird
  übersprungen, obsolete Einträge (`#~`) werden verworfen. Nicht-fatale Hinweise des Loaders (z. B.
  fehlender `Language`-Header in `tos.pot`) sind keine Fehler.
- **Kein Trimmen.** `StrictPoLoader` entfernt nur das eine Leerzeichen nach dem Kommentar-Marker;
  Kommentare wie `# original: ...`/`# original_escaped: ...` bleiben inklusive Rand-Leerzeichen exakt
  erhalten. Header-Werte trimmt die Bibliothek (unkritisch). Zeilenumbrüche in Kommentaren ersetzt
  der Adapter durch Leerzeichen (`PoGenerator` schreibt Kommentare unverändert).
- **Der Wert `"0"`.** `PoGenerator` und `MoGenerator` halten eine Übersetzung `"0"` für leer (würde
  als `msgstr ""` geschrieben bzw. aus der `.mo` weggelassen; in den `.lang`-Dateien kommt `"0"` real
  vor). Der Adapter übergibt stattdessen `"0\0"`: `PoGenerator` entfernt das NUL beim Kodieren (die
  `.po` enthält exakt `"0"`), die `.mo` enthält den C-String `"0"`, `readMoTranslations()` schneidet
  am NUL ab. Kontext bzw. `msgid_plural` `""`/`"0"` kann die Bibliothek gar nicht schreiben — der
  Adapter wirft dann, statt still zu verfälschen.
- **Header** schreibt `PoGenerator` unkodiert; der Adapter kodiert die Werte vorher. Einen Wert mit
  Zeilenumbruch lehnt `setHeader()` ab (`InvalidArgumentException`), eine geladene Datei mit einem
  solchen Wert (escaptes `\r`) gilt als kaputt (`RuntimeException`). Ein escaptes `\n` im Header-Block
  behandelt die Bibliothek als Fortsetzungszeile und zieht den Wert zusammen (`a\nb` → `ab`).
- **Kaputte `.mo` wird erkannt.** `MoLoader` wirft nur bei falscher Magic Number; eine
  abgeschnittene Datei liefert je nach Stelle still gekürzte/fehlende Einträge, PHP-Warnings oder
  einen `TypeError`. Der Adapter prüft deshalb vorab Magic Number, Index-Tabellen und alle
  String-Bereiche gegen die Dateigröße und wirft eine `RuntimeException`.
- **Deterministische `.mo`** (`MoGenerator` mit `includeHeaders(true)`), Fuzzy-Einträge werden
  mitkompiliert, leere weggelassen — Voraussetzung für die `.mo`-Selbstheilung per Byte-Vergleich
  und für den Artefakt-Index.
- **Byte-Identität:** Alle 32 geshippten `tos`-Dateien (`tos.pot`, `tos_*.po`) erzeugt
  `convert_module_to_po.php` mit der Bibliothek byte-identisch neu; `fromPoFile()` → `toPoString()`
  ergibt ebenfalls exakt die Datei.
- **Einschränkungen:** Kommentare eines Eintrags sind eine Menge (ein gleicher — nach PHPs losem
  Vergleich — zweiter Kommentar wird nicht doppelt gespeichert), Flags werden sortiert. Pluralformen
  werden genau in der Anzahl geschrieben, die der `Plural-Forms`-Header angibt (fehlende leer,
  überzählige entfallen); eine fehlende und eine leere Pluralform sind nicht unterscheidbar.

## Build-Artefakt: kompilierter Shipped-Stand

`ShippedLanguageFilesCompiledObjective` (über `ilLanguageSetupAgent::getBuildObjective()`, vorher
`NullObjective`) kompiliert jede Shipped-`.po` aller kontribuierten Verzeichnisse nach
`artifacts/language/<lang>/<modul>.mo`. `ShippedTranslations::compile()` ist dabei derselbe
Kompilierweg wie der Laufzeit-Fallback.

- Der Fingerprint umfasst auch `MigratedLanguageFileSync` (dort liegt die Kontext-Regel), aktuell
  `FORMAT_VERSION` 2.
- **Inkrementell:** Index `artifacts/language/index.json` mit Hash der `.po` sowie Größe und Hash
  des Artefakts; ein Fingerprint des Kompiliercodes (`FORMAT_VERSION` plus Quelldateien von
  Policy/ShippedTranslations/Catalog/Entry) erzwingt bei Codeänderungen einen Vollbau. Ein
  abgeschnittenes oder verändertes Artefakt wird neu gebaut, ein defekter Index führt zum Vollbau.
- **Schreiben:** atomar (temp + `rename`) **ohne fsync** — das Artefakt ist jederzeit reproduzierbar.
  Scheitert `touch()` (fremder Eigentümer), wird das Artefakt neu geschrieben.
- **Aufräumen:** verwaiste Artefakte (keine `.po` mehr) und liegengebliebene Temp-Dateien werden
  entfernt; gelöscht wird nur, wenn `realpath(dirname)` das Zielverzeichnis oder ein direktes
  Unterverzeichnis ist (kein Löschen über Symlinks).
- **Markup:** Unzulässiges Markup (`TranslationMarkupPolicy`, siehe unten) wird beim Kompilieren
  entfernt und bei jedem Build als Warnung gemeldet; Steuerzeichen im Log werden ersetzt
  (`PlainLogText::of()`). **Der Build bricht nicht ab**, auch nicht bei Fehlern in einzelnen Dateien
  (`\Throwable` pro Datei).
- **Gemessen:** 31 `tos`-Dateien voll 0,014 s, inkrementell 0,002 s; synthetisch alle
  4.774 Module×Sprachen aus den Root-`.lang` voll 4,3 s, inkrementell 0,11 s.
- Die Artefakt-Ablage in einem eigenen Unterverzeichnis mit Index weicht vom üblichen Schema
  `artifacts/<md5(Klasse)>.php` ab; mit den Setup-Maintainern noch abzustimmen.

**Deploy-Voraussetzung:** Nach jeder Änderung einer Shipped-`.po` muss `setup build` laufen. Die
Aktualität wird über mtime in Sekunden entschieden; ein Deploy, das mtimes erhält (`rsync -a`,
`tar`, `cp -p`), kann eine geänderte `.po` älter aussehen lassen als das vorhandene Artefakt, das
dann veraltet ausgeliefert wird.

## Markup-Prüfung (`TranslationMarkupPolicy`)

Zentrale Klasse für das in Übersetzungen zulässige HTML, auf Basis von `Dom\HTMLDocument` (ext-dom,
HTML5-konformer Parser wie im Browser, kein Regex). HTMLPurifier wird bewusst nicht verwendet: Er
serialisiert jede Eingabe neu, zulässige Werte blieben nicht byte-identisch.

- **Tags:** `a`, `b`, `bdo`, `br`, `code`, `div`, `em`, `gap`, `h3`, `i`, `img`, `li`, `ol`, `p`,
  `pre`, `s`, `small`, `span`, `strike`, `strong`, `sub`, `sup`, `u`, `ul` (Liste aus
  `docs/development/language.md` plus `h3`, `small`, `s`, `sub`).
- **Attribute** (Namen case-insensitiv): `a[href|target|rel|title]`, `img[src|alt]`,
  `span[class|style]`, `p|div[align]`, sonst keine. `href`: `http`, `https`, `mailto` oder relativ
  (inkl. `#…`); `src`: `http`, `https` oder relativ. Geprüft wird der dekodierte Wert (Entities,
  Whitespace/Steuerzeichen), `javascript:`/`data:` sind ausgeschlossen.
- **Weitere Verstöße:** Kommentare (auch kaputtes Markup wie `</ i>`), jedes `<` in einem
  Attributwert (Ausbruch aus `<title>`/`<textarea>`/`toJS()`), ein Wert, der in einem unfertigen Tag
  endet (Erkennung per Sentinel `U+E000`), und ein `<` als letztes Zeichen (sonst XSS durch direktes
  Aneinanderhängen zweier Werte).
- **Doppelter Parse:** als Body-Inhalt (findet `<body>`/`<html>`-Attribute) und als Tabellen-Fragment
  (dort werden auch `td`/`tr`/`caption` zu Elementen).
- Text wie `a <= b` oder `<<` ist kein Tag und bleibt zulässig; **zulässige Werte bleiben
  byte-identisch**.
- API: `findViolations()`, `isAllowed()`, `sanitize()` (entfernt Unzulässiges, Text entfernter Tags
  bleibt), `findInvalidValues()`, `findInvalidChangedValues($values, $current, $shipped)`.

Wo was passiert:

| Stelle | Verhalten |
|---|---|
| Build, Laufzeit-Fallback, Plugin-`.po` | bereinigen (`sanitize()`) + Warnung, kein Abbruch |
| `lng_data`/`lng_modules` migrierter Module | bereinigte Shipped-Werte (`MigratedLanguageFileSync::databaseValues()`), damit der DB-Fallback die Bereinigung nicht umgeht; der Delta-Vergleich bleibt gegen den **rohen** `.po`-Wert |
| Admin-Bearbeitung (`ilObjLanguageExtGUI::saveObject()`, ersetzt `ilUtil::stripSlashes()`), Upload-Import, `AddLanguageEntry` | **ablehnen**, nichts speichern, Meldung nennt alle betroffenen Keys (escaped, ohne Werte; max. 20, dann `… (+N)`, vollständige Liste im Log) |
| Customizing (`.lang.local`) bei Install/Update/"lokale Datei laden" | betroffene Einträge überspringen und melden (Setup-WARNING, Ausgabefeld `invalid_markup_customizing_entries` der Activities, GUI-Meldung), Rest wird übernommen |
| "clear" (Import der ausgelieferten Datei) | ungeprüft (Shipped-Stand) |
| Setup-Update | meldet unzulässige **lokale Altbestände** (`lng_data`-local_change und Overlay-Deltas) einmal pro Lauf als WARNING, ändert nichts |

**Geprüft wird nur, was sich ändert:** Ein Wert wird nur geprüft, wenn er vom aktuellen Wert
(DB/Overlay) **und** vom mitgelieferten Wert desselben Keys abweicht
(`ilObjLanguageExt::findInvalidMarkupOfChangedValues()`). Unveränderte mitgelieferte Verstöße (in den
Root-`.lang` z. B. `<br \>` in `file#:#copyright_inherited_info` oder `a[folder]` in
`common#:#enable_webdav_info`) blockieren damit weder das Speichern einer Tabellenseite noch den
Re-Import eines Exports. In den Import-Modi `keepall`/`keepnew` werden behaltene Werte nicht geprüft.
`AddLanguageEntry` prüft jeden Wert (neu per Definition).

Meldungstexte verwenden den generischen Key `common#:#form_input_not_valid`, gefolgt von den
betroffenen Keys (entschieden 2026-09-25: keine eigenen Sprach-Keys).

Die mitgelieferten Verstöße in `lang/ilias_*.lang` (158 Werte in 42 Keys) sind als Hinweis an die
Pflege der Sprachdateien in `SHIPPED_MARKUP_VIOLATIONS.md` aufgelistet.

`ilLanguage::toJSMap()` kodiert Key und Wert zusätzlich mit
`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.

## Overlay: Installations-eigene Delta-Dateien

Der git-getrackte `lang/`-Ordner einer Komponente wird zur Laufzeit nie beschrieben. Lokale
Änderungen landen in einem `.po`+`.mo`-Paar unterhalb des Client-Datenverzeichnisses, das den Pfad
der Directory spiegelt:

```
<client_data_dir>/lang/<Pfad der Directory><modul>_<lang>.po
<client_data_dir>/lang/<Pfad der Directory><modul>_<lang>.mo
<client_data_dir>/lang/<Pfad der Directory><modul>_<lang>.lock
```

Damit bleiben lokale Anpassungen — wie früher in `lng_data` — Instanz-Zustand und markieren keine
versionierte Datei als geändert.

- **Nur das Delta** (`MigratedLanguageFileSync::sync()`, Entscheidung in `belongsInDelta()`/
  `resolveLocalValue()`): ein Eintrag steht im Overlay, wenn sein Wert vom **rohen** Shipped-Wert
  abweicht oder der Key shipped nicht existiert (`AddLanguageEntry`, Customizing).
  `sync()` bekommt weiterhin den vollständigen Stand eines Moduls und rechnet das Delta selbst.
- **Leerer Wert = zurück auf shipped** (entschieden 2026-09-25): gilt in Overlay **und**
  DB (`ilObjLanguageExt::saveValues()` schreibt dann den Shipped-Wert mit `local_change` `NULL`).
  Umzustellen ist nur `resolveLocalValue()`. Ein leerer Wert für einen nicht mitgelieferten Key
  bleibt leer.
- **Wert == shipped** → Eintrag raus; **Delta leer** → `.po` und `.mo` werden unter Lock gelöscht.
  Die `.lock`-Datei bleibt (entfernt nur `removeOverlay()` bei der Deinstallation), damit Wartende
  nicht ihre Lock-Versuche aufbrauchen.
- **Leeres Delta und kein Overlay** → nichts wird geschrieben oder gelockt. Eine Sprache ohne lokale
  Änderungen hat kein Overlay.
- **Altbestand:** Volle Overlays früherer Stände schrumpfen beim nächsten abgleichenden Schreiben
  (Setup-Update, Neuinstallation) auf das Delta; eine eigene Migration gibt es nicht. In der
  Dev-Instanz blieb nach dem Update nur `tos_de` mit seiner einen lokalen Änderung.
- **`.mo` ohne `.po`** (die `.po` trägt die Buchführung) oder ein Overlay-Pfad, der keine reguläre
  Datei ist: Lesen und Schreiben werfen eine `RuntimeException`, nichts wird ersetzt oder gelöscht;
  die Aufrufer fallen auf `lng_modules`/`lng_data` zurück. Eine lokale Änderung geht so nicht still
  verloren.
- Overlay-Einträge werden **ohne `msgctxt`** geschrieben. Altbestand mit `msgctxt` = Modul wird
  gelesen und beim nächsten Schreiben ohne Kontext übernommen (`TranslationEntry::withContext(null)`;
  Werte, Kommentare, Flags, `original` und `local_change` bleiben erhalten).
- Eine nicht parsebare Overlay-`.po` (bei vorhandener `.po`) wird beim nächsten Schreiben neu
  aufgebaut; dabei gehen nur die bisherigen `local_change`-Zeitstempel verloren.
- Beide Dateien werden atomar mit fsync geschrieben (erst `.po`, dann `.mo`): die `.po` nur, wenn
  sich ihr Inhalt geändert hat, die `.mo` immer dann, wenn sie nicht byte-genau dem entspricht, was
  `TranslationCatalog::toMoString()` erzeugt. Eine fehlende, abgeschnittene oder veraltete `.mo`
  neben einer unveränderten `.po` wird so beim nächsten Schreiben repariert.
- Ohne DB ist das Overlay die einzige Stelle lokaler Änderungen — deshalb bleibt fsync hier, anders
  als beim Build-Artefakt.

### Locking

- Jeder Read-Modify-Write eines Overlays läuft unter `flock()` auf der `.lock`-Datei
  (`withOverlayLock()`, re-entrant pro Request).
- `LanguageInstallationManager::insertLanguage()` sperrt jedes migrierte Modul **mit Overlay** für
  den ganzen Lauf (geschachtelt). Module **ohne Overlay** werden nicht gesperrt (sonst entstünden
  dauerhaft ~4.774 Lock-Dateien) und mit `sync(..., $expected_overlay_exists = false)` geschrieben:
  entsteht ihr Overlay während des Laufs (Admin-Edit), bleibt es unangetastet und das Modul wird als
  "nicht geschrieben" gemeldet.
- **Feste Lock-Reihenfolge:** Mehrere Locks werden immer nach Modulname sortiert
  (`sort(SORT_STRING)`) erworben, nie in der Reihenfolge von `getDirectories()`. Sonst könnten Setup
  und GUI ("Lokale Änderungen entfernen/anwenden") sich gegenseitig blockieren (`flock` ohne Timeout).
- Kann eine Lock-Datei nicht geöffnet werden (Rechte, EMFILE), läuft der Schreibvorgang ohne Lock
  und es wird gewarnt.
- Restrisiko (README der Komponente): `lng_data` wird in einem Batch geschrieben; bei einem
  gleichzeitigen Admin-Edit an einem Modul ohne Overlay kann die Installation dessen DB-Zeile
  überschreiben, das ausgelieferte Overlay behält den Edit.

### Betriebsvoraussetzung: Setup läuft als Webserver-Nutzer

Das Overlay wird sowohl vom Setup als auch vom Webserver (Admin-GUI) geschrieben. **Das Setup muss
deshalb als Webserver-Nutzer laufen** (bzw. als Eigentümer des Client-Datenverzeichnisses). Läuft es
z. B. als root, entstehen Overlay-Dateien, die der Webserver später nicht mehr ersetzen kann. Seit
dem Delta-Overlay betrifft das nur noch Sprachen mit lokalen Änderungen oder Customizing-Werten.

- Vor dem Schreiben prüft `ilLanguagesInstalledAndUpdatedObjective`, ob die Overlay-Verzeichnisse
  aller migrierten Module beschreibbar bzw. anlegbar sind
  (`MigratedLanguageFileSync::findUnwritableOverlayDirectories()`), und meldet Probleme über die
  Admin-Interaction (`inform()`); zusätzlich, wenn das Setup unter einer anderen Benutzer-ID läuft
  als der Eigentümer des Client-Datenverzeichnisses. Konnte ein Overlay trotzdem nicht geschrieben
  werden, wird auch das nach dem Lauf gemeldet. Das Update bricht nicht ab.
- In der Admin-GUI wird ein nicht geschriebenes Overlay als Fehlermeldung angezeigt
  (`lng_po_overlay_not_written`, `lng_po_overlay_not_written_languages`) und geloggt. Das Signal wird
  als Rückgabewert durchgereicht: `ilObjLanguage::replaceLangModule()` (`bool`),
  `ilObjLanguageExt::_saveValues()`/`_deleteValues()`/`importLanguageFile()` (Liste der Module),
  `LanguageInstallationManager::insertLanguageFor*()` und die Activities `InstallLanguage`,
  `UpdateLanguage`, `RemoveLocalLanguageChanges`, `AddLanguageEntry` (Ausgabefeld
  `overlay_write_failed_language_keys`).

### Client-Datenverzeichnis

`MigratedLanguageFilePaths::resolveClientDataDir()`:

1. `CLIENT_DATA_DIR`, falls definiert;
2. sonst aus `ilias.ini.php`: `[clients] datadir` + `/` + `[clients] default` (relatives `datadir`
   wird relativ zum ILIAS-Wurzelverzeichnis aufgelöst). Es wird nicht über Unterordner des
   Datenverzeichnisses geraten.

Im Setup setzt `ilLanguagesInstalledAndUpdatedObjective` das Verzeichnis aus der Setup-Environment
(ilias.ini-Resource + Client-ID, via `MigratedLanguageFilePaths::fromDataDirAndClientId()` und
`ilSetupLanguage::setClientDataDir()`); ohne diese Angaben greift der obige Resolver.

Existiert das Client-Verzeichnis (noch) nicht, ist das Ergebnis `null`: dann wird kein Overlay
gepflegt (die DB wird trotzdem geschrieben). `null` führt nie dazu, stattdessen in die Shipped-Datei
zu schreiben.

## Laufzeit-Integration in `ilLanguage`

`ilLanguage` findet die kontribuierte Directory über `$DIC[LanguageFileDirectoryManager::class]`
(Bridge in `AllModernComponents.php`) anhand von `getPrefix() === $modul`. **Ein Modul gilt für eine
Sprache als migriert, sobald die Shipped-`.po` existiert** (vorher: sobald eine Overlay-`.mo`
existierte).

Geliefert wird der Shipped-Stand (`ShippedTranslations::read()`: das Build-Artefakt; ist die `.po`
neuer (mtime) oder fehlt das Artefakt, wird die `.po` direkt kompiliert, mit identischem Ergebnis)
mit der Overlay-`.mo` darüber (`array_replace`, Overlay gewinnt). Das geschieht **nur, wenn
`CLIENT_DATA_DIR` definiert ist** — vorher (z. B. in Setup-Objectives erzeugte
`ilLanguage`-Instanzen) ist `lng_modules` die richtige Quelle.

Zwei Lesepfade, gemeinsam über `loadFromMigratedLanguageFile()` (mit Request-Cache pro
`<modul>|<sprache>`):

- **`loadLanguageModule()`** — der zentrale Pfad hinter `txt()`,
- **`_lookupEntry()`** — hinter `txtlng()` und dem Fallback-Modul-Parameter von `txt()`.

Ohne kontribuierte Directory oder ohne Shipped-`.po` für die Sprache greifen unverändert
`lng_modules`/`lng_data`.

**Robustheit:** Ein defektes oder unlesbares Overlay-`.mo` oder eine nicht kompilierbare
Shipped-`.po` erzeugt eine Warnung im Log (Komponenten-Logger `lang`, sonst `error_log()`), und der
Request fällt auf `lng_modules` zurück — kein Absturz. Ein defektes Artefakt ist eine Warnung, ein
fehlendes eine Notice (einmal pro Request); in beiden Fällen wird die `.po` direkt kompiliert
(gemessen ~1 ms pro Modul).

**Entschiedene Randfälle** (2026-09-25):

- Der migrierte Stand wird **nur für installierte Sprachen** geliefert. Für eine nicht (mehr)
  installierte Sprache fällt `ilLanguage` wie bei nicht migrierten Modulen zurück (in der Regel
  `-key-`), damit eine deinstallierte Sprache nicht teilweise weiter ausgeliefert wird und keine
  gemischte Ausgabe entsteht (Option B; die Liste installierter Sprachen ist gecacht).
- Ein lokal **leerer Wert** ist im Overlay nicht darstellbar (`toMoString()` lässt leere Werte weg);
  deshalb die Regel "leer = zurück auf shipped" (siehe Overlay).

## Cache-Verhalten

`loadLanguageModule()` prüft den migrierten Stand **vor** `$this->cached_modules` (aus
`ilCachedLanguage`, das immer aktiv ist und `lng_modules` spiegelt). Ein migriertes Modul gewinnt
deshalb unabhängig vom Cache-Zustand; ein manueller Cache-Flush ist für die Anzeige migrierter Module
nicht nötig. Für nicht migrierte Module (und den Fallback) greift der Cache wie bisher; nach einem
Schreiben von `lng_modules` invalidiert ihn der `LanguageInstallationManager` selbst.

Nach jedem Overlay-Schreiben leert `ilLanguage::invalidateMigratedLanguageFileCache()` den
Request-Cache für das Modul/die Sprache.

`MigratedLanguageFileSync::readShippedPo()` hält geparste Shipped-`.po` pro Request (Schlüssel:
Inhalts-Hash, max. 512 Einträge) und leert den Cache beim Wechsel der Sprache (Speicher bei
`setup update` über viele Sprachen). Nur `sync()` bekommt einen Klon; lesende Aufrufer kopieren die
Werte in Arrays.

**Prüfen, dass wirklich das Overlay gelesen wird:** einen Wert nur im Overlay ändern (`.po` und
`.mo` neu erzeugen, DB unangetastet) und die Seite neu laden — oder umgekehrt nur die DB-Zeile
ändern; angezeigt wird der Overlay-Wert.

## Uniqueness: Kollisionen zwischen Modulen

Auf Dateiebene gibt es keine Kollisionen: jedes Modul hat eigene Dateien (Shipped-`.po`, Artefakt,
Overlay), der `msgctxt` wird dafür nicht gebraucht. `txt($topic)` nimmt aber weiterhin nur ein Topic
entgegen (die Module werden zur Laufzeit flach zusammengeführt); `ilLanguage::logCrossModuleKeyCollisions()` loggt deshalb, wenn zwei migrierte
Module denselben Identifier mit unterschiedlichem Wert liefern. Kollisionen zwischen einem migrierten
und einem nicht migrierten Modul werden nicht erkannt (der Legacy-Pfad führt keine
Pro-Topic-Modulzuordnung).

## Pluralformen

Werden **nicht unterstützt**. Die frühere Methode `ilLanguage::ntxt()` wurde entfernt (repo-weit
ungenutzt), ebenso die Abhängigkeit auf `Gettext\Translator`. `convert_module_to_po.php` erzeugt nur
`msgid`/`msgstr`-Paare. Über `gettext/gettext` werden `msgid_plural`/`msgstr[n]` gelesen und
unverändert zurückgeschrieben (Einschränkungen siehe "Gettext-Bibliothek"), aber nicht ausgewertet;
der Adapter bietet dafür keine API. Plural-Lookups sind eine künftige Option.

## Schreibpfad

Admin-Edits ("Sprachvariablen anpassen", "Neue Variable hinzufügen", Löschen einzelner Einträge,
Imports über die GUI) und `ilPluginLanguage::updateLanguages()` enden in
`ilObjLanguage::replaceLangModule($sprache, $modul, $eintraege, $refresh_original_from_shipped = false)`.
Das schreibt wie bisher die `lng_modules`-Zeile (Dual-Write; für migrierte Module mit bereinigten
Shipped-Werten, `databaseValuesOf()`) und übergibt anschließend dieselbe `identifier => wert`-
Zuordnung (roh) an `MigratedLanguageFileSync::sync()`, das daraus das Delta schreibt:

- nicht mehr übergebene oder auf shipped zurückgesetzte Einträge verschwinden aus dem Overlay, echte
  lokale Änderungen kommen hinzu;
- `fuzzy` kommt im Delta nie vor (ein Delta-Wert ist per Definition nicht der Shipped-Wert);
- No-op, wenn das Modul nicht migriert ist (keine Directory oder keine Shipped-`.po` für die
  Sprache) oder kein Client-Datenverzeichnis aufgelöst werden kann;
- ein Datei-Fehler wird geloggt und als Rückgabewert `false` gemeldet (nicht geworfen) — die
  DB-Schreibung ist bereits erfolgt; die Aufrufer zeigen ihn an.

Vor dem Schreiben prüfen alle lokalen Schreibpfade das Markup (siehe "Markup-Prüfung").

`$refresh_original_from_shipped = true` übergeben nur Aufrufer, deren Werte aus der geshippten
Quelle stammen: alle Pfade des `LanguageInstallationManager`, die "clear"-Aktion in
`ilObjLanguageExtGUI` (importiert `lang/ilias_<sprache>.lang`, übernimmt für migrierte Module aber die
Werte der geshippten `.po`) und `ilPluginLanguage::updateLanguages()`. Ist die geshippte `.po` eines
migrierten Moduls dabei nicht lesbar, bricht "clear" ab, bevor irgendetwas geändert wird
(`ilLanguageException`, Meldung `lng_error_clear_shipped_po_unreadable`). Ad-hoc-Edits und Importe
hochgeladener/Customizing-Dateien übergeben `false`, damit sich die Referenz ("original") nie
unbemerkt verschiebt.

### Lokale Änderungen nachvollziehen (`LocalChangeComments`)

Pro Delta-Eintrag zwei Übersetzerkommentare (nur in der `.po`; die `.mo` kennt keine Kommentare):

- **`# original: <wert>`** — der geshippte Wert, gegen den der Eintrag geführt wird. Gesetzt, wenn
  der Eintrag ins Delta kommt, und bei jedem abgleichenden Schreiben
  (`$refresh_original_from_shipped`). Ein Eintrag, den die Shipped-`.po` nicht mehr enthält, behält
  sein `original`. Enthält der Wert einen Zeilenumbruch oder Backslash, wird er umkehrbar kodiert als
  **`# original_escaped: <wert>`** gespeichert (`\` → `\\`, Zeilenvorschub → `\n`, Wagenrücklauf →
  `\r`); alle anderen Werte stehen unverändert unter `original: `. Alle Vergleiche arbeiten mit dem
  dekodierten Wert.
- **`# local_change: <ISO-8601-UTC>`** — gesetzt, wenn der Wert von `original` abweicht oder kein
  `original` existiert (lokal hinzugefügte Variable). Ein unverändert erneut gespeicherter Wert
  behält seinen Zeitstempel.

Für Einträge ohne Overlay liefert `loadModuleTranslations()` `local_change = false` und
`original` = Shipped-Wert. `remarks` (Freitext) wird nicht in der `.po` abgebildet.

## Installation und Update

`LanguageInstallationManager::insertLanguage()` ist der gemeinsame Pfad für Installation,
Aktualisierung (`setup update`, GUI "Aktualisieren"), "Lokale Änderungen entfernen" und "Lokale
Änderungen anwenden".

**Maßgebliche Quelle für ein migriertes Modul ist ausschließlich die Shipped-`.po`.** Die Zeilen des
Moduls in `lang/ilias_<sprache>.lang` und in der Component-`.lang` werden beim Installieren/
Aktualisieren ignoriert; `lng_data`/`lng_modules` werden trotzdem aus der `.po` befüllt (bereinigt).
Zeilen für das Modul in der Customizing-Datei (`ilias_<sprache>.lang.local`) gelten weiter als lokale
Änderungen und landen im Delta (unzulässiges Markup wird übersprungen, siehe oben).
`InstalledLanguageDatabaseRepository::checkLanguage()` erklärt eine Sprache für ungültig, wenn die
Shipped-`.po` eines migrierten Moduls nicht parsebar ist.

**Für unveränderte migrierte Module schreibt die Installation keine Datei.** Gemessen über den
Code-Pfad der GUI, alle 31 Sprachen, ohne DB-Anteil:

| Stand | Zeit | Dateien geschrieben |
|---|---|---|
| vorher (volles Overlay, fsync je Datei), synthetisch alle Module migriert | ~30 s (NVMe) | 9.548 |
| jetzt, real (nur `tos` migriert) | 0,81 s | 0 |
| jetzt, synthetisch alle Module migriert | 5,55 s (Peak 52 MB) | 0 |

Die verbleibende Zeit ist fast nur das Parsen der `.po` für den DB-Dual-Write; sie entfällt mit den
DB-Tabellen.

### Drei-Wege-Abgleich beim Update (`resolveMigratedModule()`)

Pro geshipptem Eintrag, mit L = lokaler Wert (lokale Änderung in `lng_data`, Customizing-Datei oder
nur im Overlay vermerkte lokale Änderung), O = `original` im Overlay (vorher geshippter Wert),
S = neu geshippter Wert:

| Fall | Ergebnis |
|---|---|
| kein L | S (kein Delta-Eintrag) |
| L == S | S, lokale Markierung entfällt |
| L == O (nur geshippt geändert) | S |
| sonst | L bleibt (lokal gewinnt); `original` wird auf S gesetzt, sodass "Lokale Änderungen entfernen" auf den aktuellen geshippten Wert zurücksetzt |

Werte aus der Customizing-Datei bleiben immer lokal. Neue geshippte Variablen kommen hinzu, lokal
hinzugefügte bleiben, nicht mehr geshippte unveränderte fallen weg. Ein unlesbares Overlay wird im
Abgleich ignoriert (`error_log()`), der Lauf bricht nicht ab. Verwaiste Delta-Einträge (Key nicht
mehr geshippt) bleiben stehen; eine Konfliktanzeige (`original` ≠ neuer Shipped-Wert) gibt es nicht.

### Lokale Änderungen entfernen / anwenden

- **Entfernen** (`insertLanguageForRemovingLocalChanges()`, nach `flush("all")`): ohne
  Customizing-Directory und ohne lokale Änderungen — das Delta wird leer, das Overlay entfernt
  (lokal hinzugefügte Variablen verschwinden).
- **Anwenden** (`insertLanguageForApplyingLocalChanges()`, `install_local`): wendet nur die
  Customizing-Datei auf den gespeicherten Stand an; die Shipped-`.po` wird dabei nicht erneut
  eingemischt. Gilt nur für bereits installierte Sprachen.

### Weitere Schritte nach dem Schreiben von `lng_modules`

- Kollationsprüfung der gespeicherten Modul-Arrays (`LanguageDataNotSavedException`),
- Cache-Invalidierung über einen injizierten Invalidator; im CLI-Setup ohne globalen Cache entfällt
  sie,
- Overlay-Sync aller migrierten Module (`$refresh_original_from_shipped = true`), gesperrt wie unter
  "Locking" beschrieben.

Doppelte Einträge innerhalb einer Datei: das erste Vorkommen gewinnt, die Duplikate werden per
`error_log()` gemeldet (die geshippten `ilias_fa.lang` und `ilias_nl.lang` enthalten solche). Sie in
den Sprachdateien zu bereinigen ist Aufgabe der Pflege dieser Dateien — die Regel bleibt unverändert.

### Setup

- Eine Sprache mit ungültiger Sprachdatei wird beim `setup update` übersprungen und gemeldet; der
  Lauf bricht nicht ab.
- `setup update` meldet übersprungene Customizing-Einträge (pro Sprache) und unzulässige lokale
  Altbestände als WARNING (gekürzte Liste, vollständig im `error_log`).
- Der Hash von `ilLanguagesInstalledAndUpdatedObjective` berücksichtigt die
  Verzeichnis-Konfiguration. Eine Instanz nur mit Default-Verzeichnissen (Plugin-Objectives)
  installiert fehlende Sprachen, aktualisiert installierte aber nicht.
- `LanguageInstallationManager` bekommt Client-Datenverzeichnis und Cache-Invalidator als Closures
  injiziert und meldet Datei-Fehler über `error_log()` sowie als Rückgabewert.
- Lokale Dev-Instanz: `setup update` setzt den `[log]`-Abschnitt in `ilias.ini.php` zurück; danach
  wieder aktivieren.

### Import-Modus "delete"

`ilObjLanguageExt::importLanguageFile()` löscht im Modus "delete" `lng_data`/`lng_modules` der
ganzen Sprache und synct danach jedes vorher vorhandene und jedes importierte Modul ins Overlay (ein
nicht mehr enthaltenes Modul mit leerem Eintrags-Array). Die Markup-Prüfung läuft vorher; ein
Verstoß bricht ab, bevor etwas gelöscht wird.

## Plugins

**Heute** können Plugins kein `LanguageFileDirectory` beitragen: `cli/build_bootstrap.php` baut den
Komponentengraphen nur aus `components/<Vendor>/<Komponente>`, Plugins liegen unter
`public/Customizing/global/plugins`. Ein Kompilat in `iliasdata` für Plugins wird deshalb nicht
gebaut (frühere Entscheidung verworfen).

**Übergang (umgesetzt):** Ein Plugin darf statt `lang/ilias_<lang>.lang` auch `lang/ilias_<lang>.po`
mitliefern.

- Dateiname **ohne Präfix**, `msgid` = Key **ohne Präfix** (wie in der `.lang`), **kein `msgctxt`**.
  Das Präfix (`<Komponente>_<Slot>_<Plugin-ID>`, z. B. `rep_robj_xtst`) setzt `ilPluginLanguage`
  wie bisher beim Schreiben davor — es kommt immer vom Besitzer, nie aus der Datei.
- Gibt es für eine Sprache `.po` und `.lang`, gewinnt die `.po` (`getAvailableLangFiles()`).
- `updateLanguages()` liest die `.po` (`readPoFile()`) und schreibt über den **bisherigen DB-Weg**;
  zur Laufzeit werden Plugins weiter aus der Datenbank bedient. Keine Overlay-/Artefaktdateien.
- Einträge mit `msgctxt` (auch `msgctxt ""`) werden ignoriert und geloggt; leere Übersetzungen
  übersprungen; Werte mit `sanitize()` bereinigt + Warnung; `#.`-Kommentare werden nicht gespeichert
  (wie bisher `###` auf diesem Weg). Eine kaputte `.po` überspringt nur diese Sprache.
- Warnungen gehen über den Logger, ohne Logger (Setup-Kontext) an `error_log()`, Text über
  `PlainLogText::of()`.
- Plugin-`.lang` wird ebenso bereinigt und gewarnt (`readLangFile()`, gemeinsamer Helfer
  `cleanShippedValues()`), damit dasselbe Plugin unabhängig vom Format gleich abgesichert ist.
- Unterschied beim Leerraum: `.lang`-Werte werden wie bisher getrimmt, `.po`-Werte nicht. Wer ein
  Plugin von `.lang` auf `.po` umstellt, sollte Werte mit führendem/abschließendem Leerraum prüfen.
- `removeMigratedMoFiles()` validiert das Präfix (`^[A-Za-z0-9_]+$`), sonst Warnung und nichts tun.

**Ziel (Component Revision):** Plugins werden Komponenten unter `components/<Vendor>/<plugin>` und
tragen ein `LanguageFileDirectory` bei (z. B. mit Namensschema `'ilias_%s'`). Dann gilt automatisch
der ganze Core-Weg (Build-Artefakt, Delta-Overlay, Markup-Prüfung); die Übergangsdateien bleiben
unverändert nutzbar. Offen für später: Mit dem Wegfall der Slots ändert sich der Modulname (heute
`rep_robj_xtst`) — lokale Änderungen, Overlays und `txt()`-Aufrufe brauchen dann eine einmalige
Umbenennung oder das neue Verzeichnis übernimmt vorerst den alten Namen.

## Natives PHP-`gettext()`

Wird bewusst **nicht** genutzt:

- Natives gettext braucht die PHP-Extension **und** generierte OS-Locales pro Sprache
  (`setlocale()`/`bindtextdomain()`). Locales zu generieren erfordert i. d. R. Root-Rechte, die auf
  vielen ILIAS-Hostings nicht vorhanden sind (in der Docker-Entwicklungsumgebung fehlen sowohl die
  Extension als auch die Locales).
- Locale/Domain sind globaler Prozesszustand, während `ilLanguage` pro Request mehrere Module und
  Sprachen (`txtlng()`) liest.

Der PHP-Pfad über `gettext/gettext` (siehe oben) braucht weder Extension noch Locale. Falls native
gettext später als Beschleunigung gewünscht ist, dann nur optional, pro Sprache laufzeitgeprüft
(`function_exists('gettext')` + `setlocale()`-Test) mit Fallback auf den PHP-Pfad — sinnvoll nur bei
einem gemessenen Performance-Problem.

## Rollback

`lng_data`/`lng_modules` werden für migrierte Module weiter geschrieben und sind damit jederzeit ein
aktueller Rollback-Stand. Rollback heißt deshalb "Code/Dateien entfernen", nie "Daten
zurückschreiben".

1. **Pro Modul:** die `$contribute[LanguageFileDirectory::class]`-Contribution der Komponente
   entfernen. Ohne Directory lesen alle Pfade aus der DB, und der Sync ist ein No-op; beim nächsten
   Update werden wieder die `.lang`-Zeilen des Moduls verwendet (für `tos` sind sie in
   `lang/ilias_*.lang` weiterhin vorhanden).
2. **Pro Sprache:** die Shipped-`.po` der Sprache entfernen; dann liest `ilLanguage` `lng_modules`.
   (Nur die Overlay-Dateien zu löschen, genügt nicht: dann wird der Shipped-Stand ohne lokale
   Änderungen geliefert.)
3. **Build-Artefakte:** `artifacts/language/` kann jederzeit gelöscht werden; `ilLanguage` kompiliert
   dann die `.po` direkt, der nächste `setup build` legt die Artefakte neu an.
4. **Gesamter Mechanismus:** den Lesepfad in `ilLanguage` und `ilObjLanguageExt` zurücknehmen und
   `ilLanguageSetupAgent::getBuildObjective()` wieder auf `NullObjective` setzen. Ein bereits
   geschrumpftes Overlay enthält weiterhin alle lokalen Änderungen, die DB ist vollständig.

## Deinstallation entfernt die Overlay-Dateien

`ilObjLanguage::uninstall()` ruft nach `flush()` `removeMigratedMoFiles()` auf, das für jedes
kontribuierte Modul `MigratedLanguageFileSync::removeOverlay()` (`.po`, `.mo` und `.lock`) ausführt.
`ilPluginLanguage::uninstall()` tut dasselbe für das (validierte) Plugin-Präfix (derzeit folgenlos,
da kein Plugin eine Directory kontribuiert). Fehler werden pro Modul/Sprache geloggt und
verschluckt. Eine deinstallierte Sprache wird danach auch für migrierte Module nicht mehr
ausgeliefert (siehe "Laufzeit").

## Admin-GUI-Lesestellen

`ilObjLanguageExt::_getValues()` und `_getModules()` (und damit `getAllValues()`,
`getChangedValues()`, `getUnchangedValues()` usw.) lesen ein migriertes Modul als **Shipped-Stand +
Delta** (`MigratedLanguageFileSync::loadModuleTranslations()`/`getMigratedModules()`, Gate: Shipped-
`.po` vorhanden) und schließen es aus der `lng_data`-Abfrage aus; Filter werden in PHP angewendet.
Ist der Stand unlesbar (auch `.mo` ohne `.po`), fällt die GUI auf die `lng_data`-Zeilen zurück.
`_getRemarks()` liest weiterhin `lng_data`. `ilObjLanguage::_getLastMigratedLocalChange()` liest nur
das Delta (`loadLocalChanges()`), ohne jede Shipped-`.po` zu parsen. Die Sortierung von
`_getValues()` entspricht dem `ORDER BY module, identifier` der Abfrage
(`ksort(..., SORT_STRING | SORT_FLAG_CASE)`); die LIKE-Suche fasst aufeinanderfolgende `%` zusammen.

### Geshippte Werte in der Admin-GUI

Überall, wo die Admin-GUI bisher die globale Sprachdatei `lang/ilias_<sprache>.lang` als
"Standardwert" las, gilt für ein migriertes Modul die geshippte `.po`
(`ilObjLanguageExt::getShippedValues()`/`getShippedComments()`; `.lang`-`###`-Kommentar ↔
`.po`-Extracted-Comment `#.`): Vergleich mit dem Standardwert, Filter "hinzugefügt" und
"kommentiert", Export "merged" und `_saveValues()`. Der Filter "Konflikte" vergleicht mit einer
gesicherten Kopie der `.lang`-Datei; migrierte Module erscheinen dort nie. Ist eine geshippte `.po`
nicht lesbar, zeigen die Leser ersatzweise die `.lang`-Zeilen und die GUI eine Warnung
(`lng_shipped_po_unreadable_fallback`); "clear" bricht in diesem Fall ab.

**"In die globale Sprachdatei übernehmen" (Entwicklermodus, "merge")**: migrierte Module werden
übersprungen (`ilObjLanguageExt::mergeLocalChangesIntoGlobalLanguageFile()`); ihre vorhandenen
Zeilen werden exakt so zurückgeschrieben, wie sie gelesen wurden
(`ilLanguageFile::keepOriginalLines()`). Die GUI nennt die übersprungenen Module
(`lng_merge_skipped_po_modules`).

Bei abgelehnter Speicherung (Markup) zeigt die Tabelle wieder die gespeicherten Werte; die Eingaben
gehen verloren (das Tabellen-GUI kann POST-Werte nicht vorbelegen), die abgelehnten Keys werden
genannt.

## Bekannte Grenzen und offene Punkte

- **Rollout auf weitere Module:** braucht (a) eine `$contribute[LanguageFileDirectory::class]`-
  Contribution und (b) die `.pot`/`.po`-Dateien im `lang/`-Ordner der Komponente; danach
  `setup build`. Ein Overlay entsteht nur bei lokalen Änderungen.
- **Offen:** Artefakt-Konvention mit den Setup-Maintainern.
- Mitgelieferte Markup-Verstöße in `lang/*.lang`: gemeldet über `SHIPPED_MARKUP_VIOLATIONS.md`.
- Kein Reset-UI pro Eintrag, keine `remarks` in der `.po`.
- **Zwei externe Direktzugriffe auf `lng_data`** außerhalb der Language-Komponente:
  `ilNotificationDatabaseHandler::getTranslatedLanguageVariablesOfNotificationParameters()` und
  `ilDclStandardField::_getNonImportableStandardFieldTitles()`/`_getImportableStandardFieldTitle()`.
  Sie funktionieren dank Dual-Write; vor einer Abschaltung der DB-Tabellen müssten sie umgestellt
  werden.
- `LanguageInstallationManager` hat keinen injizierten Logger (`error_log()`-Fallback).
- Plugin-Sprachupdates und die Deinstallation melden ein nicht geschriebenes bzw. nicht entferntes
  Overlay nur im Log, nicht in der GUI.
- Pluralformen werden nicht unterstützt.

## Tests

Relevante Tests liegen unter `components/ILIAS/Language/tests/`, u. a.
`ComponentTranslation/TranslationMarkupPolicyTest.php`, `ComponentTranslation/ShippedTranslationsTest.php`,
`ComponentTranslation/PlainLogTextTest.php`, `ComponentTranslation/MigratedLanguageFileSyncTest.php`,
`ComponentTranslation/MigratedLanguageFilePathsTest.php`, `ComponentTranslation/LocalChangeCommentsTest.php`,
`Setup/ShippedLanguageFilesCompiledObjectiveTest.php`, `Setup/LanguageInstallationManagerMigratedModulesTest.php`,
`Setup/ilLanguagesInstalledAndUpdatedObjectiveTest.php`, `PoMigrationLoadLanguageModuleTest.php`,
`PoMigrationWriteBackTest.php`, `ShippedArtifactProblemLoggingTest.php`, `ToJSMapTest.php`,
`ImportUsesShippedValuesOfMigratedModulesTest.php`, `Activities/AddLanguageEntryTest.php`,
`AdminGuiReadsValuesFromMigratedFileTest.php`, `UninstallRemovesMigratedMoFilesTest.php`,
`UninstallRemovesPluginMigratedMoFilesTest.php`.

## Änderungshistorie

### 2026-09-24/25: Build-Artefakt, Delta-Overlay, Markup-Prüfung, Plugin-`.po`

Anlass: Mit dem vollen Overlay hätte die Installation aller 31 Sprachen nach Migration aller Module
~30 s gedauert (auf HDD/NFS geschätzt 1,5–4 min), fast nur durch fsync von 9.548 Dateien. Gemessene
Alternativen: Sammel-fsync −12 % (jede neue Datei erzwingt einen eigenen Journal-Commit), ein
`syncfs` pro Sprache −50 % (in PHP nicht sauber verfügbar), einmal parsen + klonen −25 % Rechenzeit.
Entscheidung: kein Kopieren des Shipped-Stands mehr.

1. **`TranslationCatalog::__clone()`**; `sync()` klont die Shipped-`.po` statt sie zweimal zu parsen.
2. **`TranslationMarkupPolicy`** (Tag-/Attribut-Allowlist, HTML5-Parser, Sentinel, `<` in
   Attributen, abschließendes `<`) nach Security-Review mit PoCs; `toJSMap()` mit `JSON_HEX_*`.
3. **Build-Artefakt** `artifacts/language/<lang>/<modul>.mo` (`ShippedLanguageFilesCompiledObjective`,
   `ShippedTranslations`, `AtomicFileWriter` ohne fsync, `MigratedLanguageFilePaths::shippedArtifact*`);
   `ilLanguage` liest Shipped-Stand + Overlay, "migriert" = Shipped-`.po` vorhanden.
4. **Delta-Overlay** (`sync()`, `belongsInDelta()`, `resolveLocalValue()`, `loadLocalChanges()`,
   `hasOverlay()`); Installation schreibt keine Dateien; Altbestand schrumpft beim Update; `.mo` ohne
   `.po` wirft statt zu löschen; Locks für Module mit Overlay, sortierte Lock-Reihenfolge; Lock-Datei
   bleibt; Parse-Cache pro Sprache.
5. **Markup-Prüfung in Schreibpfaden** (nur geänderte Werte), `ilLanguageInvalidMarkupException`,
   `PlainLogText`, Setup-WARNING für Altbestände und übersprungene Customizing-Einträge, bereinigte
   Shipped-Werte in `lng_data`/`lng_modules`, `ilUtil::stripSlashes()` in `saveObject()` ersetzt.
6. **Namensschema** (`NamesShippedLanguageFiles`, 4. Parameter von `ComponentLanguageFileDirectory`),
   **Kontext-Regel** (fehlender `msgctxt` = Modul) und **Plugin-`.po`-Brücke** in
   `ilPluginLanguage`; Plugin-Kompilat in `iliasdata` verworfen, Plugin-Präfix validiert.

Geprüft: Language-Tests (zuletzt 1344 grün), Security- und Code-Review, Runtime-Check an der
Dev-Instanz (`composer du`, `setup update`, HTTP-Smoke, Logs; `lng_data` vor/nach Update
byte-identisch). Keine Breaking Changes (nur optionale Parameter und neue Methoden; einziger externer
Aufrufer `TermsOfService.php` unverändert).

Nachträge 2026-09-25:

7. **`msgctxt` entfällt** auch für Core-Komponenten: `convert_module_to_po.php` schreibt keinen
   Kontext mehr und kennt `--pattern=<schema>`; die 32 `tos`-Dateien sind neu erzeugt (einzige
   Änderung: 544 entfernte `msgctxt "tos"`-Zeilen, Werte/Flags/Kommentare/Header identisch); das
   Overlay wird ohne Kontext geschrieben; Lesen akzeptiert `msgctxt` = Modul weiter.
8. **Fix:** `findModuleEntry()` ordnete einen Eintrag mit `msgctxt ""` dem Modul zu (gettext bildet
   aus `null` und `""` dieselbe ID); jetzt nur noch Kontext `null`. Eine Datei mit demselben Key
   einmal ohne und einmal mit `msgctxt ""` lehnt `StrictPoLoader` als Duplikat ab.
9. **Build-Fingerprint** um `MigratedLanguageFileSync` ergänzt, `FORMAT_VERSION` 2.
10. **Plugin-`.lang`** wird wie Plugin-`.po` bereinigt; Logger-Fallback auf `error_log()` im
    Setup-Kontext.
11. **Freigabe:** Der `lang/`-Ordner anderer Komponenten und die Contribution
    `$contribute[LanguageFileDirectory::class]` in deren Komponentenklasse dürfen ohne gesonderten
    Auftrag angelegt bzw. geändert werden (projekt-`CLAUDE.md`) – Voraussetzung, um weitere Module zu
    migrieren.
12. **Entscheidungen:** "leerer Wert = zurück auf shipped" bestätigt; migrierte Module nur für
    installierte Sprachen (Option B).

Geprüft: Language-Tests 1362 grün, Code- und Security-Review der Plugin-Brücke; Artefakte nach der
Neuerzeugung für alle 31 Sprachen inhaltsgleich.
