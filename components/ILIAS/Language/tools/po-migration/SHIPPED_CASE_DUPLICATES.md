# Identifier, die sich nur in Groß-/Kleinschreibung unterscheiden (`lang/ilias_*.lang`)

Hinweis an die Pflege der Sprachdateien. Die Identifier unten stehen in derselben Sprachdatei im
selben Modul in zwei Schreibweisen, die sich nur in Groß-/Kleinschreibung unterscheiden. Der
Primärschlüssel von `lng_data` (`module`, `identifier`, `lang_key`) vergleicht ohne Rücksicht auf
Groß-/Kleinschreibung (`utf8mb3_general_ci`): Die Datenbank hält für beide **eine** Zeile, PHP
(`ilLanguageFile`, `ilObjLanguageExt::_saveValues()`) führt **zwei** Keys.

Folge (auch in trunk): "Lokale Änderungen löschen"/"clear" und jeder Import der ganzen Datei finden
für die zweite Schreibweise keinen Datenbankwert, halten sie für geändert und schreiben die gemeinsame
Zeile mit `local_change` (gesehen an der Dev-Instanz: `style/style/de` nach "clear"). Welche
Schreibweise zur Laufzeit gilt, hängt von der Reihenfolge der Zeilen ab.

Die Kollation setzt auch die meisten Akzente gleich (`é` = `e`); das Skript vergleicht deshalb
zusätzlich transliteriert (ASCII) – Stand 2026-09-28 ohne weiteren Treffer.

Empfehlung: je Paar eine Schreibweise entfernen (die ohne Aufrufer), in allen Sprachdateien.
„Aufrufer" sucht nur literale `txt('…')`/`txt("…")`; dynamisch zusammengesetzte Keys, Templates
u. ä. vor dem Entfernen zusätzlich prüfen.

Erzeugt mit `php components/ILIAS/Language/tools/po-migration/scan_shipped_case_duplicates.php`
(Stand 2026-09-28):

5 Identifier mit Varianten, die sich in derselben Sprachdatei nur in Groß-/Kleinschreibung unterscheiden

| Modul | Varianten | Betroffene Dateien | Fundstellen (Datei:Zeile) | Aufrufer (`txt('…')`) |
|---|---|---|---|---|
| `content` | `cont_Link`, `cont_link` | fast alle (26) | `cont_Link`: lang/ilias_ar.lang:6149<br>`cont_link`: lang/ilias_ar.lang:6672 | `cont_Link`: keine<br>`cont_link`: components/ILIAS/COPage/PC/MediaObject/ImageMapTableBuilder.php:90, components/ILIAS/COPage/PC/Paragraph/MenuGUI.php:295, components/ILIAS/COPage/PC/Section/class.ilPCSectionGUI.php:279, components/ILIAS/Glossary/Table/class.TermUsagesTable.php:77, components/ILIAS/Glossary/Table/class.TermUsagesTable.php:290 … (+3) |
| `content` | `cont_Media`, `cont_media` | fast alle (26) | `cont_Media`: lang/ilias_ar.lang:6152<br>`cont_media`: lang/ilias_ar.lang:6739 | `cont_Media`: keine<br>`cont_media`: components/ILIAS/COPage/Page/class.PageQueryActionHandler.php:542 |
| `lti` | `conf_privacy_ident_il_uuid_SHA256`, `conf_privacy_ident_il_uuid_sha256` | hu | `conf_privacy_ident_il_uuid_SHA256`: lang/ilias_hu.lang:11261<br>`conf_privacy_ident_il_uuid_sha256`: lang/ilias_hu.lang:11269 | `conf_privacy_ident_il_uuid_SHA256`: keine<br>`conf_privacy_ident_il_uuid_sha256`: components/ILIAS/CmiXapi/classes/class.ilCmiXapiSettingsGUI.php:430, components/ILIAS/CmiXapi/classes/class.ilObjCmiXapiAdministrationGUI.php:360, components/ILIAS/LTIConsumer/classes/class.ilLTIConsumeProviderFormGUI.php:346, components/ILIAS/LTIConsumer/classes/class.ilLTIConsumeProviderFormGUI.php:742 |
| `lti` | `conf_privacy_ident_il_uuid_SHA256_info`, `conf_privacy_ident_il_uuid_sha256_info` | hu | `conf_privacy_ident_il_uuid_SHA256_info`: lang/ilias_hu.lang:11262<br>`conf_privacy_ident_il_uuid_sha256_info`: lang/ilias_hu.lang:11270 | `conf_privacy_ident_il_uuid_SHA256_info`: keine<br>`conf_privacy_ident_il_uuid_sha256_info`: components/ILIAS/CmiXapi/classes/class.ilCmiXapiSettingsGUI.php:433, components/ILIAS/CmiXapi/classes/class.ilObjCmiXapiAdministrationGUI.php:363, components/ILIAS/LTIConsumer/classes/class.ilLTIConsumeProviderFormGUI.php:349, components/ILIAS/LTIConsumer/classes/class.ilLTIConsumeProviderFormGUI.php:745 |
| `style` | `Style`, `style` | fast alle (31) | `Style`: lang/ilias_ar.lang:15934<br>`style`: lang/ilias_ar.lang:16361 | `Style`: keine<br>`style`: components/ILIAS/Style/System/classes/Config/class.ilSystemStyleConfigGUI.php:334 |

## Herkunft der Varianten

Von Hand ermittelt, nicht Teil der Skriptausgabe (Stand 2026-09-30): erster Commit, der die Zeile
`<modul>#:#<identifier>#:#` einfügt (`git log -S … -- lang/ilias_en.lang`, für `lti` zusätzlich
`lang/ilias_hu.lang`). Nicht gewertet sind `c590ec16916` und `e16b4cb8695` vom 2026-06-10: Der erste
löscht versehentlich alle `.lang`-Dateien, der zweite stellt sie gleich wieder her.

| Modul | Variante | Erstmals hinzugefügt | Neuer | Befund |
|---|---|---|---|---|
| `content` | `cont_link` | 2004-01-13, `8f1114deaeb` (Killing, „list map areas") | | alt, hat Aufrufer |
| | `cont_Link` | 2019-04-05, `4c5c07bc5af` (Killing, Bug 25069) | ja | Stil-Charakteristik „Link", kollidiert mit dem alten Key. `82d36f3a296` (2026-01-19) benennt ihn **nur in en/de** in `cont_char_link` um (Aufrufer `ilPCSectionGUI`). In den übrigen Dateien steht noch `cont_Link`, und `cont_char_link` fehlt dort |
| `content` | `cont_Media` | 2009-01-07, `3f486dc4252` (Killing, „merged style editing from scorm branch") | | alt. `82d36f3a296` benennt ihn nur in en/de in `cont_char_media` um (Aufrufer `ilPCMediaObjectGUI`) |
| | `cont_media` | 2021-09-02, `7afe44398f2` (Killing, Bug 30421) | ja | eigener Key mit anderer Bedeutung („Images/Media"), kein Ersatz für `cont_Media` |
| `lti` (hu) | `conf_privacy_ident_il_uuid_SHA256` (+`_info`) | en 2022-08-01, `b5bbf72631e` (Kohnle); hu 2024-08-26, `879f009648e` (F. Wolf) | | im Modul `lti` aller Sprachen vorhanden |
| | `conf_privacy_ident_il_uuid_sha256` (+`_info`) | hu 2026-08-07, `7930fa8f486` (Kunkel, „Modified error message") | ja | kam mit der Neufassung von `ilias_hu.lang` zusätzlich unter `lti` dazu, statt die alte Zeile zu ersetzen. In en steht die Kleinschreibung nur im Modul `cmix` |
| `style` | `Style`, `style` | 2016-08-22, beide in `4ca6c6bb67c` (Amstutz, System Styles) | – | im selben Commit doppelt angelegt, beide mit Wert „Style" |

Folgerung für die Empfehlung oben:
- `cont_Link`/`cont_Media`: in den Dateien außer en/de nicht löschen, sondern wie in `82d36f3a296` in
  `cont_char_link`/`cont_char_media` umbenennen. Sonst geht der vorhandene Wert verloren, obwohl der
  Code den neuen Key aufruft.
- `lti` (hu): die neuere Kleinschreibung hätte die Großschreibung ersetzen sollen.
- `style`: doppelte Anlage, `Style` (ohne Aufrufer) entfernen.
