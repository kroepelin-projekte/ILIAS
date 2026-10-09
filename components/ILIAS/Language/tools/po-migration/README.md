# PO/MO-Übersetzungspilot: `TermsOfService` (`tos`) und `Poll` (`poll`)

Dieses Verzeichnis ist **Piloten-Tooling**, kein finales Werkzeug. Es zeigt am Beispiel der
Komponente `TermsOfService` (Modul-Präfix `tos`), wie die ILIAS-Sprachverwaltung von den
`.lang`/DB-basierten Dateien auf gettext-PO/MO-Dateien umgestellt werden kann, bevor auf weitere
Komponenten skaliert wird. Dieses README beschreibt den **aktuellen Stand**; die Entwicklung steht
am Ende unter "Änderungshistorie".

Der Ansatz orientiert sich am ILIAS-Feature-Request ["PO-Files for improving language
handling"](https://docu.ilias.de/go/wiki/wpage_8951_1357) — mit bewussten Abweichungen, die im
nächsten Abschnitt begründet sind.

Konsumenten sind derzeit `TermsOfService` (`TermsOfService.php` kontribuiert
`$contribute[LanguageFileDirectory::class]` mit einer `ComponentLanguageFileDirectory` für `tos`) und
seit 2026-09-28 `Poll` (`Poll.php`, Modul `poll`, zweiter Pilot mit Pluralformen).

## Abweichungen vom Feature Request

Dieselben Begründungen stehen auf Englisch im FR (Abschnitt 2.7 "Design decisions and their
rationale").

| Abweichung | Begründung |
|---|---|
| Laufzeit über natives `gettext()` (seit 2026-10-06), Verwaltung über `gettext/gettext` | Gelesen wird zur Laufzeit nur noch nativ (PHP-Extension `gettext`), ohne OS-Locales pro Sprache: der Build liefert eine eigene Locale mit, die Sprache steckt im Domainnamen, jeder neue Stand bekommt ein eigenes Verzeichnis (gettext lädt eine Datei pro Prozess nur einmal). `gettext/gettext` bleibt für Sprachverwaltung, Abgleich, merge und das Kompilieren. Details: "Natives PHP-`gettext()`". |
| Weder Variante A (`msgctxt`) noch B (`modul.key`), sondern eine Datei pro Modul, `msgid` = Key, kein `msgctxt` | Die Datei legt das Modul fest, `msgctxt` = Modul wäre redundant; Variante B würde jeden Key und damit jeden `txt()`-Aufruf ändern (widerspricht FR 2.5); ohne Kontext bleibt eine Datei bei Umbenennung des Moduls gültig. Details: "Dateinamen und Kontext", "Uniqueness". |
| GUI schreibt nicht in die Shipped-`.po`, sondern in ein Delta-Overlay im Client-Datenverzeichnis | Sonst Schreibrechte des Webservers im Code-Verzeichnis, Verlust lokaler Änderungen bei jedem Update und als geändert markierte versionierte Dateien. Details: "Overlay". |
| `.mo` als Build-Artefakt statt versioniert | Binärdateien sind nicht reviewbar und veralten gegenüber der `.po`; `setup build` erzeugt sie reproduzierbar. Details: "Build-Artefakt". |
| DB-Tabellen werden im Übergang mitgeschrieben (Dual-Write) | Rollback-Pfad nach FR 2.3; nicht migrierte Module, Plugins und zwei externe `lng_data`-Zugriffe hängen noch daran. Abschaltung erst nach vollständiger Migration. Details: "Rollback", "Bekannte Grenzen". |
| Zentrale Markup-Allowlist | Umsetzung von FR 4.4 (keine Skripte über die GUI). Details: "Markup-Prüfung". |
| Pluralformen: neue Methode `ntxt()` (intern `dngettext()`), Formel wird beim Kompilieren geprüft | `txt()` bleibt für jeden bestehenden Aufrufer unverändert (Plural-Eintrag → Standardform). Die Formel prüft `PluralForms` beim Kompilieren (kein `eval`), ausgewertet wird sie zur Laufzeit von gettext. Die Datenbank hält pro Key weiter genau einen Wert. Details: "Pluralformen". |

## Überblick: Datenstände pro migriertem Modul

| Stand | Ort | Geschrieben von |
|---|---|---|
| Shipped-`.po` (versioniert, maßgebliche Auslieferung) | `components/ILIAS/TermsOfService/lang/tos_<lang>.po`, `tos.pot` (Dateiname nach Namensschema, siehe unten) | `convert_module_to_po.php`; zur Laufzeit nur die Wartungsaktion „merge" im Übersetzungsmodus (siehe „merge in die Shipped-`.po`") |
| Build (kompilierter, bereinigter Shipped-Stand, gitignored) | `artifacts/language/current.json` → `artifacts/language/<build>/` mit `messages/LC_MESSAGES/<modul>.<lang>.mo`, `keys/<modul>.php`, `locale/ilias_messages/`, `manifest.json` | `php cli/setup.php build` (`ShippedLanguageFilesCompiledObjective` → `ShippedTranslationsBuild`), läuft bei `composer install`/`composer du`; nach „merge" |
| Overlay (pro Installation, **nur das Delta** = lokale Änderungen) | `<client_data_dir>/lang/<modul>/<lang>/`: `<Dateiname der Shipped-.po>.po`, `lock`, `current`, `r-<hash>/messages/LC_MESSAGES/<modul>.<lang>.overlay.mo`, `r-<hash>/keys.json` | Admin-Edits, Customizing-Werte bei Install/Update, "Lokale Änderungen anwenden" |
| `lng_data`/`lng_modules` (Fallback, Dual-Write) | Datenbank | dieselben Schreibpfade wie bisher; für migrierte Module mit den **bereinigten** Shipped-Werten |

Zur Laufzeit gilt: **Text eines Moduls = Build + Overlay** (ein Key des Overlays wird in dessen
Domain nachgeschlagen, jeder andere in der des Builds), gelesen über natives gettext.
Eine Sprache zu installieren schreibt für migrierte Module **keine Datei** mehr.

Alle Pfade setzt `ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths` zusammen; kein
anderer Code baut sie selbst.

## Das Konvertierungswerkzeug: `convert_module_to_po.php`

```bash
php components/ILIAS/Language/tools/po-migration/convert_module_to_po.php tos en components/ILIAS/TermsOfService/lang
php components/ILIAS/Language/tools/po-migration/convert_module_to_po.php poll en components/ILIAS/Poll/lang
```

Aufruf: `convert_module_to_po.php [--pattern=<schema>] [--skip-unmigratable-keys] [--remove-from-lang]
<modul> [referenzSprache] [ausgabeOrdner]`; die Referenzsprache ist standardmäßig `en` (siehe
„Englisch als Vorlage"). Ohne drittes Argument landet die Ausgabe in
`tools/po-migration/output/<modul>/` (der Ordner wird erst angelegt, wenn alle Prüfungen bestanden
sind). Unbekannte Optionen (alles mit führendem `-`) brechen ab, damit ein Tippfehler nicht als
Modulname oder Ausgabeordner gelesen wird. Modulname und Referenzsprache dürfen nur aus
`[A-Za-z0-9_]` bestehen. Steuerzeichen in gemeldeten Keys (z. B. ESC) erscheinen maskiert
(`\033`).

Das Skript liest alle `lang/ilias_<sprache>.lang`-Dateien, extrahiert die Zeilen des Moduls (jede
Zeile wird wie im ILIAS-Installer getrimmt) und schreibt über `TranslationCatalog::toPoString()`, also
mit `gettext/gettext` (siehe "Gettext-Bibliothek"):

- ein POT-Template (`<modul>.pot`) — Keys ohne Übersetzung,
- je Sprache eine PO-Datei (`<modul>_<sprache>.po`) mit Standard-Headern (`Content-Type`,
  `Language`, `X-Domain` usw.). Header einer bereits vorhandenen Zieldatei werden übernommen;
  `Plural-Forms` kommt immer aus der kanonischen Tabelle in `plurals.json` (siehe "Pluralformen").
  `Language` bleibt das Kürzel (`de`), weil Übersetzungswerkzeuge (Poedit, Weblate, `msgfmt`) daraus
  Sprache und Plural-Regel ableiten. Den englischen Namen schreibt der Konverter in das
  Standardfeld `Language-Team` (`German`), aus `meta_l_<sprache>` in `lang/ilias_en.lang`. Fehlt der
  Name, bleibt ein vorhandener `Language-Team`-Header stehen (Hinweis auf stdout). Die `.pot`
  bekommt keinen. Zur Laufzeit wird der Header nicht ausgewertet.

Es schreibt **keine `.mo`** und nichts in die Datenbank. Anschließend liest es jede geschriebene
`.po` mit `TranslationCatalog::fromPoFile()` (`StrictPoLoader`) wieder ein und vergleicht jeden Wert
mit der Quelle (Exit-Code 1 bei Abweichung; ein aus der Referenz aufgefüllter Eintrag muss den
Referenzwert haben und fuzzy sein); bei Plural-Einträgen zusätzlich `msgid_plural`, jede Form
und dass die Standardform (siehe "Pluralformen") dem bisherigen Wert entspricht. Doppelte Identifier
eines Moduls in einer `.lang`-Datei (Ausnahme: exakte Doppelzeilen mit `--skip-unmigratable-keys`,
siehe unten), eine Plural-Definition, deren Key oder Singular-Key im Modul
fehlt, und eine Sprache ohne `Plural-Forms` in der Tabelle (nur bei Modulen mit Plural-Einträgen)
führen zum Abbruch, bevor irgendetwas geschrieben wird. Fehlt `plurals.json` neben dem Tool, schreibt
es weder `Plural-Forms` noch Plural-Einträge (Hinweis auf stdout).

Das Skript definiert keine benannten Klassen oder Funktionen (nur Closures), damit es nicht in
Composers Autoload-Classmap landet und von der Setup-Klassensuche eingebunden wird. Es nutzt die
Bibliothek bewusst über denselben Adapter wie die Laufzeit (nicht direkt `Gettext\…`), damit Tool und
Laufzeit identische Dateien schreiben und die Absicherungen des Adapters (z. B. der Wert `"0"`) auch
für geshippte Dateien gelten.

Das Skript schreibt **kein `msgctxt`** mehr und kennt `--pattern=<schema>` (Standard `<modul>_%s`,
geprüft mit `MigratedLanguageFilePaths::assertValidShippedFileNamePattern()`); die POT heißt nach
dem Schema ohne `%s` (z. B. `tos.pot`, `ilias.pot`). Die `tos`-Dateien sind damit neu erzeugt (nur
die `msgctxt "tos"`-Zeilen entfallen; Werte, Flags, Kommentare und Header unverändert).

Ein Modul ohne eine einzige Zeile in `lang/ilias_*.lang` (schon migriert und mit
`--remove-from-lang` entfernt, oder Tippfehler im Modulnamen) bricht mit Exit-Code 1 ab, ohne etwas
zu schreiben; vorhandene `.po`/`.pot` bleiben unverändert. Hat nur die Referenzsprache keine
(übernehmbaren) Einträge, nennt die Meldung die Sprachen, die Zeilen haben.

### Englisch als Vorlage: Auffüllen aus der Referenzsprache

Die Referenzsprache (Standard `en`, als zweites Argument überschreibbar) ist die Vorlage aller
Sprachen: Aus ihr kommen die `msgid`s, ihre Reihenfolge und die `.pot`. Fehlt einer Sprache ein Key
der Referenz (keine Zeile) oder ist ihr Wert leer, schreibt der Konverter den Wert der Referenz und
markiert den Eintrag `#, fuzzy` – statt einer leeren Übersetzung (die zur Laufzeit `-key-` ergäbe).
Ein leerer Wert der Zielsprache wird also genau wie ein fehlender behandelt (Referenzwert + fuzzy).
Ein `###`-Kommentar der leeren Zeile bleibt erhalten (Marker → fuzzy, Notiz → `#.`). Bei
Plural-Einträgen werden die aufgefüllten Werte wie vorhandene verteilt (auch ein aufgefüllter
Singular-Key als `msgstr[0]`), der Eintrag ist fuzzy. Ein leerer Referenzwert füllt nichts auf.
Fuzzy-Einträge werden in die `.mo` mitkompiliert (siehe „Design-Entscheidungen"). Keys, die nur
andere Sprachen haben, sind gegenüber der Referenz nicht übernehmbar (Abbruch bzw.
`--skip-unmigratable-keys`, siehe unten).

Die Statistik auf stdout zeigt je Sprache `entries` (eigene Zeilen des Moduls), `fuzzy` (davon mit
„new variable"-Marker), `filled` (aus der Referenz aufgefüllt, fuzzy) und `po_ok`; die Schlusszeile
nennt die Summe der aufgefüllten Einträge.

Probelauf 2026-10-06 über alle 154 Module (Referenz `en`, ohne `--remove-from-lang`, nur in ein
Scratch-Verzeichnis): ohne `--skip-unmigratable-keys` laufen 129 Module durch (3305 aufgefüllt), mit
der Option alle 154 (6519 aufgefüllt: 6438 fehlende Zeilen + 81 leere Werte). `tos` bleibt
byte-identisch. `poll` wird seit 2026-10-09 ebenfalls mit Referenz `en` erzeugt (`poll_import` ist in
`lang/ilias_en.lang` ergänzt).

### `--skip-unmigratable-keys`: Altlasten überspringen statt abbrechen

Ohne die Option bricht der Konverter ab, bevor er etwas schreibt, wenn eine Sprache Keys hat, die
der Referenzsprache fehlen, wenn ein Key leer ist (`modul#:##:#…`; `msgid ""` wäre der PO-Header)
oder wenn ein Key in einer Sprache doppelt vorkommt. Stand 2026-10-06 (Referenz `en`) betrifft das
24 von 154 Modulen mit 114 Keys, die `en` nicht hat (die meisten in `dcl` 32, `survey` 23, `log` 9,
`rbac` 6, `meta` 5; u. a. verwaiste oder falsch einsortierte Keys), dazu der leere Key in `badge` und die Doppelzeilen mit gleichem Wert
`rbac_select_roles` in `fa` und `svy_categories` in `nl`. Der Tippfehler-Key `obj_cpad#_desc` in
`common` (nur `en`) wird mit `en` als Referenz übernommen und in den anderen Sprachen aufgefüllt.

Mit der Option:

- werden Keys, die die Referenzsprache nicht hat, und der leere Key pro Sprache als `WARNING` auf
  stderr gemeldet (`  <sprache>: key1, key2`, der leere Key als `"" (empty key)`) und nicht
  übernommen. Sie fehlen in der `.po` und sind vom Roundtrip-Vergleich ausgenommen;
- wird ein doppelter Key, dessen Vorkommen in einer Sprache alle denselben Wert und denselben
  `###`-Kommentar haben, wie der Installer sie liest (dritter `#:#`-Teil der getrimmten Zeile), als
  `WARNING` gemeldet und einmal übernommen. Ebenso nur `WARNING` ist ein doppelter Key, der ohnehin
  übersprungen wird (fehlt in der Referenz bzw. leer), auch mit unterschiedlichen Werten. Andere
  doppelte Keys mit **unterschiedlichen** Werten brechen weiterhin ab.

Die übersprungenen Keys gehen nicht verloren, solange die `.lang`-Zeilen bleiben; ob sie in ein
anderes Modul gehören oder weg können, ist ein eigenes Thema (Key-Bereinigung, ROADMAP).

```bash
php components/ILIAS/Language/tools/po-migration/convert_module_to_po.php --skip-unmigratable-keys <modul> en components/ILIAS/<Komponente>/lang
```

### `--remove-from-lang`: Modulzeilen aus den `.lang`-Dateien entfernen

**Voraussetzung: erst die Contribution eintragen, dann `--remove-from-lang`.** Die Option entfernt die
bisher einzige andere Quelle des Moduls; sie läuft deshalb nur, wenn die `.po` dort landen, wo die
Installation sie findet. Vor dem Lesen und Schreiben prüft der Konverter (sonst Exit-Code 1, nichts
geschrieben):

- `ausgabeOrdner` ist angegeben, existiert schon und ist (per `realpath`) genau
  `components/<Vendor>/<Komponente>/lang` dieses Repos;
- `components/<Vendor>/<Komponente>/<Komponente>.php` enthält (Kommentare ausgenommen) eine
  Zuweisung `$contribute[LanguageFileDirectory::class] = … new ComponentLanguageFileDirectory($this,
  '<modul>'[, '<pfad>'[, '<schema>']])` mit String-Literalen als Positionsargumenten; der Pfad ist
  `lang/` (Standard) und das Schema gleich dem des Laufs (`--pattern`, Standard `<modul>_%s`).
  Benannte Argumente, Konstanten oder Variablen erkennt die Textprüfung nicht – dann verweigert sie.
- keine `lang/ilias_<sprache>.lang` ist ein symbolischer Link oder keine reguläre Datei (ein Link
  würde erst beim Schreiben scheitern, nachdem andere Dateien schon geändert sind).

Nur nach einem vollständig erfolgreichen Lauf (alle Dateien geschrieben, jede Sprache roundtrip-ok,
also Exit-Code 0) entfernt der Konverter **alle** Zeilen des Moduls (`<modul>#:#…`, auch die mit
`--skip-unmigratable-keys` übersprungenen) aus jeder `lang/ilias_<sprache>.lang` im Repo-Root, die er
gelesen hat. Komponenten- und Customizing-`.lang` werden nicht angefasst. Am Ende der Migration
zeigen die `.lang`-Dateien so, was noch übrig ist.

- Entfernt werden genau die Zeilen, die der Konverter als Zeilen des Moduls gelesen hat, und nur
  hinter `<!-- language file start -->` (eine Datei ohne diese Zeile gilt ganz als Inhalt). Alles
  andere bleibt Byte für Byte: Header und Kommentare davor, Zeilenenden (`\n`/`\r\n`), Reihenfolge,
  Encoding und ob die Datei mit einem Zeilenumbruch endet.
- Jede Datei wird einmal gelesen; Zeilen und Hash stammen aus denselben Bytes.
- Erst werden alle neuen Inhalte berechnet und geprüft: Datei seit dem Lesen unverändert (SHA-256)
  und Zahl der zu entfernenden Zeilen = Zahl der gelesenen Modulzeilen (eine Modulzeile vor der
  Startmarke würde gelesen, aber nicht entfernt). Schlägt das für eine Datei fehl, wird **keine**
  `.lang` geändert (die `.po`/`.pot` sind dann geschrieben). Schlägt der Lauf vorher fehl, gilt das
  ohnehin.
- Geschrieben wird je Datei atomar über `AtomicFileWriter` (Temp-Datei im selben Ordner + `rename`,
  Dateimodus bleibt, Ziel auf `lang/` beschränkt). Pro Datei meldet stdout die Zahl der entfernten
  Zeilen. Scheitert ein Schreibvorgang mittendrin, sind die bis dahin gemeldeten Dateien geändert,
  die übrigen nicht (Exit-Code 1).
- Ein zweiter Lauf für dasselbe Modul bricht mit „no lines in any … lang file … most likely it is
  migrated already" ab und lässt die `.po`/`.pot` unverändert.

```bash
php components/ILIAS/Language/tools/po-migration/convert_module_to_po.php --skip-unmigratable-keys --remove-from-lang <modul> en components/ILIAS/<Komponente>/lang
```

**Folgen:**

- **Rollback pro Modul** (siehe „Rollback") braucht danach die `.lang`-Zeilen zurück: aus git holen,
  z. B. `git show <ref>:lang/ilias_de.lang` (Stand vor dem Entfernen) und die Zeilen des Moduls in
  die aktuelle Datei einfügen. Ohne sie hat das Modul nach dem Entfernen der Contribution keine
  ausgelieferten Werte mehr.
- **Kein erneuter Konverterlauf** für das Modul: Die `.po` sind danach die einzige Quelle. Damit
  kann ein Neulauf aus veralteten `.lang`-Zeilen auch nicht mehr überschreiben, was per „merge"
  (Übersetzungsmodus) in die Shipped-`.po` gekommen ist (neue Keys, geänderte Werte). Änderungen am
  Modul laufen ab jetzt nur noch über die `.po`/`.pot`. Wer die Zeilen für einen Rollback aus git
  zurückholt, hat damit wieder einen veralteten Stand ohne diese Merge-Ergebnisse.
- **Modul `meta` zuletzt migrieren** ist nicht nötig, aber zu beachten: Den `Language-Team`-Header
  liest der Konverter aus `meta_l_<sprache>` in `lang/ilias_en.lang`. Hat `meta` dort keine Zeilen
  mehr, nimmt er sie aus einer ausgelieferten `components/*/*/lang/meta_en.po` – aber nur unter dem
  Standard-Dateinamen. Wird `meta` mit einem anderen Schema oder Pfad migriert, fehlen die Namen
  (Hinweis auf stdout, ein vorhandener Header bleibt stehen).
- Fällt eine Shipped-`.po` als unlesbar aus, nutzt `ilObjLanguageExt::getShippedValues()` bisher die
  `.lang`-Zeilen des Moduls als Näherung; nach dem Entfernen liefert das Modul dort nichts.

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
`Gettext\Translations`, `Gettext\Translation`, `Loader\StrictPoLoader`, `Generator\PoGenerator` und
`Generator\MoGenerator` (seit 2026-09-28 nicht mehr `Loader\MoLoader`, siehe unten). **Der `Scanner`-Teil der Bibliothek (Extraktion
aus Quellcode) wird nicht verwendet und darf nicht verwendet werden** — nur dort betrifft der noch
unveröffentlichte PHP-8.5-Fix der Bibliothek Code. Mit v5.7.3 bestehen unter PHP 8.4.25 und 8.5.4 die
eigene Testsuite der Bibliothek und ein PO/MO-Roundtrip (Kontext, Plural, Flags, Kommentare,
Mehrzeiler/Escapes) ohne Deprecations/Notices.

Die Bibliothek ist hinter einem schmalen Adapter gekapselt; kein anderer Code der Komponente
importiert etwas aus `Gettext\`, ein Versionswechsel bleibt also lokal:

- `TranslationCatalog` — Katalog (Header + Einträge) und einziger Ort, der die Dateiformate liest
  und schreibt (`fromPoFile()`/`fromPoString()`, `toPoString()`, `toMoString()`); eine `.mo` liest
  seit 2026-10-06 nur noch natives gettext zur Laufzeit (`NativeGettext`). `clone` erzeugt eine unabhängige Kopie
  (`__clone()` klont die gettext-Einträge und Header mit),
- `TranslationEntry` — ein Eintrag (Kontext, ID, Übersetzung, `msgid_plural` und alle Formen:
  `getPluralId()`, `isPlural()`, `getPluralTranslations()`, `setPlural()`, `#`-/`#.`-Kommentare, Flags),
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
  `.po` enthält exakt `"0"`), die `.mo` enthält den C-String `"0"` (gettext endet am NUL). Kontext bzw. `msgid_plural` `""`/`"0"` kann die Bibliothek gar nicht schreiben — der
  Adapter wirft dann, statt still zu verfälschen.
- **Header** schreibt `PoGenerator` unkodiert; der Adapter kodiert die Werte vorher. Einen Wert mit
  Zeilenumbruch lehnt `setHeader()` ab (`InvalidArgumentException`), eine geladene Datei mit einem
  solchen Wert (escaptes `\r`) gilt als kaputt (`RuntimeException`). Ein escaptes `\n` im Header-Block
  behandelt die Bibliothek als Fortsetzungszeile und zieht den Wert zusammen (`a\nb` → `ab`).
- **`.mo` liest der Adapter nicht mehr** (seit 2026-10-06; vorher ein eigener, geprüfter Leser statt
  `MoLoader`). Zur Laufzeit liest natives gettext, beschädigte Builds erkennt das Manifest.
- **Plural in der `.mo`:** Vor dem Kompilieren bekommt jeder Plural-Eintrag genau so viele Formen,
  wie `PluralForms` für den Header ergibt — dieselbe Regel wie zur Laufzeit, also höchstens 10 und
  germanisch bei fehlendem/ungültigem Header (fehlende leer, überzählige entfallen; bei `nplurals=1`
  wird er als Singular-Eintrag kompiliert). Weicht die Formenzahl, die `MoGenerator` selbst aus dem
  Header liest, davon ab (ungültiger Header), wird der Header der kompilierten Kopie durch die
  verwendete Regel ersetzt. Ein Plural-Eintrag mit leerem `msgstr[0]`, aber nicht leeren
  weiteren Formen würde `MoGenerator` komplett verwerfen — der Adapter wirft stattdessen.
- **Deterministische `.mo`** (`MoGenerator` mit `includeHeaders(true)`), Fuzzy-Einträge werden
  mitkompiliert, leere weggelassen — Voraussetzung für die `.mo`-Selbstheilung per Byte-Vergleich
  und für den Artefakt-Index.
- **Byte-Identität:** Alle 32 geshippten `tos`-Dateien (`tos.pot`, `tos_*.po`) erzeugt
  `convert_module_to_po.php` mit der Bibliothek byte-identisch neu; `fromPoFile()` → `toPoString()`
  ergibt ebenfalls exakt die Datei.
- **Einschränkungen:** Kommentare eines Eintrags sind eine Menge (ein gleicher — nach PHPs losem
  Vergleich — zweiter Kommentar wird nicht doppelt gespeichert), Flags werden sortiert. Pluralformen
  werden genau in der Anzahl geschrieben, die der `Plural-Forms`-Header angibt (fehlende leer,
  überzählige entfallen); eine fehlende und eine leere Pluralform sind nicht unterscheidbar. Ein
  Plural-Eintrag mit `msgstr[0]` = `"0"` lässt sich nicht als `.mo` kompilieren (Adapter wirft).

## Build-Artefakt: kompilierter Shipped-Stand

`ShippedLanguageFilesCompiledObjective` (über `ilLanguageSetupAgent::getBuildObjective()`) ruft
`ShippedTranslationsBuild` auf – nur über `setup build` (CLI); der Webserver schreibt nicht in
`artifacts/` (entschieden 2026-10-06, auch „merge" baut nicht).
Seit 2026-10-06 (natives gettext):

- **Aufbau:** `artifacts/language/current.json` nennt den gültigen Build (`build`, `previous`,
  `modules` = Modul → gebaute Sprachen, `hashes` = Modul → Sprache → SHA-256 der gebauten `.po`,
  Warnungen, Kollisionen). Ein Build liegt in
  `artifacts/language/<build>/`: `messages/LC_MESSAGES/<modul>.<lang>.mo` (Domain `<modul>.<lang>`),
  `keys/<modul>.php` (`return [key => '<modul>', …]`, sprachunabhängig: Vereinigung aller Sprachen
  und der `.pot`; PHP für den Opcache), `locale/ilias_messages/` (Kopie von `C.utf8` der C-Bibliothek)
  und `manifest.json` (Größe und Hash jeder Datei, Prüfwert, Warnungen).
- **Inhalt einer `.mo`** (`ShippedTranslations::compileCatalog()`): jeder Eintrag ohne Kontext unter
  seinem Key; ein Plural-Eintrag zusätzlich mit allen Formen unter dem Kontext `ilias-plural`
  (`dngettext()` mit `"ilias-plural\x04<key>"`), ohne Kontext steht seine Standardform. Ein Wert, der
  gleich seinem Key ist, bekommt den Marker `ilias-identity\x04<key>` = `1` (gettext antwortet bei
  fehlender Übersetzung mit dem Key). Header: immer `charset=UTF-8`, `Plural-Forms` so, wie
  `PluralForms` ihn liest (ungültig → germanische Regel), damit gettext und `PluralForms` gleich zählen.
- **Build-ID** = Fingerprint über `FORMAT_VERSION` (aktuell 4), den Code (u. a. `ShippedTranslations`,
  Policy, Catalog/Entry, `MigratedLanguageFileSync`, `MigratedLanguageFilePaths`, `PluralForms`,
  `NativeGettext`), alle Shipped-`.po`/`.pot` und die Locale-Dateien. Ein intakter Build gleicher ID
  (Manifest) bleibt; sonst wird in ein temporäres Verzeichnis gebaut und umbenannt.
- **Prüfung:** Vor dem Umschalten fragt der Build über natives gettext einen echten Wert ab
  (`manifest.json` `sample`). Fehlt `ext-gettext`, `C.utf8` oder übersetzt gettext nicht, **bricht
  `setup build` ab** (`UnachievableException`), `current.json` bleibt unverändert.
- **Grenze der Prüfung:** Die Locale wird aus der glibc des Hosts kopiert, auf dem `setup build`
  läuft, und geprüft wird nur im CLI-Prozess. Getrennte CLI- und Webserver-Images (andere glibc,
  fehlende Extension im FPM) oder ein musl-System (ignoriert `LOCPATH`, bräuchte `MUSL_LOCPATH`)
  bestehen die Prüfung womöglich trotzdem; zur Laufzeit gibt es dann `-key-`, Log und den Hinweis in
  der Sprachverwaltung. Voraussetzung: `ext-gettext` für CLI **und** Webserver, glibc mit `C.utf8`,
  dieselbe glibc für Build und Webserver.
- **Umschalten:** `current.json` atomar (mit fsync), erst nach vollständigem, geprüftem Build; der
  vorige Build bleibt (laufende Requests), ältere und der frühere Aufbau (`<lang>/<modul>.mo`,
  `index.json`) werden entfernt. Builds sind über `artifacts/language/build.lock` serialisiert.
  Gelöscht wird nur unterhalb des Artefaktverzeichnisses, ohne Symlinks zu folgen.
- **Warnungen** (bei jedem Build wiederholt, auch bei unverändertem Build): unzulässiges Markup
  (`TranslationMarkupPolicy`, Wert wird bereinigt kompiliert), eine nicht kompilierbare `.po` (das
  Modul wird für die Sprache nicht ausgeliefert, kein Abbruch), Keys mit unterschiedlichen Werten in
  mehreren migrierten Modulen (Liste; ersetzt die frühere Laufzeit-Warnung). Steuerzeichen im Log
  werden ersetzt (`PlainLogText::of()`).

**Deploy-Voraussetzung:** Nach jeder Änderung einer Shipped-`.po` muss `setup build` laufen – die
Laufzeit kompiliert nicht mehr selbst. Ein Modul, dessen `.po` für eine Sprache nicht im Build ist,
liefert dort `-key-` (Log-Eintrag und Hinweis in der Sprachverwaltung), nie Werte aus der DB.

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
- **`style`** (nur auf `span`): nur `background-color`, `color`, `font-style`, `font-weight`,
  `text-align`, `text-decoration`; als Funktionen nur `rgb`/`rgba`/`hsl`/`hsla`, keine CSS-Escapes
  und Kommentare.
- **Weitere Verstöße:** Kommentare (auch kaputtes Markup wie `</ i>`), jedes `<` in einem
  Attributwert (Ausbruch aus `<title>`/`<textarea>`/`toJS()`), ein Wert, der in einem unfertigen Tag
  endet (Erkennung per Sentinel `U+E000`), und ein `<` als letztes Zeichen (sonst XSS durch direktes
  Aneinanderhängen zweier Werte).
- **Tag-Balance:** per echtem Parser, der Wert eingebettet in eine Kette umgebender Elemente
  (`collectUnbalancedMarkup()`): ein offen gelassenes Element oder ein End-Tag, das ein umgebendes
  Element schließt, ist ein Verstoß. Optionale End-Tags (HTML5, z. B. `li`, `p`) sind erlaubt.
  Zusätzlich ist jedes End-Tag eines nicht zulässigen Tags ein Verstoß (`</form>`, `</section>`, …,
  `collectEndTagsOfDisallowedTags()`): Der Parser verwirft es im Wert allein, in der Seite schließt
  es aber das umgebende Element (`Speichern</form>`). Einzige Lücke: ein überzähliges `</p>` oder
  `</a>` (beides zulässige Tags, nicht in der Kette) wird nicht erkannt.
- **Längengrenze:** Ein Wert mit `<` über 16 KiB wird nicht geparst und ist immer ein Verstoß;
  `sanitize()` escaped ihn komplett.
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
| "clear"/„Lokale Änderungen entfernen" (Neuaufbau aus dem Shipped-Stand) | ungeprüft (Shipped-Stand) |
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

Die mitgelieferten Verstöße in `lang/ilias_*.lang` (Stand 2026-10-09: 200 Werte in 58 Keys) sind als Hinweis an die
Pflege der Sprachdateien in `SHIPPED_MARKUP_VIOLATIONS.md` aufgelistet.

`ilLanguage::toJSMap()` kodiert Key und Wert zusätzlich mit
`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.

## Overlay: Installations-eigene Delta-Dateien

**Grundsatz:** Shipped-Dateien werden zur Laufzeit nie geschrieben – einzige Ausnahme ist die
Wartungsaktion „merge" im Übersetzungsmodus (LANGMODE), wie bisher für `lang/ilias_<lang>.lang`
(siehe „merge in die Shipped-`.po`"). Der git-getrackte `lang/`-Ordner einer Komponente wird sonst
zur Laufzeit nie beschrieben. Lokale Änderungen landen unterhalb des Client-Datenverzeichnisses
(seit 2026-10-06; der frühere Ort `<client_data_dir>/lang/<Pfad der Directory><modul>_<lang>.*` wird
nicht mehr gelesen, eine Migration gibt es nicht):

```
<client_data_dir>/lang/<modul>/<lang>/<Dateiname der Shipped-.po>.po   Delta mit Buchführung
<client_data_dir>/lang/<modul>/<lang>/lock                              Schreib-Lock
<client_data_dir>/lang/<modul>/<lang>/current                           Name der gültigen Revision
<client_data_dir>/lang/<modul>/<lang>/r-<hash>/messages/LC_MESSAGES/<modul>.<lang>.overlay.mo
<client_data_dir>/lang/<modul>/<lang>/r-<hash>/keys.json                Keys mit Wert (JSON, kein PHP)
<client_data_dir>/lang/<modul>/template.lock                            Lock der .pot (merge)
```

Jede Änderung schreibt die `.po`, kompiliert eine neue Revision (`compileCatalog()` ohne
Markup-Bereinigung, `r-<hash>` = Inhalts-Hash) und stellt `current` atomar um; höchstens die aktuelle
und die vorige Revision bleiben (gettext lädt eine Datei pro Prozess nur einmal, eine Revision wird
deshalb nie überschrieben). Ein leeres Overlay entfernt `current`, `.po` und die Revisionen
(ein laufender Request, der eine Revision schon geladen hat, liest sie weiter).

Damit bleiben lokale Anpassungen — wie früher in `lng_data` — Instanz-Zustand und markieren keine
versionierte Datei als geändert.

- **Nur das Delta** (`MigratedLanguageFileSync::sync()`, Entscheidung in `belongsInDelta()`/
  `resolveLocalValue()`): ein Eintrag steht im Overlay, wenn sein Wert vom **rohen** Shipped-Wert
  abweicht oder der Key shipped nicht existiert (`AddLanguageEntry`, Customizing).
  `sync()` bekommt weiterhin den vollständigen Stand eines Moduls und rechnet das Delta selbst.
- **Plural-Einträge** stehen im Delta mit allen Formen, sobald eine Form abweicht; `original` gibt es
  pro Form (siehe "Pluralformen").
- **Leerer Wert = zurück auf shipped** (entschieden 2026-09-25): gilt in Overlay **und**
  DB (`ilObjLanguageExt::saveValues()` schreibt dann den Shipped-Wert mit `local_change` `NULL`).
  Umzustellen ist nur `resolveLocalValue()`. Ein leerer Wert für einen nicht mitgelieferten Key
  bleibt leer.
- **Wert == shipped** → Eintrag raus; **Delta leer** → `current` und `.po` werden unter Lock gelöscht.
  Die `lock`-Datei bleibt (entfernt nur `removeOverlay()` bei der Deinstallation), damit Wartende
  nicht ihre Lock-Versuche aufbrauchen.
- **Leeres Delta und kein Overlay** → nichts wird geschrieben oder gelockt. Eine Sprache ohne lokale
  Änderungen hat kein Overlay.
- **Altbestand:** Volle Overlays früherer Stände schrumpfen beim nächsten abgleichenden Schreiben
  (Setup-Update, Neuinstallation) auf das Delta; eine eigene Migration gibt es nicht. In der
  Dev-Instanz blieb nach dem Update nur `tos_de` mit seiner einen lokalen Änderung.
- **`current` ohne `.po`** (die `.po` trägt die Buchführung) oder ein Overlay-Pfad, der keine
  reguläre Datei ist: Lesen und Schreiben werfen eine `RuntimeException`, nichts wird ersetzt oder
  gelöscht. Eine lokale Änderung geht so nicht still verloren.
- Overlay-Einträge werden **ohne `msgctxt`** geschrieben. Altbestand mit `msgctxt` = Modul wird
  gelesen und beim nächsten Schreiben ohne Kontext übernommen (`TranslationEntry::withContext(null)`;
  Werte, Kommentare, Flags, `original` und `local_change` bleiben erhalten).
- Eine nicht parsebare Overlay-`.po` (bei vorhandener `.po`) wird beim nächsten Schreiben neu
  aufgebaut; dabei gehen nur die bisherigen `local_change`-Zeitstempel verloren.
- Alle Dateien werden atomar mit fsync geschrieben (erst `.po`, dann Revision, dann `current`): die
  `.po` nur, wenn sich ihr Inhalt geändert hat, eine neue Revision immer dann, wenn die von `current`
  genannte nicht byte-genau dem entspricht, was `compileCatalog()` erzeugt. Eine fehlende oder
  beschädigte Revision neben einer unveränderten `.po` wird so beim nächsten Schreiben ersetzt.
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

Seit 2026-10-06 liest `ilLanguage` migrierte Module ausschließlich über natives gettext
(`MigratedTranslations`, Prozesszustand in `NativeGettext`) – nie über `gettext/gettext` und nie aus
`lng_modules`/`lng_data`. **Ein Modul gilt für eine Sprache als migriert, wenn der Build in
`current.json` es für die Sprache enthält.** Liefert eine kontribuierte Directory eine `.po`, die nicht
im Build ist, gilt das Modul ebenfalls als migriert, aber ohne Keys (`-key-`, Problem wird gemeldet).
Nur wenn `CLIENT_DATA_DIR` definiert ist — vorher (z. B. in Setup-Objectives erzeugte
`ilLanguage`-Instanzen) ist `lng_modules` die richtige Quelle; nur für installierte Sprachen.

- **Aktivierung** (beim ersten migrierten Modul eines Requests): `LOCPATH=<build>/locale`,
  `setlocale(LC_MESSAGES, 'ilias_messages')`, `LOCPATH` sofort wieder entfernt, `LANGUAGE=messages`.
  Zurückgesetzt wird am Request-Ende von PHP selbst (siehe „Natives PHP-`gettext()`"). Ändert anderer
  Code `LC_MESSAGES` oder `LANGUAGE` (z. B. `ilInitialisation::initLocale()`, unter ZTS das
  Request-Ende eines anderen Threads), wird beim nächsten Fehltreffer neu aktiviert; gelingt das
  nicht, gibt es `-key-` statt eines rohen Keys.
- **`loadLanguageModule()`:** bindet `<modul>.<lang>` an den Build und ggf. `<modul>.<lang>.overlay`
  an die Revision aus `current` (jede Domain mit Codeset UTF-8, geprüft über den Header) und trägt die
  Keys aus `keys/<modul>.php` und `keys.json` in die Zuordnung Key → Modul
  (`$migrated_key_modules`) ein. Später Geladenes überschreibt; ein später geladenes **nicht**
  migriertes Modul entfernt seine Keys aus der Zuordnung, damit „zuletzt geladen gewinnt" exakt
  bleibt. Migrierte Werte landen nicht mehr in `$text`.
- **`txt()`/`exists()`/`ntxt()`/`txtlng()`/`_lookupEntry()`:** über die Zuordnung → Overlay-Domain
  (nur für Keys aus `keys.json`) → Build-Domain; nicht migrierte Module unverändert über `$text`/DB.
  Ist ein Key im zuständigen (zuletzt geladenen) migrierten Modul für die Sprache nicht übersetzt,
  liefert `txt()` `-key-` (ohne Log) – **bewusste Verhaltensänderung** gegenüber `.lang`+DB, wo
  `array_merge()` den Wert eines früher geladenen Moduls behielt (entschieden 2026-10-06; bei
  Kollisionen bedeutet derselbe Key meist etwas anderes, ein falscher Text fällt schwerer auf als
  `-key-`). Das gilt auch, wenn das frühere Modul nicht migriert ist; nur das vom Aufrufer genannte
  Fallback-Modul (`txt($key, $modul)`) greift weiter. Das Usage-Log nutzt die Zuordnung.
- **`translate(string|LanguageIdentifier $key, ?int $n = null, ?string $lang = null)`** (seit 2026-10-06,
  nicht im Interface): Mit einem `ILIAS\Language\LanguageIdentifier` (generiertes Enum pro Modul,
  `tools/language-identifier-enum/`) wird der Key direkt in der Domain **seines** Moduls nachgeschlagen –
  ohne Zuordnung, ohne vorheriges `loadLanguageModule()`, also ohne Kollision. Von einem nicht
  migrierten Modul werden für die aktuelle Sprache die Werte gelesen (Cache bzw. `lng_modules`, im
  Request zwischengespeichert) und sein eigener Wert geliefert – ohne Seiteneffekt auf `txt()` (nicht
  in `$text`, nicht in die geladenen Module, Zuordnung unverändert); für andere Sprachen aus `lng_data`; ohne Text
  Rückfall auf die Standardsprache, sonst `-key-`. Mit einem String ohne `$lang` exakt
  `txt()`/`ntxt()`; mit anderer `$lang` genau eine Abfrage im Modul, aus dem `txt()` den Key liefert
  (Zuordnung, sonst das zuletzt geladene Modul, das ihn enthält), ohne Standardsprachen-Rückfall.
- **Ausfall** (Extension fehlt, Locale nicht aktivierbar, Katalog nicht lesbar, kein Build): `-key-`,
  ein Log-Eintrag pro Problem und Request (mit vollen Pfaden), und `MigratedTranslations::getProblem()`
  für die Sprachverwaltung: Fehler-Box für Probleme aller migrierten Module (natives gettext, kein
  Build), Info-Box für ein Modul/eine Sprache/ein Overlay; Pfade dort relativ. Ein nicht lesbares
  Overlay (auch mit Symlink in `lang/<modul>/<lang>/`, `current` wird höchstens 128 Byte gelesen) wird
  geloggt und ausgelassen (Shipped-Werte werden geliefert).

**Entschiedene Randfälle** (2026-09-25):

- Der migrierte Stand wird **nur für installierte Sprachen** geliefert. Für eine nicht (mehr)
  installierte Sprache fällt `ilLanguage` wie bei nicht migrierten Modulen zurück (in der Regel
  `-key-`), damit eine deinstallierte Sprache nicht teilweise weiter ausgeliefert wird und keine
  gemischte Ausgabe entsteht (Option B; die Liste installierter Sprachen ist gecacht).
- Ein lokal **leerer Wert** ist im Overlay nicht darstellbar (leere Werte werden nicht kompiliert);
  deshalb die Regel "leer = zurück auf shipped" (siehe Overlay).

## Cache-Verhalten

`loadLanguageModule()` prüft den migrierten Stand **vor** `$this->cached_modules` (aus
`ilCachedLanguage`, das immer aktiv ist und `lng_modules` spiegelt). Ein migriertes Modul gewinnt
deshalb unabhängig vom Cache-Zustand; ein manueller Cache-Flush ist für die Anzeige migrierter Module
nicht nötig. Für nicht migrierte Module (und den Fallback) greift der Cache wie bisher; nach einem
Schreiben von `lng_modules` invalidiert ihn der `LanguageInstallationManager` selbst.

Nach jedem Overlay-Schreiben verwirft `ilLanguage::invalidateMigratedLanguageFileCache()`
(→ `MigratedTranslations::invalidate()`) den Stand des Moduls/der Sprache; der nächste Zugriff bindet
die neue Revision. Keys, die eine laufende `ilLanguage`-Instanz schon zugeordnet hat, bleiben.

`MigratedLanguageFileSync::readShippedPo()` hält geparste Shipped-`.po` pro Request (Schlüssel:
Inhalts-Hash, max. 512 Einträge) und leert den Cache beim Wechsel der Sprache (Speicher bei
`setup update` über viele Sprachen). Nur `sync()` bekommt einen Klon; lesende Aufrufer kopieren die
Werte in Arrays.

**Prüfen, dass wirklich das Overlay gelesen wird:** nur die DB-Zeile ändern und die Seite neu laden;
angezeigt wird weiter der Wert aus Build/Overlay.

## Uniqueness: Kollisionen zwischen Modulen

Auf Dateiebene gibt es keine Kollisionen: jedes Modul hat eigene Dateien (Shipped-`.po`, Artefakt,
Overlay), der `msgctxt` wird dafür nicht gebraucht. `txt($topic)` nimmt aber weiterhin nur ein Topic
entgegen (die Module werden zur Laufzeit flach zusammengeführt); `setup build` listet deshalb seit
2026-10-06 die Identifier, die zwei migrierte Module mit unterschiedlichem Wert liefern (vorher eine
Laufzeit-Warnung pro Request). Kollisionen zwischen einem migrierten
und einem nicht migrierten Modul werden nicht erkannt (der Legacy-Pfad führt keine
Pro-Topic-Modulzuordnung).

**Bestand** (`scan_shipped_cross_module_duplicates.php`, Bericht `SHIPPED_CROSS_MODULE_DUPLICATES.md`,
Stand 2026-09-30): 241 von 17.968 Identifiern stehen in mehr als einem Modul, 85 davon schon in en mit
anderer Bedeutung (z. B. `bibl`/`irss` `sorting_3…6`, `assessment`/`survey` `category`), 130 nur
anders übersetzt. Am häufigsten sind `cmix` + `lti` (75), `assessment` + `survey` (33) und
`<typ>_copy` in Objektmodul + `rbac`. Die Umstellung ändert nicht, welcher Wert gewinnt (gleiche
Ladereihenfolge, gleiches `array_merge`). Für den Rollout gilt aber:

- Schon heute unerkannt: `poll` (migriert) und `rbac` (nicht migriert) teilen `poll_copy`.
- Sind beide Module eines Paars migriert, nennt `setup build` jeden betroffenen Identifier mit den
  Modulen und Sprachen. Reine Übersetzungsunterschiede zählen mit.
- Beheben lässt sich eine Kollision über `txt()` nur durch Umbenennen eines Keys samt Aufrufern
  (FR 2.5 schließt eine Signaturänderung von `txt()` aus). Die Aufrufer liegen meist in anderen
  Komponenten. Ohne Umbenennen: Aufrufer auf `translate(<Modul>LanguageIdentifier::<KEY>)` umstellen – das
  Enum nennt das Modul, der Wert kommt immer aus diesem (seit 2026-10-06).

## Pluralformen

Seit 2026-09-28 unterstützt. Zweiter Pilot ist `poll` (69 Keys, in `en` 68, nur von `Poll` genutzt, keine
dynamisch zusammengesetzten Keys).

### Laufzeit-API

- **`ilLanguage::ntxt(string $a_topic, int $a_n, string $a_default_lang_fallback_mod = ""): string`**
  (neu, nicht im Interface `ILIAS\Language\Language`, das bliebe sonst für Implementierer ein
  Breaking Change). Liefert für einen Plural-Eintrag eines migrierten Moduls die Form, die die
  `Plural-Forms`-Formel der Sprache für `$a_n` wählt. In jedem anderen Fall — nicht migriertes
  Modul, Plugin, Key ohne Plural, leere Form, Key inzwischen von einem anderen Modul überschrieben —
  genau `txt($a_topic, $a_default_lang_fallback_mod)`. Die Zahl setzt der Aufrufer selbst ein
  (`sprintf()`); ein negatives `$a_n` zählt wie sein Betrag.
- **`txt()` ändert sich für keinen bestehenden Aufrufer.** Ein Plural-Eintrag liefert seine
  **Standardform = die letzte Form** `msgstr[nplurals-1]` (CLDR „other", die allgemeinste
  Kategorie; entschieden 2026-09-28). Ist sie leer, gilt die letzte nicht leere Form davor; sind
  alle leer, gilt der Eintrag als nicht geshippt. Die Regel steht an genau einer Stelle
  (`PluralForms::defaultFormIndex()`/`defaultValueOf()`) und gilt ebenso für `txtlng()`,
  den Eintrag ohne Kontext im Build, den DB-Dual-Write samt `local_change`, alte Einzelwerte
  (`mapLegacyPluralValues()`), „Neue Variable hinzufügen", die Admin-GUI und die
  Plugin-`.po`-Brücke. Für `poll_population` ist das der bisherige Wert von `poll_population`.
  `msgstr[1]` wäre falsch, weil es in vielen Sprachen eine Sonderform ist:

  | Sprachen | nplurals | `msgstr[1]` | Standardform |
  |---|---|---|---|
  | cs, sk | 3 | n = 2–4 | `[2]` other |
  | pl, ru, uk, hr, sr | 3 | 2–4 (few) | `[2]` many/other |
  | lt | 3 | 2–9 (few) | `[2]` other |
  | ro | 3 | 0, 2–19 (few) | `[2]` other |
  | es, fr, it, pt | 3 | Millionen (many) | `[2]` other |
  | sl | 4 | Dual (n % 100 = 2) | `[3]` other |
  | ar | 6 | n = 1 | `[5]` other |

  Für Sprachen mit 2 Formen (`[1]`) und mit einer Form (`[0]`) ändert sich gegenüber der früheren
  Regel nichts.

### Formel-Auswertung (`PluralForms`)

Eigener kleiner Parser (rekursiver Abstieg) statt fester Tabelle: Die Formel steht in einer Datei
(Shipped-`.po`, Overlay, Plugin) und wird daher wie eine Eingabe behandelt; eine feste Tabelle hätte
jeden abweichenden, aber gültigen Header ignoriert und die Tabelle an zwei Stellen (Tool, Laufzeit)
gepflegt verlangt. Erlaubt ist genau die Teilmenge der gettext/CLDR-Formeln: `n`, nicht-negative
Ganzzahlen (max. 9 Stellen), `?:`, `||`, `&&`, `!`, `==`, `!=`, `<`, `<=`, `>`, `>=`, `%`, Klammern.
Der Header wird vor jedem regulären Ausdruck auf 576 Zeichen begrenzt, der Ausdruck selbst ist
possessiv (kein Backtracking).
Kein `eval`, kein `create_function`, kein `Gettext\Translator`. Validierung beim Parsen: Header-Form
`nplurals=<1..10>; plural=<Ausdruck>;`, Länge ≤ 512, Schachtelung ≤ 64, und für n = 0…200 sowie
einige große Werte (1000, 1000000, …) muss der Index in 0…nplurals−1 liegen. Ein fehlender oder
ungültiger Header ergibt die germanische Regel `nplurals=2; plural=(n != 1);` plus Warnung
(Build: Warnung, der kompilierte Header ist dann die germanische Regel; Plugin: Logger). Seit
2026-10-06 wertet zur Laufzeit gettext die (geprüfte) Formel aus; der Parser bleibt für die Prüfung
beim Kompilieren, `TranslationCatalog::toMoString()`, den Abgleich und den Konverter
(`formIndexFor()`). Scheitert die Formel für ein einzelnes n (z. B. Modulo 0), gilt die Standardform (letzte
Form).

### Kanonische Tabelle und Konverter (`plurals.json`)

`tools/po-migration/plurals.json` enthält

- `plural_forms`: den `Plural-Forms`-Header aller 31 Sprachen der Root-`lang/ilias_*.lang`
  (identisch mit den seit dem Piloten in `tos_*.po` stehenden Headern, die `tos`-Dateien bleiben
  daher byte-identisch). **Quelle: CLDR 48.2** (`release-48-2`, 2026-03-17), Integer-Kategorien;
  für alle 31 Sprachen gegen n = 0…2000 und große Werte geprüft (31/31 OK). es/fr/it/pt behalten
  die dreiformige CLDR-Regel (entschieden 2026-09-28). CLDR main (49, unveröffentlicht) ändert `vi`
  auf zwei Formen (`nplurals=2; plural=n > 1;`) — bei Release 49 nachziehen. Der Konverter schreibt ihn in jede erzeugte `.po`;
  die `.pot` bekommt keinen.
- `modules.<modul>`: die Plural-Einträge pro Modul, `msgid => {"singular": <key>, "plural_id": <msgid_plural>}`
  (beides optional, `msgid_plural` sonst `<key>_plural`, Label nach dem Beispiel des FR). Für `poll`:
  `poll_population` (Singular `poll_population_singular`) und `poll_vote_error_multi`.

Der Konverter erfindet keine Texte, er verteilt nur vorhandene Werte (ein aus der Referenzsprache
aufgefüllter Wert gilt als vorhanden, siehe „Englisch als Vorlage"; der Eintrag ist dann fuzzy):

- Der Wert des Singular-Keys kommt nur in die Form, die die Regel der Sprache **ausschließlich für
  n = 1** wählt (geprüft über die Auswertung für n = 0…200 und einige große Werte, nicht über den
  Formeltext; meist Form 0, `ar`: Form 1), und nur, wenn die Sprache den Singular-Key übersetzt hat
  (nicht leer, nicht aus der Referenz aufgefüllt, kein „new variable"-Marker). Alle übrigen Formen =
  bisheriger Wert.
- Ohne solche Form oder ohne Singular-Key (`poll_vote_error_multi`, „%s answers"): alle Formen =
  bisheriger Wert.
- **Fuzzy**, wenn der bisherige Wert fuzzy ist **oder in mehr als eine Form kopiert wurde** (die
  Formen sind dann noch zu übersetzen). Ausnahme: die Quellsprachen (Referenzsprache, `de`, `en`),
  deren Originaltext in jeder Form als übersetzt gilt. Sprachen mit `nplurals=1` kopieren nie.
- Der „new variable"-Marker wird an einer Stelle erkannt: `LegacyFuzzyMarker` (Konverter und
  `LanguageInstallationManager`).
- Keys mit ` [` (die Schreibweise einer Pluralform, `PluralFormKey`) sind nicht migrierbar: Abbruch
  bzw. mit `--skip-unmigratable-keys` übersprungen. Die `.mo` kompiliert Fuzzy weiter mit,
  zur Laufzeit ändert sich dadurch nichts. Der Singular-Key bleibt als eigener Eintrag erhalten;
  in der `.pot` hat ein Plural-Eintrag `msgstr[0]`/`msgstr[1]` leer.

### Datenmodell in den Schreib-/Lesepfaden (`PluralFormKey`)

Überall, wo die Language-Komponente ein Modul als flache `identifier => wert`-Zuordnung behandelt
(Admin-GUI, `loadModuleTranslations()`, `loadLocalChanges()`, `sync()`, Drei-Wege-Abgleich,
Export/Import), steht ein Plural-Eintrag als eine Zeile pro Form: **`<key> [<form>]`**, z. B.
`poll_population [0]`, `poll_population [1]` — nie unter dem Key selbst. Damit arbeiten Delta,
„leer = zurück auf shipped", Markup-Prüfung (`findInvalidMarkupOfChangedValues()`) und der
Drei-Wege-Abgleich unverändert **pro Form**. Ein Schlüssel dieser Form gilt nur als Form, wenn sein
Key ein Plural-Eintrag ist (shipped oder im Overlay).

- **Datenbank (Dual-Write):** `lng_data`/`lng_modules` halten nur den Key mit der Standardform
  (`MigratedLanguageFileSync::collapsePluralForms()`, bereinigt wie jeder Shipped-Wert);
  `local_change` des Keys ist nur gesetzt, wenn **die Standardform** von der geshippten abweicht
  (die Zeile steht nur für diesen Wert; sonst würde `keep_local_changes` nach einem Rollback
  `.lang`-Updates des Keys unterdrücken). Lokale Änderungen anderer Formen stehen nur im Overlay.
  Alle Formen gibt es nur in Shipped-`.po`, Build-Artefakt und Overlay.
- **Bemerkungen (`remarks`)** gehören zum Key: Die Formzeilen der Admin-GUI zeigen die Bemerkung
  des Keys; beim Speichern gewinnt die erste Form, deren Bemerkung von der gespeicherten abweicht
  (leer = Bemerkung löschen); weicht keine ab, bleibt die gespeicherte erhalten (ebenso beim
  Löschen einer Form). Formzeilen mit einem Index ≥ der geshippten Formenzahl werden beim Speichern
  verworfen; „Neue Variable hinzufügen" lehnt eine solche Form ab.
- **Alte Einzelwerte:** Ein einfacher Wert für den Key eines Plural-Eintrags (lokale Änderung aus der
  Zeit vor dem Plural, aus `lng_data` zurückgelesen, Customizing-Zeile, Import) steht für die
  Standardform (`mapLegacyPluralValues()`); steht die Form bereits da, gewinnt sie. **Eingaben**
  (Import/„Datei laden"/Upload in allen Modi, „Neue Variable hinzufügen") bilden einen einfachen
  Wert vorher auf den Schlüssel der Standardform ab (`MigratedLanguageFileSync::defaultFormKeyOf()`),
  er gilt also als Änderung der letzten Form; enthält eine Datei den einfachen Key und die Formzeile
  der Standardform, gewinnt die spätere Zeile.
- **Overlay:** Ein Plural-Eintrag im Delta enthält immer alle Formen (nicht geänderte mit dem
  Shipped-Wert), ein `# original[<form>]: …` (bzw. `original_escaped[<form>]`) pro Form und ein
  `# local_change` für den ganzen Eintrag (`LocalChangeComments::refreshForms()`). `loadLocalChanges()`
  meldet pro Form nur die tatsächlich geänderten als `local_change`.
- **Admin-GUI:** Tabelle und Speichern verwenden die Formzeilen; `[`/`]` werden im Feldnamen wie `.`
  und Leerzeichen kodiert (`_POSTLBRACKET_`/`_POSTRBRACKET_`), sonst würde PHP `[0]` als Array-Index
  lesen. Der Identifier-Filter und die Seitenübersetzung finden die Formen über den Key.
  „Neue Variable hinzufügen" mit dem Key eines Plural-Eintrags setzt dessen Standardform.
- **Laufzeit:** `ntxt()` fragt `dngettext()` mit `"ilias-plural\x04<key>"` ab, gettext wertet die
  Formel aus (für alle 31 Sprachen identisch zu `PluralForms::formIndexFor()`). Das Overlay ersetzt
  einen Eintrag ganz (ein Singular-Eintrag im Overlay verdrängt auch die geshippten Formen).
- **Plugins:** Die Plugin-`.po`-Brücke schreibt für Plural-Einträge nur die Standardform in die
  Datenbank und loggt das; `ntxt()` fällt für Plugins auf `txt()` zurück.

### Aufrufer in `Poll`

Werden von der Language-Komponente nicht umgestellt (Nutzerentscheidung); die Vorschläge für
`ilPollContentRenderer::renderTotalParticipantsInfo()`, `ilPollAnswerTableGUI` (zeigt heute „1 votes
cast") und `ilPollAnswersRenderer` (`poll_vote_error_multi`) gehen an die Maintainer. Nach einer
Umstellung sehen Sprachen ohne „n == 1"-Regel (z. B. `ja`, `fr`, `es`, `pl`) für n = 1 die
Pluralform, bis ihre `.po` übersetzt ist — heute zeigt der Renderer dort für n = 1 den (meist
englischen, fuzzy) Singular-Key.

## Schreibpfad

Admin-Edits ("Sprachvariablen anpassen", "Neue Variable hinzufügen", Löschen einzelner Einträge,
Imports über die GUI) und `ilPluginLanguage::updateLanguages()` enden in
`ilObjLanguage::replaceLangModule($sprache, $modul, $eintraege, $refresh_original_from_shipped = false)`.
Das schreibt wie bisher die `lng_modules`-Zeile (Dual-Write; für migrierte Module mit bereinigten
Shipped-Werten und Plural-Einträgen nur mit ihrer Standardform, `databaseValuesOf()`) und übergibt
anschließend dieselbe `identifier => wert`-Zuordnung (roh, Plural-Einträge als Formzeilen, siehe
"Pluralformen") an `MigratedLanguageFileSync::sync()`, das daraus das Delta schreibt:

- nicht mehr übergebene oder auf shipped zurückgesetzte Einträge verschwinden aus dem Overlay, echte
  lokale Änderungen kommen hinzu;
- `fuzzy` kommt im Delta nie vor (ein Delta-Wert ist per Definition nicht der Shipped-Wert);
- No-op, wenn das Modul nicht migriert ist (keine Directory oder keine Shipped-`.po` für die
  Sprache) oder kein Client-Datenverzeichnis aufgelöst werden kann;
- ein Datei-Fehler wird geloggt und als Rückgabewert `false` gemeldet (nicht geworfen) — die
  DB-Schreibung ist bereits erfolgt; die Aufrufer zeigen ihn an.

Vor dem Schreiben prüfen alle lokalen Schreibpfade das Markup (siehe "Markup-Prüfung").

`$refresh_original_from_shipped = true` übergeben nur Aufrufer, deren Werte aus der geshippten
Quelle stammen: alle Pfade des `LanguageInstallationManager` (auch "clear"/„Lokale Änderungen
entfernen") und `ilPluginLanguage::updateLanguages()`. Ad-hoc-Edits und Importe
hochgeladener/Customizing-Dateien (`_saveValues()`, `importLanguageFile()`, die den Parameter nicht
mehr haben) übergeben `false`, damit sich die Referenz ("original") nie unbemerkt verschiebt. Ist die
geshippte `.po` eines migrierten Moduls nicht lesbar, gilt die Sprache als ungültig
(`InstalledLanguageDatabaseRepository::checkLanguage()`) und "clear" ändert nichts (Meldung
`lng_error_clear_shipped_po_unreadable`).

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

- **`# remark: <text>`** (bzw. **`# remark_escaped: <text>`**, kodiert wie `original_escaped`) —
  die Bemerkung des Administrators (Gegenstück zu `lng_data.remarks`, eine pro Key, auch bei
  Plural-Einträgen). Siehe "Bemerkungen im Overlay".

Für Einträge ohne Overlay liefert `loadModuleTranslations()` `local_change = false` und
`original` = Shipped-Wert.

### Bemerkungen im Overlay (seit 2026-09-28)

- **Gespeichert** werden Admin-Bemerkungen (Kommentarspalte im Übersetzungsmodus, Import-Kommentare,
  der Login bei „Neue Variable hinzufügen", Bemerkungen aus Customizing-Zeilen) zusätzlich zu
  `lng_data.remarks` (Dual-Write bleibt) im Overlay (`LocalChangeComments::setRemark()`,
  `MigratedLanguageFileSync::setRemarks()`).
- **Delta mit Bemerkung:** Ein Eintrag mit Bemerkung gehört ins Overlay, auch wenn sein Wert der
  geshippte ist (`belongsInDelta(..., $remark)`). Dann ist er ein **reiner Bemerkungseintrag** mit
  leerem `msgstr` (Plural: alle Formen leer): die `.mo` lässt ihn weg, ausgeliefert wird weiter der
  Shipped-Wert; `loadModuleTranslations()`/`loadLocalChanges()` übergehen ihn (kein veralteter Wert,
  wenn sich der Shipped-Wert später ändert).
- **`sync(..., $remarks)`**: `null` (Standard, z. B. Admin-Edit über `replaceLangModule()`) behält die
  Bemerkungen des Overlays — auch für einen Eintrag, der auf den Shipped-Wert zurückgesetzt wird;
  ein Array ist die vollständige Menge des Moduls. Bemerkungen zu Keys, die weder geshippt noch im
  Delta sind, entfallen.
- **Lesen:** `ilObjLanguageExt::_getRemarks()` nimmt für migrierte Module die Overlay-Bemerkungen
  (`loadRemarks()`), eine nur in `lng_data` stehende Bemerkung gilt weiter (Altbestand).
- **Setup (Drei-Wege-Abgleich):** Bemerkungen aus `lng_data` (Altbestand), Overlay und
  Customizing-Datei (spätere gewinnen) werden ins Overlay und in die geschriebenen `lng_data`-Zeilen
  übernommen — so wandert Altbestand beim nächsten abgleichenden Schreiben ins Overlay. Vor der
  Migration stand in `lng_data.remarks` der geshippte `###`-Kommentar der `.lang` (meist der
  Datums-Marker „… new variable"); eine `lng_data`-Bemerkung wird deshalb nur übernommen, wenn sie
  vom `###`-Kommentar der Root-/Component-`.lang` **und** von der `#.`-Notiz der Shipped-`.po`
  abweicht und kein Datums-Marker ist. Echte Bemerkungs-Edits von vor der Migration wandern so
  weiter ins Overlay. Remark-only-Einträge stehen sortiert nach Key (kein Neuschreiben ohne
  Inhaltsänderung).
  **„Lokale Änderungen entfernen"** (Sprachenliste) entfernt auch die Bemerkungen (`sync(..., [])`).
- **„Lokale Änderungen in der Datenbank löschen"** (Wartung, "clear") ist seit 2026-09-28 derselbe
  Codepfad wie „Lokale Änderungen entfernen" (siehe "Wartungsaktionen"). „Lokale Ergänzungen
  löschen" entfernt mit einer lokal hinzugefügten Variable auch deren Bemerkung; die
  Deinstallation entfernt das ganze Overlay und die `lng_data`-Zeilen.
- **Admin-GUI:** Speichern schreibt geänderte Bemerkungen ins Overlay; ändert sich nur die
  Bemerkung, wird in `lng_data` nur `remarks` aktualisiert. Löschen einer Variable entfernt ihre
  Bemerkung auch aus dem Overlay (Plural-Formzeilen: die Bemerkung des Keys bleibt). Bekannt: Bei
  einer Bemerkungsänderung wird das Overlay zweimal geschrieben (erst der Wert über
  `replaceLangModule()`, dessen Signatur keine Bemerkungen trägt, dann `setRemarks()`); ebenso bei
  „Neue Variable hinzufügen".
- **Kodierung/Länge:** Bemerkungen werden einmal zentral auf 250 Zeichen gekürzt (`mb_substr`, auch
  in `ilObjLanguage::replaceLangEntry()`/`updateLangEntry()`), nicht gültiges UTF-8 wird verworfen
  und geloggt (`LocalChangeComments::setRemark()` wirft dafür). Ein Overlay, das kein gültiges
  UTF-8 wäre, wird nicht geschrieben (`RuntimeException`), damit es lesbar bleibt.

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

**Leere Übersetzungen gelten als nicht geshippt** (seit 2026-09-28): Ein Eintrag mit leerem `msgstr`
(bzw. ein Plural-Eintrag, dessen Formen alle leer sind; der Konverter schreibt seit 2026-10-06 statt
leerer Werte den Referenzwert als fuzzy, siehe „Englisch als Vorlage") fehlt in der `.mo`, und ebenso in den Shipped-Werten
(`loadShippedModuleEntries()`, `loadShippedModules()`, `loadModuleTranslations()`): keine
`lng_data`-Zeile mit `''`, keine Admin-GUI-Zeile, `txt()` liefert wie vor der Migration `-key-`.
Eine lokale Änderung eines solchen Keys wird wie die eines nicht geshippten Keys behandelt.

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
  (lokal hinzugefügte Variablen und Bemerkungen verschwinden). Einziger Codepfad für
  „Lokale Änderungen entfernen" (Sprachenliste) und „Lokale Änderungen in der Datenbank löschen"
  (Wartung): beide führen die Activity `RemoveLocalLanguageChanges` aus
  (`ilObjLanguage::removeLocalChanges()`: `flush("all")`, Neuaufbau aus `lang/ilias_<lang>.lang`,
  Component-`.lang` und Shipped-`.po`, Status „installed", Cache-Invalidierung; danach spielt die
  Activity die Sprachdateien der aktiven Plugins neu ein). Rechteprüfung über die Activity.
  Geprüft wird vorher nur der Shipped-Stand (`ilObjLanguage::check("shipped")`,
  `InstalledLanguageDatabaseRepository::checkShippedLanguage()`): eine ungültige Customizing-Datei
  blockiert das Entfernen nicht, sie wird ja nicht angewendet. **Restrisiko:** `flush("all")` und
  der Neuaufbau sind keine Transaktion; scheitert der Neuaufbau, meldet die GUI den Fehler
  (`language_error_clear_local`), das Log nennt den Rückweg (`setup update` bzw. „Aktualisieren").
- **Anwenden** (`insertLanguageForApplyingLocalChanges()`, `install_local`): wendet nur die
  Customizing-Datei auf den gespeicherten Stand an; die Shipped-`.po` wird dabei nicht erneut
  eingemischt. Gilt nur für bereits installierte Sprachen.

### Wartungsaktionen (Reiter „Wartung", Stand 2026-09-28)

| Aktion | Verhalten für PO-Module | Offen |
|---|---|---|
| `save_dist` („Globale Sprachdatei sichern") | kopiert `lang/ilias_<lang>.lang` und (seit 2026-09-28) die Shipped-`.po` jedes PO-Moduls der Sprache nach `<client_data_dir>/lang_data/` (Dateiname der Shipped-`.po`, `ShippedPoMerger::backupShippedPoFiles()`); Grundlage des Filters "Konflikte" | Scheitert eine Kopie, meldet die Aktion `language_save_dist_failed` |
| `load` („Lokale Datei laden") | `importLanguageFile(<customizing>, "replace", true)`: Werte als lokale Änderungen ins Overlay, Kommentare als Bemerkungen; unzulässiges Markup übersprungen; einfacher Key eines Plural-Eintrags = letzte Form (gleich nach dem Einlesen, auch für "keepall"/"keepnew" und die Markup-Prüfung, `withPluralFormKeys()`) | – |
| `clear` („Lokale Änderungen in der Datenbank löschen") | = „Lokale Änderungen entfernen" (Activity `RemoveLocalLanguageChanges`): Werte, lokal hinzugefügte Keys, Bemerkungen weg (DB und Overlay), Shipped-Stand aus der `.po`, Status „installed", Plugins neu eingespielt; läuft auch bei ungültiger Customizing-Datei | Nicht atomar (siehe oben); Case-Duplikate der `.lang` (siehe `SHIPPED_CASE_DUPLICATES.md`) |
| `delete_added` („Lokale Ergänzungen löschen") | `_deleteValues(getAddedValues())`: lokal hinzugefügte Keys samt Bemerkung aus DB und Overlay | – |
| `remove_local_file` | löscht die Customizing-Datei, keine Datenänderung | – |
| `merge` (Übersetzungsmodus) | nicht migrierte Module wie bisher in `lang/ilias_<lang>.lang`; PO-Module mit Overlay in ihre Shipped-`.po` (neue Keys auch in die `.pot`), Sicherung nach `lang/customizing/`, siehe „merge in die Shipped-`.po`"; die `.lang`-Zeilen der PO-Module bleiben byte-genau erhalten | `setup build` empfohlen, nicht nötig (bis dahin Kompilierung pro Request) |

Der frühere Reset-Zweig des Imports (`importLanguageFile(..., $refreshOriginalFromShipped)`,
`removeRemarksOfMigratedModules()`, der gleichnamige Parameter von `_saveValues()`) ist entfernt;
`importLanguageFile($file, $mode, $skipInvalidMarkup)` importiert nur noch hochgeladene bzw.
Customizing-Dateien.

### merge in die Shipped-`.po` (seit 2026-09-28)

Wartungsaktion „In die globale Sprachdatei übernehmen" (`merge`, nur Übersetzungsmodus). Ein Klick
übernimmt alles: nicht migrierte Module wie in trunk in `lang/ilias_<lang>.lang` (Sicherung vorher
nach `lang/customizing/ilias_<lang>.lang`), migrierte Module in ihre Shipped-`.po`. Ablauf
(`ilObjLanguageExt::mergeLocalChangesIntoGlobalLanguageFile()` → `ShippedPoMerger::merge()`), pro
PO-Modul der Sprache **mit Overlay**, nach Modulname sortiert, jeweils unter dem Overlay-Lock
(`withOverlayLock()`):

0. **Nur bei genau einem Client:** Die Shipped-`.po` teilen sich alle Clients der Installation, merge
   übernimmt aber nur die lokalen Änderungen und `lng_data` des aktuellen Clients.
   `mergeLocalChangesIntoGlobalLanguageFile()` schreibt die PO-Module deshalb nur, wenn
   `isSingleClientInstallation()` genau ein `client.ini.php` neben dem aktuellen Client findet (wie
   `ilSoapAdministration`; ohne `CLIENT_WEB_DIR` nie). Sonst wird nur die `.lang` geschrieben, und
   das Ergebnis nennt unter `not_single_client` die PO-Module mit Overlay; die GUI meldet sie mit
   `lng_merge_po_not_single_client`. Der Infotext der Aktion warnt (`lng_merge_po_single_client_info`).
1. **Prüfen:** Shipped-`.po` und ihr Verzeichnis, `lang/customizing/` und – nur bei neuen Keys – die
   `.pot` (Pfad `MigratedLanguageFilePaths::shippedTemplatePath()`, dieselbe Namensregel
   `templateFileName()` wie der Konverter) müssen beschreibbar sein, sonst wird das Modul ganz
   übersprungen (Fehlermeldung `lng_merge_po_not_written` mit den Modulen, Grund im Log). Alle Dateien
   müssen außerdem unterhalb des ILIAS-Verzeichnisses liegen (`realpath`; ein symbolischer Link
   hinaus führt zum Überspringen, `AtomicFileWriter` prüft beim Schreiben erneut; die `lang_data`-Kopie
   von `save_dist` ist auf das Client-Datenverzeichnis beschränkt). Zwei Module mit gleichem
   Shipped-Dateinamen (gleiches Namensschema) werden übersprungen, weil ihre Sicherungen kollidieren.
2. **Übernehmen** (auf dem geparsten Shipped-Katalog, `TranslationCatalog`; unveränderte Einträge,
   Header inkl. `Plural-Forms` bleiben byte-genau):
   - Wert → `msgstr` (Plural: alle Formen des Overlay-Eintrags; ein einfacher Wert eines
     Plural-Eintrags ist seine Standardform), `#, fuzzy` entfällt.
   - Reine Bemerkungseinträge liefern keinen Wert.
   - **Bemerkung → `#.`:** Die Admin-Bemerkung (`# remark`) wird als **weitere** `#.`-Zeile nach den
     vorhandenen angehängt (Zeilenumbrüche, andere Steuerzeichen, Zeilen-/Absatztrenner und
     Bidi-Steuerzeichen U+202A–U+202E, U+2066–U+2069 werden zu Leerzeichen,
     `TranslationEntry::plainLine()`, gilt für jede `#.`-Zeile); eine gleichlautende `#.`-Zeile
     wird nicht verdoppelt, bestehende `#.`-Kommentare bleiben immer erhalten. Begründung: Die
     Bemerkung eines PO-Moduls ist (anders als früher `lng_data.remarks`, das den `###`-Kommentar
     enthielt und beim merge ersetzte) eine Ergänzung zur Notiz der Datei.
   - `# original`/`# original[<n>]`/`# local_change` kommen nie in die Shipped-Datei.
   - **Neue Keys** (nicht in der Shipped-`.po` der Sprache) werden nach `msgid` sortiert eingefügt
     (`TranslationCatalog::addInIdOrder()`), ebenso in die `.pot` mit leerem `msgstr`
     (Plural: `msgid_plural` der `.pot` bzw. `<key>_plural`, zwei leere Formen wie beim Konverter);
     die `.po` der anderen Sprachen bleiben unverändert.
   - **Nicht übernommen** (bleiben im Overlay, werden gemeldet): unzulässiges Markup
     (`TranslationMarkupPolicy`, geprüft wird nur, was von der Shipped-`.po` abweicht), ein
     Plural-Eintrag mit leerem `msgstr[0]` neben anderen Formen (nicht kompilierbar), ein
     Plural-Eintrag im Overlay zu einem singulären Shipped-Eintrag und ein neuer Key der Form
     `<key> [<n>]` (würde als Pluralform gelesen). Gemeldet werden Markup-Verstöße und diese Fälle
     getrennt, beide mit `form_input_not_valid` + Keys (Grund im Log).
3. **Schreiben:** Das Ergebnis wird vorher gelesen (`StrictPoLoader`) und kompiliert (`toMoString()`),
   dann: Sicherung der bisherigen Shipped-`.po` nach `lang/customizing/<Dateiname der Shipped-.po>`
   (überschreibt eine frühere Sicherung, wie bei `.lang`), `.pot`, dann `.po`, jeweils atomar mit
   fsync (`AtomicFileWriter`). Die `.pot` gehört allen Sprachen: Sie wird unter einem eigenen Lock
   gelesen und geschrieben (`MigratedLanguageFileSync::withTemplateLock()`, Lock-Datei
   `<client_data_dir>/lang/<modul>/template.lock`, immer **nach** dem Overlay-Lock),
   damit ein gleichzeitiger merge einer anderen Sprache keinen neuen Key verliert. Danach wird das
   **kein** Build erzeugt (der Webserver schreibt nicht in `artifacts/`, entschieden 2026-10-06). **Eigentümer:** Die geschriebenen Dateien gehören danach
   dem Webserver-Nutzer (neue Datei per `rename`, Rechte der alten Datei werden übernommen, Eigentümer
   und Gruppe nicht) – im Log vermerkt; für einen Commit aus dem Arbeitsverzeichnis ggf. `chown`.
4. **Overlay bleibt** (seit 2026-10-06): Die Laufzeit liefert den Build, der bis zum nächsten
   `setup build` die alten Shipped-Werte hat – das Overlay liefert die übernommenen Werte weiter.
   merge markiert sie (`# merged_into_shipped`, `LocalChangeComments::setMergedIntoShipped()`); jedes
   andere Schreiben des Moduls (Admin-Edit, Import, `setup update` ohne vorheriges `setup build`)
   behält markierte Einträge, solange der Build nicht genau diese `.po` ausliefert (Hash der gebauten
   `.po` in `current.json`, `ShippedTranslationsBuild::servesShippedPo()`). Erst `setup build` und
   danach `setup update` (Drei-Wege-Abgleich: lokaler Wert == neuer Shipped-Wert → Eintrag verlässt das
   Overlay, Marker entfällt) räumen sie weg; eine Overlay-Bemerkung, die jetzt als `#.` in der
   Shipped-`.po` steht, verwirft `setup update` ebenfalls (`migratedModuleRemarks()`). Ein zweites
   merge ohne neue Änderung schreibt nichts.
   **Datenbank:** `lng_data.local_change` der übernommenen Keys wird
   `NULL`, `lng_data.remarks` der übernommenen Bemerkungen ebenfalls (wie nach einer Neuinstallation,
   bei der eine `#.`-Notiz keine Bemerkung ist); `lng_modules` bleibt (die Werte ändern sich nicht).
   **Abweichung von trunk:** trunk lässt `local_change` nach merge stehen. Für ein PO-Modul würde ein
   stehen gebliebenes `local_change` beim nächsten Update, sobald sich der Shipped-Wert ändert, im
   Drei-Wege-Abgleich als lokale Änderung zurückkehren (L ≠ S, kein `original` mehr).
5. **Meldung:** `language_merged_global` plus `lng_merged_po_modules` mit den geschriebenen `.po`
   (Pfade relativ zum ILIAS-Verzeichnis); als Fehler: übersprungene Module (`lng_merge_po_not_written`),
   Markup-Verstöße, nicht übernommene Keys, nicht abgeglichene Overlays (`lng_po_overlay_not_written`)
   und Module, deren `lng_data`-Zeilen nicht aktualisiert werden konnten (`lng_merge_database_not_updated`;
   die Dateien sind geschrieben, die übrigen Module werden weiter bearbeitet). Der frühere Key
   `lng_merge_skipped_po_modules` ist entfernt.
6. **Absicherung der GUI:** Die Wartungsaktionen `merge`, `delete_added` und `remove_local_file` prüfen
   LANGMODE serverseitig (sonst `permission_denied`). `ilObjLanguageExtGUI` implementiert
   `ilCtrlSecurityInterface`: `save`, `upload`, `maintainExecute`, `saveSettings` und `saveNewEntry`
   brauchen das CSRF-Token auch per GET, POST-Befehle weiterhin immer. Das ilCtrl-Artefakt
   (`setup build`) muss dafür neu gebaut werden.

**Hinweise:** Eine Customizing-Zeile (`ilias_<lang>.lang.local`) für einen übernommenen Key sollte
danach entfernt werden – sonst kommt der Wert beim nächsten Update erneut als lokale Änderung (bzw.
die Bemerkung doppelt: als `#.` und im Overlay). Ein erneuter Lauf von `convert_module_to_po.php`
erzeugt die `.po` aus den Root-`.lang` neu: per merge hinzugefügte Keys und `#.`-Zeilen gehen dabei
verloren, solange sie nicht in die `.lang` nachgetragen sind. `lang/customizing/*.po` ist per Root-`.gitignore`
(`/lang/customizing/*.po`) ausgeschlossen.

**Build-Artefakt:** `setup build` ist funktional nicht nötig, aber empfohlen: bis dahin kompiliert die
Laufzeit die `.po` pro Request selbst (das Artefakt ist zurückdatiert, siehe Schritt 3). **Rückweg:** Sicherung aus `lang/customizing/` zurückkopieren bzw. `git checkout` der
Komponenten-Dateien (Entwicklungsumgebung) und die Werte ggf. neu eingeben – das Overlay enthält die
übernommenen Werte nicht mehr. Die `.po`-Sicherungen in `lang/customizing/` schließt die
Root-`.gitignore` aus.

**Filter "Konflikte"** (Lokale und Update-Änderungen): vergleicht jetzt auch PO-Module, mit der
Kopie ihrer Shipped-`.po`, die `save_dist` nach `<client_data_dir>/lang_data/` schreibt – analog zur
`.lang`-Kopie dort (`ilObjLanguageExt::getShippedChangesSinceBackup()`,
`ShippedPoMerger::findShippedChangesSinceBackup()`): Einträge (Formzeilen bei Plural), deren
Shipped-Wert neu ist oder von der Kopie abweicht, geschnitten mit den lokal geänderten Werten. Ein
PO-Modul ohne Kopie trägt nichts bei. Die merge-Sicherung in `lang/customizing/` ist – wie die
`.lang`-Sicherung dort – nur eine Sicherung, nicht die Vergleichsbasis des Filters.

**Case-Duplikate in den Sprachdateien:** `scan_shipped_case_duplicates.php` findet Identifier, die
in derselben `.lang` im selben Modul nur in Groß-/Kleinschreibung verschieden vorkommen
(`style#:#Style`/`style#:#style` in allen Sprachen, `content#:#cont_Link`/`cont_link` und
`cont_Media`/`cont_media` in 26, zwei `lti`-Keys in `hu`). Der Primärschlüssel von `lng_data` ist
case-insensitiv: Jeder Import der ganzen Datei hält die zweite Schreibweise für geändert und setzt
`local_change` (auch in trunk). Hinweis an die Sprachdatei-Pflege: `SHIPPED_CASE_DUPLICATES.md`.

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
- Plugin-`.lang` wird **nicht** bereinigt: unzulässiges Markup wird nur geloggt
  (`logShippedViolations()`), der Wert unverändert übernommen – sonst verlören Drittanbieter-Plugins
  ohne Vorwarnung Text. Bereinigt (`cleanShippedValues()`) wird nur eine Plugin-`.po`.
- Unterschied beim Leerraum: `.lang`-Werte werden wie bisher getrimmt, `.po`-Werte nicht. Wer ein
  Plugin von `.lang` auf `.po` umstellt, sollte Werte mit führendem/abschließendem Leerraum prüfen.
- `removeMigratedMoFiles()` validiert das Präfix (`^[A-Za-z0-9_]+$`), sonst Warnung und nichts tun.
- Plural-Einträge einer Plugin-`.po` landen mit ihrer Standardform in der Datenbank (Warnung im Log),
  siehe "Pluralformen".

**Ziel (Component Revision):** Plugins werden Komponenten unter `components/<Vendor>/<plugin>` und
tragen ein `LanguageFileDirectory` bei (z. B. mit Namensschema `'ilias_%s'`). Dann gilt automatisch
der ganze Core-Weg (Build-Artefakt, Delta-Overlay, Markup-Prüfung); die Übergangsdateien bleiben
unverändert nutzbar. Offen für später: Mit dem Wegfall der Slots ändert sich der Modulname (heute
`rep_robj_xtst`) — lokale Änderungen, Overlays und `txt()`-Aufrufe brauchen dann eine einmalige
Umbenennung oder das neue Verzeichnis übernimmt vorerst den alten Namen.

## Natives PHP-`gettext()`

Seit 2026-10-06 der einzige Laufzeit-Lesepfad migrierter Module (vorher bewusst nicht genutzt). Die
früheren Einwände sind so gelöst (verifiziert mit glibc 2.36/PHP 8.4 im Container und glibc
2.43/PHP 8.5):

- **Keine OS-Locales pro Sprache:** Der Build kopiert `C.utf8` der C-Bibliothek als
  `locale/ilias_messages`; `NativeGettext::activate()` lädt sie per `LOCPATH` und entfernt `LOCPATH`
  sofort wieder. `LANGUAGE=messages` macht den Katalogordner unabhängig von der Locale
  (`<gebundenes Verzeichnis>/messages/LC_MESSAGES/<domain>.mo`). `C`/`C.UTF-8` selbst übersetzen nicht.
- **Mehrere Sprachen pro Request:** Die Sprache steckt im Domainnamen (`<modul>.<lang>`); ein
  Wechsel über `LANGUAGE` wirkte wegen des glibc-Caches nicht.
- **`.mo` pro Prozess gecacht:** Jeder neue Stand bekommt ein neues Verzeichnis (Build-ID,
  Overlay-Revision); `bindtextdomain()` auf das neue Verzeichnis lädt ihn. Alte Builds/Revisionen
  bleiben im Speicher langlebiger FPM-Worker, bis diese neu starten.
- **Prozesszustand:** PHP setzt am Request-Ende selbst zurück (ext/standard, Request-Shutdown: mit
  `putenv()` gesetzte Variablen bekommen ihren alten Wert, eine mit `setlocale()` geänderte Locale
  wird auf `C` gesetzt). Geprüft am 2026-10-06 in FPM (`pm = dynamic`): ein Testskript setzte
  `LANGUAGE`, `LOCPATH` und `LC_MESSAGES` ohne Rücksetzung; der nächste Request desselben Workers
  (gleiche PID, 6 Requests je Worker) begann jeweils mit `LC_MESSAGES=C` und ohne `LANGUAGE`/`LOCPATH`.
  Eine eigene Shutdown-Funktion gibt es deshalb nicht mehr; `NativeGettext::restore()` bleibt nur für
  langlaufende Prozesse (Setup nach der Build-Prüfung). Kindprozesse (`exec()`) erben
  `LANGUAGE=messages` (harmlos: ein C-Programm beachtet es nur mit einer Locale ungleich `C`).
- **Voraussetzung:** PHP-Extension `gettext` (CLI und Webserver) und `C.utf8` unter
  `/usr/lib/locale` bzw. `/usr/lib64/locale`, dieselbe glibc für Build und Webserver; sonst bricht
  `setup build` ab bzw. die Laufzeit liefert `-key-` (siehe „Build-Artefakt"). Unter ZTS gelten
  Locale/`LANGUAGE` für alle Threads (Hinweis in der Sprachverwaltung).

## Rollback

`lng_data`/`lng_modules` werden für migrierte Module weiter geschrieben und sind damit jederzeit ein
aktueller Rollback-Stand. Rollback heißt deshalb "Code/Dateien entfernen", nie "Daten
zurückschreiben".

1. **Pro Modul:** die `$contribute[LanguageFileDirectory::class]`-Contribution der Komponente
   entfernen. Ohne Directory lesen alle Pfade aus der DB, und der Sync ist ein No-op; beim nächsten
   Update werden wieder die `.lang`-Zeilen des Moduls verwendet (für `tos` und `poll` sind sie in
   `lang/ilias_*.lang` weiterhin vorhanden, auch `poll_population_singular`). Wurden sie mit
   `--remove-from-lang` entfernt, müssen sie vorher aus git zurück (`git show <ref>:lang/ilias_<sprache>.lang`,
   siehe „`--remove-from-lang`"). Für `poll` hält die DB
   die Standardform der Plural-Einträge, also den bisherigen Wert; lokale Änderungen einzelner
   Formen außer der Standardform gehen beim Rollback verloren (sie stehen nur im Overlay).
2. **Pro Sprache:** die Shipped-`.po` der Sprache entfernen und `setup build` ausführen; dann liest
   `ilLanguage` `lng_modules`.
   (Nur die Overlay-Dateien zu löschen, genügt nicht: dann wird der Shipped-Stand ohne lokale
   Änderungen geliefert.)
   **Nach einem „merge"** stehen die übernommenen Werte nur noch in der Shipped-`.po` (Overlay und
   `lng_data.local_change` sind bereinigt, der Wert steht weiter in `lng_data`); die `.lang`-Zeilen
   des Moduls sind veraltet. Ein Rollback auf `.lang` verliert sie mit dem nächsten Update, solange
   sie nicht in die `.lang` nachgetragen werden.
3. **Build:** `artifacts/language/` kann gelöscht und mit `setup build` neu angelegt werden; bis dahin
   liefern migrierte Module `-key-` (kein Rückfall auf die DB).
4. **Gesamter Mechanismus:** den Lesepfad in `ilLanguage` und `ilObjLanguageExt` zurücknehmen und
   `ilLanguageSetupAgent::getBuildObjective()` wieder auf `NullObjective` setzen. Ein bereits
   geschrumpftes Overlay enthält weiterhin alle lokalen Änderungen, die DB ist vollständig.

## Deinstallation entfernt die Overlay-Dateien

`ilObjLanguage::uninstall()` ruft nach `flush()` `removeMigratedMoFiles()` auf, das für jedes
kontribuierte Modul `MigratedLanguageFileSync::removeOverlay()` ausführt (`current`, `.po`, alle
Revisionen, `lock` und das leere Verzeichnis `lang/<modul>/<lang>`).
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
Plural-Einträge erscheinen als eine Zeile pro Form (`poll_population [0]`, …), siehe "Pluralformen".

### Geshippte Werte in der Admin-GUI

Überall, wo die Admin-GUI bisher die globale Sprachdatei `lang/ilias_<sprache>.lang` als
"Standardwert" las, gilt für ein migriertes Modul die geshippte `.po`
(`ilObjLanguageExt::getShippedValues()`/`getShippedComments()`; `.lang`-`###`-Kommentar ↔
`.po`-Extracted-Comment `#.`): Vergleich mit dem Standardwert, Filter "hinzugefügt" und
"kommentiert", Export "merged" und `_saveValues()`. Der Filter "Konflikte" vergleicht mit den
Kopien, die `save_dist` im Datenverzeichnis ablegt: `.lang` für nicht migrierte Module, die Kopie der
Shipped-`.po` für migrierte (seit 2026-09-28, siehe „merge in die Shipped-`.po`"). Ist eine geshippte `.po`
nicht lesbar, zeigen die Leser ersatzweise die `.lang`-Zeilen und die GUI eine Warnung
(`lng_shipped_po_unreadable_fallback`); "clear" ändert in diesem Fall nichts.

**Informationen der Shipped-`.po` in der Admin-GUI** (seit 2026-09-28):

- **Nicht übersetzt:** Ein fuzzy Eintrag bekommt im Kommentar des Standardwerts den Marker
  `lng_not_translated` („Nicht übersetzt“, angehängt an eine `#.`-Notiz) — er ersetzt die früheren
  Datums-Marker „… new variable" der `.lang`-Dateien. Der Marker ist **nur Anzeige** (`getShippedCommentsForDisplay()`,
  `_getShippedMigratedComments()`): `getShippedComments()` — und damit der Filter "kommentiert",
  der Export "merged" (`getMergedRemarks()`) und `_saveValues()` — enthält nur die `#.`-Notizen.
- **Andere Vergleichssprache:** Die Kommentarspalte der Vergleichssprache zeigt deren Bemerkungen
  und, wo keine besteht, die `#.`-Notizen/den Marker ihrer Shipped-`.po`
  (`ilObjLanguageExt::_getShippedMigratedComments()`).
- **Filter:** "kommentiert" berücksichtigt `#.`-Notizen, "dbremarks" die Bemerkungen aus Overlay und
  `lng_data`, Formzeilen über die Bemerkung ihres Keys.
- **Aufwand:** Die Shipped-`.po` der Vergleichssprache werden nur für die angezeigten Module gelesen
  (Modulfilter bzw. Module der Seitenübersetzung; ohne Filter alle). `_getRemarks()` liest die
  Overlay-`.po` aller migrierten Module der Sprache (nur Module mit Overlay kosten etwas) — bei sehr
  vielen migrierten Modulen mit Overlay ggf. einschränken. Alle Bemerkungen und Notizen
  werden escaped ausgegeben (`ilLegacyFormElementsUtil::prepareFormOutput()`).

**"In die globale Sprachdatei übernehmen" (Übersetzungsmodus, "merge")**: migrierte Module werden
in ihre Shipped-`.po` übernommen, siehe „merge in die Shipped-`.po`"; ihre vorhandenen `.lang`-Zeilen
werden exakt so zurückgeschrieben, wie sie gelesen wurden (`ilLanguageFile::keepOriginalLines()`).

Bei abgelehnter Speicherung (Markup) zeigt die Tabelle wieder die gespeicherten Werte; die Eingaben
gehen verloren (das Tabellen-GUI kann POST-Werte nicht vorbelegen), die abgelehnten Keys werden
genannt.

## Bekannte Grenzen und offene Punkte

- **Rollout auf weitere Module:** braucht (a) eine `$contribute[LanguageFileDirectory::class]`-
  Contribution und (b) die `.pot`/`.po`-Dateien im `lang/`-Ordner der Komponente; danach
  `setup build`. Ein Overlay entsteht nur bei lokalen Änderungen.
- **Offen:** Artefakt-Konvention mit den Setup-Maintainern.
- Mitgelieferte Markup-Verstöße in `lang/*.lang`: gemeldet über `SHIPPED_MARKUP_VIOLATIONS.md`.
- Kein Reset-UI pro Eintrag. Bemerkungen stehen im Overlay, nicht in der Shipped-`.po` (dort nur
  `#.`-Notizen; erst „merge" macht aus einer Bemerkung eine `#.`-Zeile). Ein Import im Modus "delete" übernimmt die Bemerkungen der Datei, ersetzt aber die
  übrigen Overlay-Bemerkungen nicht. Beim Export bekommen Plural-Formzeilen keine Bemerkung (sie
  gehört zum Key).
- **Zwei externe Direktzugriffe auf `lng_data`** außerhalb der Language-Komponente:
  `ilNotificationDatabaseHandler::getTranslatedLanguageVariablesOfNotificationParameters()` und
  `ilDclStandardField::_getNonImportableStandardFieldTitles()`/`_getImportableStandardFieldTitle()`.
  Sie funktionieren dank Dual-Write; vor einer Abschaltung der DB-Tabellen müssten sie umgestellt
  werden.
- `LanguageInstallationManager` hat keinen injizierten Logger (`error_log()`-Fallback).
- Plugin-Sprachupdates und die Deinstallation melden ein nicht geschriebenes bzw. nicht entferntes
  Overlay nur im Log, nicht in der GUI.
- Pluralformen: Bemerkungen (`remarks`) gibt es nur für den Key, nicht pro Form (siehe
  "Pluralformen"). Ein Plural-Eintrag,
  der nicht (mehr) geshippt wird (nur im Overlay), wird in der DB unter seinen Formschlüsseln
  geführt. Ein exportierter `.lang`-Stand enthält Formzeilen (`poll_population [0]`), die ein
  System ohne migriertes `poll` als gewöhnliche Keys importieren würde. Wird die Formel einer
  Sprache später geändert (andere Formenzahl), passen bestehende Overlays erst nach dem nächsten
  abgleichenden Schreiben. **Offener Fehler (2026-09-30):** Dabei werden lokale Formwerte nach
  Formindex übernommen, nicht nach Bedeutung. Bei 3→2 geht die lokale Standardform verloren und die
  frühere Form 1 wird zur Standardform; bei 2→3 und 1→2 (angekündigter CLDR-49-Wechsel für `vi`)
  gilt der lokale Wert nicht mehr für die Standardform, die wieder den Shipped-Wert hat (bei 2→3
  auch in `lng_data`). Vor einer Formeländerung zu beheben.

## Tests

Relevante Tests liegen unter `components/ILIAS/Language/tests/`, u. a.
`ComponentTranslation/TranslationMarkupPolicyTest.php`, `ComponentTranslation/ShippedTranslationsTest.php`,
`ComponentTranslation/PlainLogTextTest.php`, `ComponentTranslation/MigratedLanguageFileSyncTest.php`,
`ComponentTranslation/MigratedLanguageFilePathsTest.php`, `ComponentTranslation/LocalChangeCommentsTest.php`,
`Setup/ShippedLanguageFilesCompiledObjectiveTest.php`, `Setup/LanguageInstallationManagerMigratedModulesTest.php`,
`Setup/ilLanguagesInstalledAndUpdatedObjectiveTest.php`, `PoMigrationLoadLanguageModuleTest.php`,
`PoMigrationWriteBackTest.php`, `ToJSMapTest.php`,
`ImportUsesShippedValuesOfMigratedModulesTest.php`, `Activities/AddLanguageEntryTest.php`,
`AdminGuiReadsValuesFromMigratedFileTest.php`, `UninstallRemovesMigratedMoFilesTest.php`,
`UninstallRemovesPluginMigratedMoFilesTest.php`, `ComponentTranslation/Catalog/TranslationCatalogPoTest.php`,
`ComponentTranslation/Catalog/TranslationCatalogMoTest.php` (Kompilat, gelesen mit dem Leser des
Fixtures `MigratedPoFixture`; die Laufzeit liest nativ),
`ConvertModuleToPoToolTest.php`.

Pluralformen und merge (Stand 2026-09-30):

- `PluralForms`: `ComponentTranslation/PluralFormsTest.php` (Formeln, Präzedenz, Randwerte, ungültige
  Header) und `ComponentTranslation/PluralFormsShippedRulesTest.php` (alle Header aus `plurals.json`
  gegen eine unabhängige CLDR-Referenz, n = 0…2000 und große Werte; eine Sprache ohne Referenz macht
  den Test rot).
- `PluralFormKey`: `ComponentTranslation/PluralFormKeyTest.php`; `ntxt()`: `NtxtTest.php`.
- Plural-Pfade: `ComponentTranslation/MigratedLanguageFileSyncPluralHelpersTest.php`,
  `ComponentTranslation/MigratedLanguageFileSyncPluralOverlayTest.php`,
  `Setup/LanguageInstallationManagerDatabaseRowsOfMigratedModuleTest.php`,
  `Setup/LanguageInstallationManagerMigratedModulesTest.php` (auch wachsende Formenzahl),
  `SaveValuesWritesPluralFormsTest.php`, `ImportLanguageFilePluralPlainKeyTest.php`,
  `WithPluralFormKeysTest.php`.
- merge und Sicherungen: `ComponentTranslation/ShippedPoMergerTest.php`; Filter "Konflikte":
  `ShippedChangesSinceBackupTest.php`.
- `TranslationCatalog::addInIdOrder()`: `ComponentTranslation/Catalog/TranslationCatalogPoTest.php`;
  `MigratedLanguageFilePaths::templateFileName()`: `ComponentTranslation/MigratedLanguageFilePathsTest.php`.

## Änderungshistorie

### 2026-10-09: `poll` mit Referenz `en`

- `poll_import` in `lang/ilias_en.lang` ergänzt, `poll`-Dateien mit Referenz `en` neu erzeugt.

### 2026-10-06: Konverter – Englisch als Vorlage

- Standard-Referenzsprache `en` statt `de` (überschreibbar); `.pot` und `msgid`-Liste kommen aus ihr.
- Fehlt einer Sprache ein Key der Referenz oder ist er leer: Referenzwert, `#, fuzzy` (Plural
  sinngemäß); Roundtrip-Prüfung erwartet den Referenzwert und das Flag; Statistik-Spalte `filled`.
- Ausgelieferte `tos`/`poll`-Dateien nicht neu erzeugt (`tos` unverändert, `poll` siehe „Englisch
  als Vorlage"); `ConvertModuleToPoToolTest`: `tos` mit `en`, `poll` weiter mit `de` und den drei
  aufgefüllten `poll_import`-Einträgen als einzigem Unterschied, zwei neue Tests zum Auffüllen.

### 2026-10-06: Laufzeit über natives gettext

- Lesepfad migrierter Module nur noch nativ (`MigratedTranslations`, `NativeGettext`), kein Rückfall
  auf `lng_modules` und keiner auf `gettext/gettext`; entfallen sind `ShippedTranslations::read()`/
  `readCompiled()` (Kompilieren zur Laufzeit), `TranslationCatalog::readMo*()`,
  `CompiledTranslations`, der Plural-Cache und `ilLanguage::logCrossModuleKeyCollisions()`.
- Build neu (`ShippedTranslationsBuild`, siehe „Build-Artefakt"): `current.json` + Build-Verzeichnis,
  Prüfung über natives gettext, Kollisionsliste; `index.json` und `<lang>/<modul>.mo` entfallen.
- Overlay neu unter `<client_data_dir>/lang/<modul>/<lang>/` mit Revisionen; der alte Ort wird nicht
  mehr gelesen (keine Migration).
- „merge" schreibt nur die Shipped-`.po` (kein Build, kein Zurückdatieren); die übernommenen
  Einträge bleiben markiert im Overlay, bis der Build die `.po` ausliefert (Hash pro Modul/Sprache in
  `current.json`) und `setup update` lief; der Webserver schreibt nicht in `artifacts/`.
- Kein Rückfall auf früher geladene Module: ein im zuständigen migrierten Modul nicht übersetzter
  Key ergibt `-key-` (bewusste Verhaltensänderung, siehe „Laufzeit-Integration").
- Kein eigenes Zurücksetzen am Request-Ende: PHP setzt `putenv()`/`setlocale()` selbst zurück
  (in FPM geprüft); `reactivateIfLost()` prüft `LC_MESSAGES` und `LANGUAGE`.
- Leeres Overlay entfernt seine Revisionen sofort; der Lesepfad des Overlays folgt keinen Symlinks.
- Sprachverwaltung zeigt eine MessageBox, wenn natives gettext nicht verfügbar ist (Texte mit
  englischem Fallback, bis die Keys `lng_native_gettext_unavailable`/`lng_native_gettext_thread_safe`
  in `lang/ilias_*.lang` stehen).
- Tests: `ShippedArtifactProblemLoggingTest.php` entfernt (Verhalten entfallen); Leser-Robustheitstests
  in `TranslationCatalogMoTest.php` entfernt; übrige Tests auf das neue Layout umgestellt
  (`MigratedPoFixture::resetRuntime()` lenkt Builds in ein temporäres Artefaktverzeichnis).

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

### 2026-09-28: Planung der nächsten Schritte

- Abschnitt "Abweichungen vom Feature Request" mit Begründungen ergänzt (gleichlautend im FR, 2.7).
- Entschieden: Plural-Unterstützung kommt in diesen Branch, zweiter Pilot `poll` (siehe
  "Pluralformen"), Umstellung der `Poll`-Aufrufer nur als Vorschlag. Module ohne eigene Komponente
  (sicher: `common`, `cptch`, `bkm`, `pdesk`) sollen in die Language-Komponente; offen sind
  `assessment`, `content`, `pd`, `scormtrac`/`scov`. Der Branch wird nicht in mehrere PRs geteilt.

### 2026-09-28: Pluralformen und zweiter Pilot `poll`

1. **`PluralForms`** (Parser/Auswerter der `Plural-Forms`-Formel, Standardform-Regel, germanischer
   Fallback mit Warnung) und **`PluralFormKey`** (`<key> [<form>]`).
2. **Adapter:** `TranslationEntry::getPluralId()/isPlural()/getPluralTranslations()/setPlural()`,
   `TranslationCatalog::readMoMessages()/readMoMessagesFromString()` mit `CompiledTranslations`;
   `.mo` wird selbst gelesen (kein `MoLoader`); `readMoTranslations*()` liefert für Plural-Einträge
   die Standardform; Formenzahl in der `.mo` = `nplurals`. Build: `FORMAT_VERSION` 3, jede Form
   bereinigt, Warnung bei fehlendem/ungültigem Header.
3. **`ilLanguage::ntxt()`**; `txt()` unverändert (Standardform).
4. **Formzeilen** in Sync, Overlay (`original[<form>]`), Drei-Wege-Abgleich, Installation,
   Admin-GUI (`_saveValues()`/`_deleteValues()`/Filter/Feldnamen), „Neue Variable";
   DB-Dual-Write nur mit Standardform; Plugin-`.po`-Brücke schreibt die Standardform und loggt.
5. **Konverter:** `plurals.json` (Tabelle für 31 Sprachen, Plural-Definitionen pro Modul),
   Roundtrip-Prüfung für Plural; `tos` bleibt byte-identisch.
6. **`poll` migriert:** `Poll.php` kontribuiert `ComponentLanguageFileDirectory` für `poll`,
   `components/ILIAS/Poll/lang/poll.pot` und 31 `poll_*.po` per Konverter (Referenz `de`, weil `en`
   `poll_import` fehlt). Root-`lang/ilias_*.lang` unverändert. Plural-Einträge: `poll_population`
   (Singular `poll_population_singular`), `poll_vote_error_multi`.
7. Nach Code-/Security-Review: Bemerkungen des Keys bleiben bei Formzeilen erhalten;
   `local_change` der DB-Zeile nur bei abweichender Standardform (`changedPluralMessages()`
   entfernt); Bemerkung: erste abweichende Form gewinnt; Fuzzy auch vom Singular-Key
   (`poll_*.po` neu erzeugt); Header-Regex begrenzt und possessiv; Formenzahl der `.mo` aus
   `PluralForms`; Längensumme der `.mo`-Strings gedeckelt; überzählige Formzeilen werden verworfen;
   leere Shipped-Übersetzungen werden nicht mehr als `''` in `lng_data` geschrieben (Runtime-Check:
   `poll/poll_import/en`).
8. Nutzerentscheidungen: **Standardform = letzte Form** (`PluralForms::defaultFormIndexForCount()`
   = nplurals−1, bei leerer Form abwärts die nächste nicht leere) statt `msgstr[1]`; es/fr/it/pt
   bleiben dreiformig, Quelle CLDR 48.2 in `plurals.json` dokumentiert; Konverter markiert kopierte
   Plural-Werte als fuzzy (`poll_*.po` neu erzeugt, 16 Dateien, nur `#, fuzzy`-Zeilen; `tos`
   byte-identisch). Das Build-Kompilat enthält die Regel nicht (die `.mo` hat alle Formen), der
   Fingerprint ändert sich trotzdem mit dem Hash von `PluralForms`, `FORMAT_VERSION` bleibt 3.
9. Nebenbefunde der Platzhalterprüfung (nicht geändert, Pflege der `.lang`): `fr`
   `poll_vote_error_multi` („de% réponses"), `sv` `poll_voting_period_info` ohne `%s`, `tr`
   `poll_population`/`poll_block_results_available_on` („% s"), `pt` `poll_population` (falscher Text
   ohne `%s`); `en`, `ja`, `pt` fehlt `poll_import`.

### 2026-09-28: Bemerkungen im Overlay, Shipped-Informationen in der Admin-GUI

1. **Bemerkungen im Overlay** (`# remark:`/`# remark_escaped:`), reine Bemerkungseinträge mit leerem
   `msgstr`, `sync(..., $remarks)`, `loadRemarks()`, `setRemarks()`, `belongsInDelta(..., $remark)`;
   Setup übernimmt Bemerkungen aus `lng_data`/Customizing, „Lokale Änderungen entfernen" entfernt
   sie; Admin-GUI schreibt sie ins Overlay und liest sie von dort (Fallback `lng_data`).
2. **Admin-GUI:** Marker `new variable` für fuzzy Einträge, `#.`-Notizen der Vergleichssprache,
   Filter "dbremarks" mit Formzeilen. Vorschlag für einen eigenen Key (Root-`lang/*.lang`, nicht
   angelegt): `administration#:#language_not_translated#:#Not translated` / `Nicht übersetzt`.
3. Vorab an der Instanz bestätigt: `setup update` behält Bemerkungen lokal geänderter Zeilen.
4. Nach Code-/Security-Review: geshippte `###`-Kommentare/`#.`-Notizen/Datums-Marker aus
   `lng_data.remarks` werden nicht als Bemerkungen ins Overlay übernommen; Bemerkungen mit
   `mb_substr` auf 250 Zeichen, ungültiges UTF-8 verworfen, Overlay-UTF-8-Prüfung vor dem Schreiben;
   Remark-only-Einträge sortiert; Fuzzy-Marker nur Anzeige; Vergleichssprache nur für angezeigte
   Module; Overlay-Bemerkungen beim Löschen nur für migrierte Module.
5. Fix nach Browser-Test: Wartung „Lokale Änderungen in der Datenbank löschen" ("clear") ließ
   Bemerkungen migrierter Module (Remark-only-Overlay, `lng_data.remarks`) stehen, weil sich dort
   kein Wert änderte; jetzt entfernt, `.lang`-`###`-Kommentare migrierter Module werden nicht mehr
   als Bemerkungen übernommen.

### 2026-09-28: Wartungsaktionen vereinheitlicht, Plural-Plain-Key beim Import, Case-Duplikate

1. **Fix B1:** Ein einfacher Key eines Plural-Eintrags in einem Import (load, Upload, alle Modi) ging
   verloren (`mapLegacyPluralValues()` verwarf ihn neben den Formzeilen). `saveValues()` bildet ihn
   jetzt vorher auf die Standardform ab (`MigratedLanguageFileSync::defaultFormKeyOf()`, auch von
   `AddLanguageEntry` genutzt); die Bemerkung wandert mit.
2. **„clear" = „Lokale Änderungen entfernen":** Die Wartungsaktion führt die Activity
   `RemoveLocalLanguageChanges` aus (vorher `importLanguageFile(…, "replace", true)`, das lokal
   hinzugefügte Keys stehen ließ). Das Neueinspielen der Plugin-Sprachdateien liegt jetzt in der
   Activity (vorher in `ilObjLanguageFolderGUI`). Der damit tote Reset-Zweig ist entfernt:
   `importLanguageFile()` ohne `$refreshOriginalFromShipped` (neu: `($file, $mode,
   $skipInvalidMarkup)`), `_saveValues()` ohne diesen Parameter, `removeRemarksOfMigratedModules()`
   gelöscht.
4. Nach Review: einfache Plural-Keys werden schon beim Einlesen der Importdatei auf die
   Standardform abgebildet (`withPluralFormKeys()`, gemeinsam mit `saveValues()`), damit
   "keepall"/"keepnew" und die Markup-Prüfung dieselben Keys sehen; „Lokale Änderungen entfernen"
   prüft nur den Shipped-Stand (ungültige Customizing-Datei blockiert nicht) und meldet einen
   Fehler beim Neuaufbau mit Rückweg im Log.
3. **Case-Duplikate:** `scan_shipped_case_duplicates.php` und `SHIPPED_CASE_DUPLICATES.md` (Befund
   `style/style/de` mit `local_change` nach "clear"); kein Code-Fix.

### 2026-09-28: merge in die Shipped-`.po` (Variante A)

1. **`ShippedPoMerger`** (neu): „merge" übernimmt die Overlays der PO-Module in ihre Shipped-`.po`
   (Werte, Pluralformen, Bemerkungen als angehängte `#.`-Zeile, neue Keys auch in die `.pot`),
   Sicherung nach `lang/customizing/`, atomar, unter dem Overlay-Lock; danach Overlay-Abgleich
   (`refresh_original_from_shipped`) und `lng_data.local_change`/`remarks` der übernommenen Keys
   `NULL` (Abweichung von trunk begründet unter „merge in die Shipped-`.po`").
   `ilObjLanguageExt::mergeLocalChangesIntoGlobalLanguageFile()` liefert statt der Liste
   übersprungener Module ein Ergebnis-Array (`written`, `skipped`, `invalid_markup`, `not_merged`,
   `unwritten_overlay`); kein Aufrufer außerhalb der Language-Komponente.
2. **`save_dist`** sichert auch die Shipped-`.po` nach `lang_data/`; der Filter "Konflikte" vergleicht
   PO-Module damit (`getShippedChangesSinceBackup()`).
3. Gemeinsame Helfer: `MigratedLanguageFilePaths::templateFileName()`/`shippedTemplatePath()` (auch
   vom Konverter genutzt, Ausgabe für `poll` byte-identisch), `TranslationCatalog::addInIdOrder()`,
   öffentlich gemacht: `MigratedLanguageFileSync::findDirectory()`, `isRemarkOnly()`, neu
   `readOverlayCatalog()`. Der Build-Fingerprint ändert sich (Quellen von Catalog/Sync), der nächste
   `setup build` ist ein Vollbau.
4. Grundsatz ergänzt: Shipped-Dateien werden zur Laufzeit nie geschrieben – Ausnahme merge im
   Übersetzungsmodus, wie bisher für `.lang`.
5. Nach Code-/Security-Review: LANGMODE-Prüfung serverseitig; `ilObjLanguageExtGUI` implementiert
   `ilCtrlSecurityInterface` (ctrl-Artefakt neu bauen); Confinement der Dateien auf das
   ILIAS-/Client-Datenverzeichnis; eigener Lock für die `.pot`
   (`MigratedLanguageFileSync::withTemplateLock()`); Artefakt nach merge zurückdatiert; DB-Fehler
   eines Moduls bricht die übrigen nicht ab (`unwritten_database`); übersprungene Module als Fehler
   gemeldet, nicht übernommene Keys getrennt von Markup-Verstößen; `#.`-Zeilen ohne Steuer-/Bidi-Zeichen
   (`TranslationEntry::plainLine()`, `tos`/`poll` byte-identisch).

### 2026-09-28: Sprach-Keys für merge und den Marker „Nicht übersetzt“

- Neu in `lang/ilias_en.lang` und `lang/ilias_de.lang` (Modul `lng`, Präfix `lng_` wie die übrigen
  Keys dieses Branches): `lng_not_translated` (Marker für fuzzy Einträge, ersetzt das Literal
  `new variable`; statt des geplanten `administration#:#language_not_translated`),
  `lng_merged_po_modules`, `lng_merge_po_not_written`, `lng_merge_database_not_updated` (Meldungen
  von merge, statt `language_error_write_global` bzw. `error`).
- Entfernt: `lng_merge_skipped_po_modules` (seit merge in die Shipped-`.po` ungenutzt).
- Nach dem Einspielen: Sprachen aktualisieren (`setup update` bzw. „Aktualisieren“), damit die Keys
  in der Datenbank stehen.

### 2026-09-30: Identifier in mehreren Modulen

- `scan_shipped_cross_module_duplicates.php` und `SHIPPED_CROSS_MODULE_DUPLICATES.md`: Bestand und
  Folgen für den Rollout unter „Uniqueness: Kollisionen zwischen Modulen“.
- `ilLanguage::logCrossModuleKeyCollisions()` meldet eine Warnung pro Modulpaar statt pro Identifier
  (Zahl + die ersten fünf Identifier), damit Paare wie `cmix`/`lti` nach dem Rollout das Log nicht
  füllen. Test: `testLogsOneWarningPerModulePairListingTheCollidingIdentifiers()`.

### 2026-09-30: Sprachname im Header

- `convert_module_to_po.php` schreibt `Language-Team: <englischer Name>` (aus `meta_l_<sprache>` in
  `lang/ilias_en.lang`), `Language` bleibt das Kürzel. `tos_*.po` und `poll_*.po` neu erzeugt
  (62 Dateien, nur diese Header-Zeile; `.pot` unverändert). Test:
  `testLanguageTeamHeaderNamesTheLanguageInEnglishFromTheMetaModule()`.

### 2026-09-30: Tests nachgezogen

- Neu: `PluralFormsShippedRulesTest.php`, `ShippedChangesSinceBackupTest.php`; ergänzt:
  `ShippedPoMergerTest.php` (Plural-merge, Änderungen seit Sicherung, defekte Sicherung),
  `LanguageInstallationManagerMigratedModulesTest.php` (Formenzahl 1→2, 2→3). Abschnitt "Tests"
  aktualisiert.
- Befund: Übernahme lokaler Formwerte bei geänderter Formenzahl, siehe "Bekannte Grenzen".
