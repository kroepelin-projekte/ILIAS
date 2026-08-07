# Entwickler-Leitfaden: Map-Datenschicht (`LSAdaptivePosition` & `LSMapDataBuilder`)

Dieser Leitfaden richtet sich an **Entwickler:innen**, die die Map-Daten einer
adaptiven Learning Sequence (LSO) im Frontend an eine **JavaScript-Bibliothek**
anbinden (z. B. zum Zeichnen des „Wasserfall"-Graphen). Er erklärt:

1. was die Datenschicht liefert und wie man sie aufruft,
2. welche Rolle `LSAdaptivePosition` und `LSMapDataBuilder` spielen,
3. **wie die Daten konkret aussehen** (JSON-Struktur für die JS-Lib).

Alle beteiligten Klassen liegen im Ordner
`classes/Player/Map/` im Namespace `ILIAS\LearningSequence\Player\Map`.

---

## 1. Überblick

Die Map-Datenschicht ist eine **reine Datenschicht** – sie rendert nichts und
berechnet kein Layout. Sie beschreibt für **einen** User (`usr_id`) innerhalb
**einer** LSO (`lso_obj_id`), welche Objekte es gibt, wie sie zusammenhängen
(gerichteter Graph) und in welchem Zustand sie sich für diesen User befinden.

Das Layout des Wasserfalls (Ebenen, Spalten, kreuzungsfreie Kanten) ist
**Aufgabe der JS-Lib im Frontend**, nicht der Datenschicht.

```
PHP (Server)                                  JS (Client)
──────────────────────────────────────────   ─────────────────────────
LSAdaptivePosition  ─┐
                     ├─►  LSMapDataBuilder ─► LSMap ─► toArray() ─► json_encode ─► JS-Lib
AdaptiveNavigator   ─┘         (BFS)        │
LSLearnerItem[]     ─┘                      └─► LSMapNode[] (die Knoten)
```

- **`LSAdaptivePosition`** kennt die Position des Users im Objekt-Graphen
  (Start/Ende, aktuelle Position, gelaufener Pfad, Besuchszähler, Nachfolger,
  „abgeschlossen?"). Die öffentliche API spricht durchgängig `obj_id`.
- **`AdaptiveNavigator`** liefert Kanten (Nachfolger/Vorgänger), prüft die
  Bedingungen (`canLeave`/`canEnter`) und die Condition-IDs eines Objekts.
- **`LSMapDataBuilder`** ist der **Aggregator**: Er läuft den Graphen per
  Breitensuche (BFS) ab dem Startobjekt ab und baut daraus ein `LSMap` mit
  `LSMapNode`-Objekten.

---

## 2. Aufruf aus dem Server-Code

**Wichtig:** Du konstruierst den `LSMapDataBuilder` **nicht** selbst. Zwar
verlangt sein Konstruktor mehrere Parameter (`AdaptiveNavigator`,
`LSUrlBuilder`, `goto_command`, `lso_obj_id`, den Default-`usr_id` sowie zwei
Fabrik-Closures für `LSAdaptivePosition` und die `LSLearnerItem[]`) – diese
werden aber komplett von der lokalen DI der Learning Sequence verdrahtet. Du
holst dir den **fertig zusammengebauten** Builder aus dem DI-Container
(`class.ilLSLocalDI.php`, Service-Key `map.data_builder`) und rufst daran nur
noch `build($mode[, $usr_id])` auf.

Die beiden Parameter, die du selbst übergibst, sind also der **Sichtmodus**
und – optional – die **Nutzer-ID**:

- **`$mode`** – der Sichtmodus (siehe unten).
- **`$usr_id`** – *optional*. Lässt du ihn weg (oder `null`), wird die Map für
  den **aktuellen User** gebaut. Übergibst du eine konkrete `usr_id`, bekommst
  du die Map **dieses** Lernenden – gedacht z. B. für eine spätere Tutor-Sicht,
  die die Karten mehrerer Teilnehmer nebeneinander anzeigt. Der Builder baut
  Position und Lernerdaten intern pro `usr_id` frisch auf.

Innerhalb einer GUI, die bereits Zugriff auf die lokale DI der LSO hat, sieht
das so aus:

```php
use ILIAS\LearningSequence\Player\Map\LSMapViewMode;

/** @var \ILIAS\LearningSequence\Player\Map\LSMapDataBuilder $builder */
$builder = $ls_local_di["map.data_builder"]; // fertig konstruiert aus der DI

// Map des aktuellen Users (usr_id weggelassen):
$map = $builder->build(LSMapViewMode::MODE_FULL_ROUTE);

// Map eines bestimmten Users (z. B. Tutor-Sicht):
$map_of_learner = $builder->build(LSMapViewMode::MODE_FULL_ROUTE, $some_usr_id);

// Für die JS-Lib in JSON umwandeln:
$json = json_encode($map->toArray());
```

`$json` kann dann z. B. per `il.UI`/Template oder als `data-…`-Attribut an das
JS übergeben werden.

> Nur falls du (z. B. in einem Test oder außerhalb der LSO-DI) den Builder
> ausnahmsweise selbst instanziieren musst, füllst du die Konstruktor-Parameter
> so, wie es der Service `map.data_builder` in `class.ilLSLocalDI.php` vormacht.
> Im Normalfall ist das nicht nötig.

### Sichtmodi (`LSMapViewMode`)

Der Parameter von `build()` steuert **nur die Sicht**, niemals die
Berechtigung (die steckt immer in `can_access` pro Knoten):

| Konstante                          | Bedeutung |
|------------------------------------|-----------|
| `LSMapViewMode::MODE_FULL_ROUTE`   | Komplette Route inkl. aller Verzweigungen und Sackgassen. Die Traversierung folgt dem **konfigurierten** Graphen (`getStructuralSuccessors()`), gesperrte Objekte bleiben also als Knoten mit `can_access = false` enthalten; Objekte ohne Verbindung zum Startobjekt werden als eigene Wurzel ergänzt. |
| `LSMapViewMode::MODE_REACHABLE_ONLY` | Nur Knoten, die aktuell erreichbar sind (`can_access`), plus bereits besuchte (rückwärts erreichbare „Ehrenrunden") und der aktuelle Knoten. Kanten auf herausgefilterte Knoten werden entfernt. |
| `LSMapViewMode::MODE_PROGRESS`     | Wie `FULL_ROUTE`; gedacht als Hinweis für die UI, den Fortschritt (`has_visited`/`has_completed`) hervorzuheben. |

---

## 3. Datenstruktur für die JS-Lib

`LSMap::toArray()` erzeugt genau folgende Struktur. Die `nodes` sind eine
**Liste** (JSON-Array), nicht ein nach `obj_id` verschlüsseltes Objekt.

```json
{
  "lso_obj_id": 42,
  "usr_id": 6,
  "mode": 1,
  "start_obj_id": 101,
  "end_obj_id": 104,
  "nodes": [
    {
      "obj_id": 101,
      "title": "Einführung",
      "description": "Kurze Einleitung in das Thema.",
      "icon": "assets/images/standard/icon_lm.svg",
      "player_link": "https://ilias.example/goto.php?...&lsocmd=goto&lsov=101",
      "can_access": true,
      "has_visited": true,
      "has_completed": true,
      "situation": "start",
      "successors": [102, 103],
      "input_condition_ids": [],
      "output_condition_ids": [7],
      "visit_count": 1,
      "last_visited_ts": 1732531200,
      "is_current": false,
      "is_on_walked_path": true,
      "depth": 0
    },
    {
      "obj_id": 102,
      "title": "Lernmodul A",
      "description": "",
      "icon": "assets/images/standard/icon_lm.svg",
      "player_link": "https://ilias.example/goto.php?...&lsocmd=goto&lsov=102",
      "can_access": true,
      "has_visited": true,
      "has_completed": false,
      "situation": "straight",
      "successors": [104],
      "input_condition_ids": [11],
      "output_condition_ids": [12],
      "visit_count": 2,
      "last_visited_ts": 1732534800,
      "is_current": true,
      "is_on_walked_path": true,
      "depth": 1
    },
    {
      "obj_id": 104,
      "title": "Abschlusstest",
      "description": "",
      "icon": "assets/images/standard/icon_tst.svg",
      "player_link": null,
      "can_access": false,
      "has_visited": false,
      "has_completed": false,
      "situation": "end",
      "successors": [],
      "input_condition_ids": [21],
      "output_condition_ids": [],
      "visit_count": 0,
      "last_visited_ts": null,
      "is_current": false,
      "is_on_walked_path": false,
      "depth": 2
    }
  ]
}
```

### Felder des Containers (`LSMap`)

| Feld         | Typ               | Bedeutung |
|--------------|-------------------|-----------|
| `lso_obj_id`   | `int`      | obj_id der Learning Sequence, zu der die Map gehört. |
| `usr_id`       | `int`      | User, für den die Map berechnet wurde. |
| `mode`         | `int`      | Der verwendete `LSMapViewMode` (1/2/3). |
| `start_obj_id` | `int`      | **obj_id des Startobjekts** der LSO (`0`, falls keins konfiguriert). |
| `end_obj_id`   | `int`      | **obj_id des Endobjekts** der LSO (`0`, falls keins konfiguriert). |
| `nodes`        | `object[]` | Die Knotenliste (siehe unten). |


Merksätze für den Aufbau:

- **Einstieg = `start_obj_id`.** Von hier aus über `successors` (ebenfalls
  `obj_id`s) traversieren; das ist genau die Kantenrichtung des Graphen.
- **Abschluss = `end_obj_id`.** Praktisch für Hervorhebung/„Ziel erreicht" und
  um zu prüfen, ob der Endknoten schon `can_access`/`has_completed` ist.
- **`0` bedeutet „nicht konfiguriert".** Ist `start_obj_id` oder `end_obj_id`
  gleich `0`, gibt es serverseitig kein Start-/Endobjekt; dann auf
  `situation`/`depth` zurückfallen (z. B. Knoten mit `depth === 0` als Start).
- **Kein Mischen mit `ref_id`.** Alle IDs im JSON (`start_obj_id`, `end_obj_id`,
  `obj_id`, `successors`) sind `obj_id`s desselben Adressraums und lassen sich
  direkt vergleichen und als Map-Key nutzen.

### Felder eines Knotens (`LSMapNode`)

| Feld                   | Typ           | Bedeutung / Nutzung im Frontend |
|------------------------|---------------|---------------------------------|
| `obj_id`               | `int`         | **Eindeutige ID** des Knotens innerhalb der LSO. Als Knoten-Key in der JS-Lib verwenden. |
| `title`                | `string`      | Anzeigetitel des Objekts. |
| `description`          | `string`      | Beschreibung (kann leer sein). |
| `icon`                 | `string`      | Pfad zum Typ-Icon des Objekts (`LSItem::getIconPath()`), leer wenn keines vorhanden. |
| `player_link`          | `string\|null`| Direktlink in den Player zu diesem Objekt. `null`, wenn kein Zugriff (`can_access = false`). Nur setzen/verlinken, wenn nicht `null`. |
| `can_access`           | `bool`        | Darf der User dort hinein? Harte Regel für Klickbarkeit – **unabhängig vom Sichtmodus**. `true`, sobald **mindestens ein** Vorgänger verlassen werden darf (mehrere eingehende Kanten sind Alternativpfade) und alle Input-Conditions erfüllt sind, die keine Kante darstellen. |
| `has_visited`          | `bool`        | Wurde das Objekt jemals besucht (inkl. später verlassener Äste)? |
| `has_completed`        | `bool`        | Sind die Output-Conditions erfüllt (=„abgeschlossen" im adaptiven Sinne)? Immer `false`, wenn `can_access = false` – ein gesperrtes Objekt kann nicht abgeschlossen sein, auch wenn es (noch) keine Output-Condition hat. |
| `can_leave`            | `bool`        | Darf der User von hier **weiter**? = alle Output-Conditions des Objekts erfüllt (z. B. Lernfortschritt „abgeschlossen"). Solange `false`, ist **keine** ausgehende Kante passierbar. |
| `situation`            | `string`      | Einer von `start` / `end` / `branch` / `straight` / `deadend` / `blocked`. Für Icon/Form des Knotens. |
| `successors`           | `int[]`       | **Die Kanten**: obj_ids der direkt erreichbaren Folgeknoten. Zusammenlaufende Pfade = mehrere Kanten auf denselben Knoten. |
| `passable_successors`  | `int[]`       | Teilmenge von `successors`: die **jetzt** passierbaren Kanten – dieses Objekt darf verlassen werden (`can_leave`, und es ist selbst zugänglich) **und** das Zielobjekt darf über genau diese Kante betreten werden. Alles aus `successors`, was hier fehlt, als **gesperrten Pfeil** zeichnen. |
| `input_condition_ids`  | `int[]`       | IDs **aller** Input-Conditions des Objekts (Bedingungen zum Hineinkommen). Nur IDs; Details bei Bedarf separat auflösen. |
| `output_condition_ids` | `int[]`       | IDs **aller** Output-Conditions des Objekts (Bedingungen zum Verlassen). |
| `visit_count`          | `int`         | Wie oft der User das Objekt besucht hat (relevant für „Ehrenrunden"). |
| `last_visited_ts`      | `int\|null`   | Unix-Zeitstempel (Sekunden) des **letzten** Besuchs dieses Objekts, oder `null`, wenn der User noch nie hier war. |
| `is_current`           | `bool`        | Steht der User gerade auf diesem Knoten? |
| `is_on_walked_path`    | `bool`        | Liegt der Knoten auf dem aktuell aktiven Pfad (nicht ein verlassener Ast)? |
| `depth`                | `int`         | Unverbindlicher Tiefen-/Ebenen-Hinweis (Abstand vom Start im BFS). Das echte Layout macht die JS-Lib selbst. |

---

## 4. Direkter Zugriff auf `LSAdaptivePosition` (fortgeschritten)

In den meisten Fällen genügt der `LSMapDataBuilder`. Wer feinere Fragen zur
Position beantworten will (ohne die ganze Map zu bauen), kann
`LSAdaptivePosition` direkt nutzen. Die öffentliche API spricht durchgängig
`obj_id`:

| Methode                             | Liefert |
|-------------------------------------|---------|
| `getStartObjId()` / `getEndObjId()` | obj_id von Start-/Endobjekt (0 wenn keins). |
| `getCurrentObjId()`                 | obj_id, wo der User gerade steht. |
| `getSuccessors($items, $item)`      | Erreichbare Folge-`LSLearnerItem`s (Basis der Kanten). |
| `getSituation($items, $item)`       | `end`/`blocked`/`deadend`/`branch`/`straight`. |
| `hasVisited($obj_id)`               | Wurde das Objekt jemals besucht? |
| `hasCompleted($items, $obj_id)`     | Output-Conditions erfüllt? |
| `getWalkedObjIds()`                 | Aktuell gelaufener Pfad (obj_ids, ältester zuerst). |
| `getEverVisitedObjIds()`            | Alle jemals besuchten obj_ids (dedupliziert). |
| `getVisitCount($obj_id)`            | Anzahl der Besuche eines Objekts. |
| `getLastVisitTs($obj_id)`           | Unix-Zeitstempel des letzten Besuchs eines Objekts (`null`, wenn nie besucht). |
| `getVisitLog()`                     | Vollständiges, append-only Besuchsprotokoll (`obj_id` + `visited_ts`). |

`$items` ist immer die `LSLearnerItem[]`-Liste der LSO
(`ilLSLearnerItemsQueries::getItems()`).

### Regeln, die der Builder daraus ableitet

- **`can_access`** eines Knotens = **alle** eingehenden Vorgänger dürfen
  verlassen werden (`AdaptiveNavigator::canLeave` für jeden Vorgänger). Der
  Startknoten hat keine Vorgänger und ist immer zugänglich.
- **Kantenzustand**: Eine Kante ist nur passierbar (`passable_successors`), wenn der
  Quellknoten zugänglich ist, seine Output-Conditions erfüllt sind (`can_leave`,
  z. B. Lernfortschritt „abgeschlossen") **und** die Condition genau dieser Kante
  zusammen mit den Nicht-Kanten-Input-Conditions des Ziels erfüllt ist
  (`AdaptiveNavigator::canEnterFrom`). Alle übrigen Kanten sind gesperrt.
- **Kanten** (`successors`) ergeben sich aus den adaptiven Bedingungen
  (`LearningProgressInputCondition`), nicht aus der reinen Listenreihenfolge.
- **Ehrenrunden/Zyklen** sind erlaubt: Ein Rücksprung dupliziert keinen Knoten;
  Mehrfachdurchläufe zeigen sich in `visit_count`. Der BFS-Aufbau nutzt ein
  visited-Set (nach `obj_id`) und terminiert daher immer.

---

## 5. Wichtige Konventionen

- **`obj_id` ist die Adresse.** Die interne `ref_id` erscheint nie in der
  öffentlichen API oder im JSON; das Mapping passiert serverseitig zentral
  (1:1 pro LSO).
- Die JSON-Schlüssel in `toArray()` sind der **stabile Vertrag** zur JS-Lib.
  Wer Felder umbenennt/entfernt, bricht das Frontend.
- Die Map ist **schreibfrei**: `LSMap`/`LSMapNode` sind `readonly`-DTOs. Sie
  ändern keinen Zustand; Navigation passiert weiterhin ausschließlich über den
  Player.
