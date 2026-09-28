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
