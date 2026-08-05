# Konzept: Umbau des LearningSequence-Players (linear + adaptiv)

## Ziel

Der Player der LearningSequence soll umgebaut werden, sodass er zwei
Betriebsmodi unterstützt:

- **linear** – der bestehende Modus, der aktuell verwendet wird.
- **adaptiv** – ein neuer Modus, der zusätzlich eingebaut werden muss.

Welcher Modus aktiv ist, wird in den Einstellungen der LearningSequence
hinterlegt (`lso_mod`, siehe `ilLearningSequenceSettings` mit `MODE_LINEAR = 0`
und `MODE_ADAPTIVE = 1`). Der Player muss beide Modi sauber unterstützen; keiner
der beiden darf den jeweils anderen brechen. Es gilt eine **scharfe Trennung**
zwischen linear und adaptiv – die lineare Logik wird nicht "umgebogen", sondern
bleibt unverändert bestehen.

## Grundlagen des adaptiven Modus

Im adaptiven Modus gelten andere Regeln als im linearen:

- Es gibt **keine feste Reihenfolge** der Objekte.
- **Conditions** entscheiden über die Navigation:
  - Eine **Output-Condition** legt fest, ob man ein Objekt **verlassen** darf.
  - Eine **Input-Condition** legt fest, ob man ein Objekt **betreten** darf.
- Es gibt (vorerst) **keine Priorisierung** der Conditions bzw. der möglichen
  Folge-Objekte.
- Ein Objekt entscheidet darüber, **welche Vorgänger-Objekte** es hat.
- Es gibt genau ein **Start-Objekt** und ein **End-Objekt**
  (`start_ref_id` / `end_ref_id`, siehe `lso_item_boundaries`).

### Einstieg und Ende

Beim ersten Aufruf im adaptiven Modus startet der Player **gezielt beim
`start_ref_id`** aus `lso_item_boundaries` – nicht bei `items[0]` wie im
linearen Modus. Analog ist das `end_ref_id` das definierte Ziel des Ablaufs.
Auch wenn der Fokus dieses Konzepts auf dem Start-Objekt liegt, gilt dasselbe
Prinzip für das End-Objekt.

### Verfügbarkeit: scharfe Trennung zu linear

Der lineare Player nutzt heute `Step::AVAILABLE` / `getNextAvailableItem()`.
Im adaptiven Modus wird dieser Verfügbarkeitsbegriff **nicht** verwendet.
Stattdessen wird **bei jedem Request neu geprüft**, was möglich ist und was
nicht – ausschließlich über die Input-/Output-Conditions. Dadurch greifen nicht
zwei konkurrierende Verfügbarkeitsbegriffe gleichzeitig.

### Abgrenzung (nicht Teil dieses Konzepts)

- **Zyklen im Graphen** (z. B. A → B → A) werden hier **vorerst ignoriert**.
  Das wird später vom Dead-End-Schutz abgedeckt – ein eigenes Projekt für
  später.
- **Kombinationskonflikte von Output- und Input-Conditions** (welche Meldung
  greift, wenn beide Seiten blockieren) werden hier **nicht** gelöst. Auch das
  übernimmt später der (umbenannte) Dead-End-Mechanismus, der eher zu einer
  **Assistenz für den Tutor** wird, um Probleme frühzeitig zu erkennen.
- Die **Visualisierung von Pfaden** (die spätere "Map") ist hier nicht relevant.
- Die **Input-LP-Conditions** werden parallel von anderen Kollegen umgebaut.
- **Migration/Kompatibilität** ist unkritisch: Ein LSO wird immer **offline**
  gesetzt, wenn mit den Conditions etwas nicht stimmt. Es muss also kein
  halb-konfigurierter adaptiver Zustand zur Laufzeit abgefangen werden.

Es geht in diesem Konzept ausschließlich darum, **was passiert, wenn man im
Player auf "Vor" und "Zurück" drückt**.

## Verhalten je nach Situation

### 1. Linearer Ablauf (Sonderfall im adaptiven Modus)

Wenn es keine Verzweigungen gibt und man vom Start-Objekt geradlinig zum
End-Objekt gelangt, ist der Ablauf praktisch identisch zum linearen Modus.
Der einzige Unterschied: Es wird zusätzlich geprüft, ob die Conditions erfüllt
sind, wenn man

- ein Objekt **verlassen** will (Output-Condition), und
- in das nächste Objekt **eintreten** will (Input-Condition).

### 2. Pfad-Verzweigungen

An bestimmten Punkten kann es sein, dass man mit **mehr als einem Objekt**
fortfahren kann. In diesem Fall soll eine **gesonderte Auswahlseite**
eingeblendet werden, auf der man wählt, mit welchem Objekt es weitergehen soll.

**Blockierte Optionen werden ausgeblendet:** Sind einzelne Folge-Objekte durch
nicht erfüllte Conditions gesperrt, tauchen sie auf der Auswahlseite gar nicht
erst auf. Es werden nur die tatsächlich erlaubten Optionen angeboten.

### 3. Pfad-Ende (ohne End-Objekt)

Manchmal endet ein Pfad, ohne zum End-Objekt zu führen – das ist kein Fehler.
In diesem Fall hat ein Objekt **kein Folge-Objekt**.

Dann soll eine **eigene Seite** mit folgender Meldung erscheinen:

> "Hey, hier ist der Pfad zu Ende. Schaue in die Map, um mit einem anderen
> Objekt weiterzumachen."

(Um die Map kümmern wir uns später.)

### 4. Bedingung nicht erfüllt

Manchmal kommt man bei einem Objekt nicht weiter.

**Beispiel:** Ein ILIAS-Test-Objekt hat die Output-Condition "LP resolved"
(Lernfortschritt abgeschlossen / bestanden). Die Person ist jedoch
durchgefallen. Um das nächste Objekt zu starten, muss der Test aber
bestanden ("LP resolved") sein.

In diesem Fall darf man **nicht weiterklicken** und es erscheint die Meldung:

> "Um das nächste Objekt zu beginnen, muss eine Bedingung erfüllt sein."

Diese Meldung ist ein **Platzhalter** und wird später verfeinert.

### 5. "Zurück"

"Zurück" führt im adaptiven Modus **immer auf das Objekt, das man zuvor
bearbeitet hat** – also entlang des tatsächlich begangenen Pfades, nicht über
die Graph-Vorgänger. Das ist für Lernende am wenigsten überraschend und
eindeutig, auch wenn ein Objekt mehrere mögliche Vorgänger hat. Voraussetzung
dafür ist ein gespeicherter Verlauf (Pfad-Historie), dessen Umsetzung in der
Ausarbeitung unten behandelt wird.

## Zusammenfassung der Anforderungen

- Der Player unterscheidet linear und adaptiv anhand der Einstellung `lso_mod`.
- Im adaptiven Modus bestimmen Input-/Output-Conditions die Navigation; bei
  jedem Request wird neu geprüft, was geht und was nicht.
- Einstieg erfolgt am `start_ref_id`, Ziel ist das `end_ref_id`.
- Fünf Situationen müssen im adaptiven Modus abgedeckt sein:
  1. gradliniger Ablauf (mit Condition-Prüfung),
  2. Verzweigung → Auswahlseite (blockierte Optionen ausgeblendet),
  3. Pfad-Ende ohne Folge-Objekt → Hinweisseite,
  4. Bedingung nicht erfüllt → Blockade mit Platzhalter-Meldung,
  5. "Zurück" → zurück auf das zuvor bearbeitete Objekt (Pfad-Historie).
- Der lineare Modus muss unverändert weiter funktionieren (scharfe Trennung).

## Umsetzung (Überblick)

### Vorhandene Bausteine

Ein großer Teil der Grundinfrastruktur ist bereits vorhanden:

- **Betriebsmodus:** `ilLearningSequenceSettings` mit `MODE_LINEAR`/
  `MODE_ADAPTIVE` und dem Feld `lso_mod`.
- **Conditions:** `classes/Content/Condition/` mit `AbstractCondition`
  (`check(): bool`, persistiert über `lso_conditions`), Input-Conditions
  (`LogicGatter`, `Points`, `SimpleChoice`, `Subset`), Output-Conditions
  (`Always`, `LearningProgress`, `Points`) und einer `ConditionFactory`.
  `SimpleChoiceInputCondition` speichert bereits ein `target_ref_id`
  (`lso_c_simple_choice`) – faktisch schon die **Kanten** des Graphen.
- **Start-/End-Objekt:** `LSOAdaptiveBoundaries` über `lso_item_boundaries`.
- **Adaptive Content-Verwaltung:** `classes/Content/Adaptive/` für die
  Autoren-Seite.

Der eigentliche Umbau konzentriert sich damit auf die Navigationslogik im
Player (`classes/Player/class.ilLSPlayer.php`), die heute rein linear und
index-basiert ist (`getNextItem()` rechnet `position + (+1|-1)`).

### Navigator-Strategy-Pattern

Die Navigation wird hinter eine Abstraktion gelegt, z. B. eine Schnittstelle
`LSNavigator`:

- `getSuccessors(LSLearnerItem $current): LSLearnerItem[]`
- `getPredecessors(LSLearnerItem $current): LSLearnerItem[]`
- `canLeave(LSLearnerItem $current): bool` (Output-Condition)
- `canEnter(LSLearnerItem $target): bool` (Input-Condition)

Mit zwei Implementierungen:

- `LinearNavigator` – kapselt exakt die heutige index-basierte Logik
  (Verhalten bleibt garantiert unverändert).
- `AdaptiveNavigator` – nutzt Boundaries + Conditions, um Vorgänger/Nachfolger
  und Erlaubnisse zu bestimmen; wertet bei jedem Request neu aus.

Die Auswahl des Navigators erfolgt einmalig anhand von `lso_mod`, am besten in
der `ilLSViewFactory` bzw. beim Zusammenbau des Players. Der `ilLSPlayer` fragt
dann nur noch den Navigator und kennt die Fallunterscheidung nicht selbst.

### Abbildung der Fälle

| Fall | Bedingung | Verhalten im Player |
|------|-----------|---------------------|
| Gradlinig | genau 1 erlaubter Nachfolger | direkt weiter (wie linear) |
| Verzweigung | > 1 erlaubter Nachfolger | Auswahlseite (blockierte Optionen ausgeblendet) |
| Pfad-Ende | 0 Nachfolger und nicht End-Objekt | Hinweisseite "Pfad zu Ende" |
| Blockiert | Output-Condition nicht erfüllt | Weiter-Button sperren + Meldung |
| Zurück | vorheriges Objekt aus der Historie | zurück auf zuletzt bearbeitetes Objekt |

Die Auswahlseite sowie die Hinweis-/Blockade-Seiten werden als eigene,
leichtgewichtige Views bzw. Zustände umgesetzt. Die Auswahlseite kann das
bestehende `LSO_CMD_GOTO`-Kommando wiederverwenden und pro Option ein
Ziel-`ref_id` anbieten.

### Persistenz des begangenen Pfades (in der DB)

Der begangene Pfad wird **dauerhaft in der Datenbank** gespeichert. Diese
Entscheidung ist getroffen: Eine zusätzliche Tabelle ist unproblematisch und
sie ist die einzige Variante, die "Zurück", Suspend/Resume und die spätere Map
sauber und eindeutig unterstützt (eine reine Laufzeit-/Session-Lösung würde die
Historie bei Logout verlieren und wäre bei Verzweigungen nicht rekonstruierbar).

Der Pfad ist eine **geordnete Liste besuchter `ref_id`** pro Teilnehmer und LSO
– im Grunde ein Stack:

```
[ start_ref_id, ref_a, ref_c, ... , current_ref_id ]
```

- "Vor" hängt das gewählte Folge-Objekt hinten an.
- "Zurück" entfernt das letzte Element (Pop) und macht das vorletzte zum
  aktuellen Objekt.

**Datenmodell:** eine neue Tabelle, z. B. `lso_item_path` mit den Spalten
`usr_id`, `lso_obj_id`, `position` (int) und `ref_id` (int). Das aktuelle Objekt
ergibt sich aus dem Element mit der höchsten `position`; der heute vorhandene
`getCurrentItemRefId()` bleibt konsistent nutzbar. Aufräum-/Reset-Logik ist
vorzusehen (Neustart des LSO, Löschen von Objekten).

---

# Arbeitspakete

Die Umsetzung wird in kleine, **einzeln testbare** Arbeitspakete (AP) zerlegt.
Jedes AP endet mit einem sichtbaren bzw. überprüfbaren Ergebnis und einer
**manuellen Testanleitung** (kein Unit-Test). Der lineare Modus muss nach jedem
AP unverändert funktionieren.

Vorbereitung für die Tests (einmalig): Lege eine LearningSequence mit mehreren
Objekten an. Für adaptive Tests stelle den Modus in den LSO-Einstellungen auf
**adaptiv**, setze Start- und End-Objekt und pflege die Conditions.

## AP 1 – Modus erkennen und Navigator-Abstraktion (LinearNavigator)

**Ziel:** Der Player erkennt seinen Modus (`lso_mod`) und die heutige
index-basierte Navigation wird 1:1 hinter eine Schnittstelle `LSNavigator`
gelegt (`LinearNavigator`). Verhalten bleibt garantiert unverändert.

**Umsetzung:** Modus (`ilLearningSequenceSettings::getMode()`) im `ilLSPlayer`
verfügbar machen (Zusammenbau in `ilLSLocalDI`); zur Sichtbarkeit vorübergehend
eine Log-Ausgabe (`ilLog`) mit dem erkannten Modus. Interface `LSNavigator`
(`getSuccessors`, `getPredecessors`, `canLeave`, `canEnter`) einführen und die
bisherige `getNextItem()`-Logik in einem `LinearNavigator` kapseln. Der Player
nutzt für beide Modi vorerst den `LinearNavigator`.

**Manueller Test:**
1. Öffne eine **lineare** LSO → im ILIAS-Log erscheint "Modus: linear"; sie
   spielt sich ("Vor"/"Zurück"/"Beenden") exakt wie vorher.
2. Öffne eine **adaptive** LSO → im Log erscheint "Modus: adaptiv"; sie verhält
   sich noch wie linear (adaptive Logik folgt später) – nichts ist kaputt.

## AP 2 – Pfad-Historie: DB-Tabelle und Repository

**Ziel:** Die Tabelle `lso_item_path` existiert und eine Klasse kapselt
Push/Pop/Read darauf. Noch nicht in die Navigation eingebunden.

**Umsetzung:** DB-Step (`dbupdate`) anlegen, der `lso_item_path`
(`usr_id`, `lso_obj_id`, `position`, `ref_id`) erstellt. Repository (z. B.
`LSOItemPath`) mit `push(usr_id, lso_obj_id, ref_id)`, `pop(...)`,
`getPath(...)`, `getCurrent(...)`, `reset(...)`.

**Manueller Test:**
1. DB-Update in der ILIAS-Administration ausführen; in der DB prüfen
   (`SHOW TABLES LIKE 'lso_item_path'` / `DESCRIBE lso_item_path`): Tabelle mit
   den vier Spalten ist vorhanden. Beide Modi laufen weiter normal.
2. Über einen temporären Aufruf zwei `push` auslösen → zwei Zeilen mit position
   0 und 1; ein `pop` → nur noch eine Zeile.
3. Werte in der DB entsprechen den erwarteten ref_ids/positions.

## AP 3 – AdaptiveNavigator: Start-Objekt und geradliniger Ablauf

**Ziel:** Im adaptiven Modus startet der Player am `start_ref_id` und geht bei
genau einem erlaubten Nachfolger geradlinig weiter; jeder Schritt wird in die
Pfad-Historie geschrieben.

**Umsetzung:** `AdaptiveNavigator` nutzt `LSOAdaptiveBoundaries` (Start/Ende)
und die Conditions, um den (einzigen) Nachfolger zu bestimmen. Modusauswahl
(linear/adaptiv) beim Zusammenbau des Players. "Vor" schreibt via AP-2-Repo.

**Manueller Test:**
1. Adaptive LSO ohne Verzweigung öffnen → es erscheint das **Start-Objekt**
   (nicht das erste Listenobjekt).
2. Mehrfach "Vor" klicken → man läuft geradlinig zum End-Objekt.
3. In `lso_item_path` wächst der Pfad Schritt für Schritt korrekt mit.
4. Lineare LSO ist unverändert.

## AP 4 – "Zurück" über die Pfad-Historie

**Ziel:** "Zurück" führt im adaptiven Modus auf das **zuvor bearbeitete**
Objekt (Pop aus der Historie).

**Umsetzung:** "Zurück" im adaptiven Modus ruft `pop()` und rendert das dann
oberste Objekt.

**Manueller Test:**
1. In der adaptiven LSO einige Objekte vorwärts gehen.
2. "Zurück" klicken → es erscheint jeweils exakt das vorher bearbeitete Objekt.
3. In `lso_item_path` wird pro "Zurück" das letzte Element entfernt.
4. Am Start-Objekt ist "Zurück" deaktiviert.

## AP 5 – Verhaltensfälle: Blockade, Verzweigung, Pfad-Ende

**Ziel:** Die drei restlichen Situationen des adaptiven Modus sind abgedeckt:
nicht erfüllte Bedingung (Blockade), Verzweigung mit Auswahlseite und Pfad-Ende
ohne End-Objekt.

**Umsetzung:**
- **Blockade:** Player fragt `canLeave()`; bei `false` "Vor" deaktivieren und
  Meldung "Um das nächste Objekt zu beginnen, muss eine Bedingung erfüllt sein."
  anzeigen.
- **Verzweigung:** bei `count(getSuccessors) > 1` eine Auswahl-View rendern, die
  pro erlaubter Option `LSO_CMD_GOTO` mit Ziel-`ref_id` anbietet; Optionen mit
  `canEnter() === false` werden nicht gelistet.
- **Pfad-Ende:** bei `count(getSuccessors) === 0` und `ref_id !== end_ref_id`
  eine Hinweis-View "Hey, hier ist der Pfad zu Ende. Schaue in die Map …".

**Manueller Test:**
1. Objekt mit nicht erfüllter Output-Condition öffnen → "Vor" gesperrt, Meldung
   sichtbar; Bedingung erfüllen → "Vor" wieder möglich.
2. Verzweigung mit zwei erlaubten Folge-Objekten → Auswahlseite mit beiden
   Optionen; eine wählen → Sprung dorthin, Pfad wächst; eine Option blockieren
   (Input-Condition) → sie erscheint nicht mehr.
3. Objekt ohne Folge-Objekt (kein End-Objekt) → "Pfad zu Ende"-Hinweisseite; am
   echten End-Objekt stattdessen regulärer Abschluss (Beenden).

## AP 6 – Suspend/Resume, Aufräumen und Bereinigung

**Ziel:** Der adaptive Zustand inkl. Historie überlebt Suspend/Resume; Reset-
und Aufräum-Logik greift, temporäre Provisorien sind entfernt.

**Umsetzung:** Beim Einstieg im adaptiven Modus das aktuelle Objekt aus
`lso_item_path` (höchste `position`) laden statt aus dem Listenindex. Reset der
Historie beim Neustart eines LSO bzw. beim Löschen von Objekten sicherstellen;
temporäre Log-Ausgaben aus AP 1 entfernen.

**Manueller Test:**
1. Adaptive LSO mehrere Schritte spielen, "Beenden/Verlassen", erneut öffnen →
   man landet exakt am zuletzt bearbeiteten Objekt; "Zurück" führt weiter
   korrekt entlang des begangenen Pfades.
2. LSO neu starten (falls vorgesehen) → `lso_item_path` beginnt wieder am
   Start-Objekt; ein Objekt aus dem LSO löschen → keine verwaisten Einträge oder
   Fehler beim Weiterspielen.
3. Das ILIAS-Log ist frei von den temporären Modus-Ausgaben.

---

# Ausführliche manuelle Tests (Abnahme des Gesamtumbaus)

Dieser Abschnitt beschreibt die vollständige, manuelle Abnahme des umgebauten
Players. Er ergänzt die kurzen Tests je Arbeitspaket und dient dem **großen
Testdurchlauf am Ende**. Die Tests sind so geschrieben, dass jeder Schritt ein
**sichtbares oder in der DB prüfbares Ergebnis** hat und ohne Programmier- oder
Unit-Test-Kenntnisse nachvollziehbar ist.

## Vorbereitung der Testumgebung

### T0.1 – Benötigte Testinhalte anlegen

Lege für die Tests die folgenden Objekte an (Namen frei wählbar, hier zur
besseren Zuordnung vorgeschlagen):

1. **LSO-LINEAR** – eine LearningSequence im Modus **linear** mit mindestens
   drei Inhaltsobjekten (z. B. drei Inhaltsseiten „L1", „L2", „L3").
2. **LSO-ADAPTIV-GERADE** – eine LearningSequence im Modus **adaptiv** ohne
   Verzweigung: fünf Objekte „A", „B", „C", „D", „E"; Start-Objekt = „A",
   End-Objekt = „E"; Conditions so, dass jeweils genau ein Nachfolger existiert
   (A→B→C→D→E).
3. **LSO-ADAPTIV-VERZWEIGT** – eine LearningSequence im Modus **adaptiv** mit
   Verzweigung: Start „S", danach Auswahl zwischen „P1" und „P2", die beide auf
   ein gemeinsames End-Objekt „Z" führen.
4. **LSO-ADAPTIV-BLOCKADE** – eine LearningSequence im Modus **adaptiv** mit
   einer **Output-Condition** an einem Objekt „X" (z. B. „X muss bestanden
   sein"), gefolgt von „Y". Start = „X", End = „Y".
5. **LSO-ADAPTIV-SACKGASSE** – eine LearningSequence im Modus **adaptiv**, in der
   ein Objekt „T" **keinen** Nachfolger hat und **nicht** das End-Objekt ist
   (bewusster Pfad ohne End-Objekt).

Halte außerdem einen **Testnutzer** bereit, der Mitglied/Teilnehmer der LSOs
ist (nicht als Administrator spielen, sondern als Lernender).

### T0.2 – DB-Zugang bereitstellen

Für die Prüfungen in der Datenbank benötigst du Zugriff auf die ILIAS-DB
(z. B. via `mysql`/`mariadb`-Client oder phpMyAdmin). Die Kernabfrage für die
Pfad-Historie lautet immer:

```sql
SELECT usr_id, lso_obj_id, position, ref_id
FROM lso_item_path
WHERE usr_id = <TESTNUTZER_USR_ID> AND lso_obj_id = <LSO_OBJ_ID>
ORDER BY position;
```

Die `lso_obj_id` ist die **Objekt-ID** (nicht ref_id) der jeweiligen LSO; die
`usr_id` findest du in der Benutzerverwaltung. Notiere dir beide Werte je LSO
vor Beginn.

### T0.3 – Ausgangszustand herstellen

Vor jedem Testblock sicherstellen, dass der Testnutzer die betreffende LSO noch
nicht (oder frisch zurückgesetzt) bearbeitet hat, damit der Einstieg wirklich
am Start-Objekt erfolgt.

## Block A – Linearer Modus bleibt unverändert (Regression)

Ziel: Nachweisen, dass der Umbau den linearen Modus **nicht** verändert hat.

### T-A1 – Durchspielen vorwärts
1. Öffne **LSO-LINEAR** als Testnutzer.
2. Erwartung: Es erscheint das **erste** Listenobjekt „L1" (Index-basiert, wie
   bisher – nicht ein Start-Objekt).
3. Klicke „Vor" bis zum letzten Objekt „L3".
4. Erwartung: Reihenfolge L1 → L2 → L3; „Vor" ist am letzten Objekt zu
   „Beenden" geworden.

### T-A2 – Rückwärts navigieren
1. Gehe von „L3" mehrfach „Zurück".
2. Erwartung: L3 → L2 → L1; am ersten Objekt ist „Zurück" deaktiviert.

### T-A3 – Verfügbarkeit / gesperrte Objekte
1. Falls in LSO-LINEAR ein Objekt eine Voraussetzung hat: versuche, es zu
   überspringen.
2. Erwartung: Das Verhalten (`Step::AVAILABLE`) ist **identisch zu vor dem
   Umbau** – gesperrte Objekte bleiben gesperrt.

### T-A4 – Keine Pfad-Historie im linearen Modus
1. Führe T-A1 komplett durch.
2. Prüfe in der DB (Abfrage aus T0.2 mit der `lso_obj_id` von LSO-LINEAR).
3. Erwartung: **Keine** Zeilen – der lineare Modus schreibt nicht in
   `lso_item_path`.

### T-A5 – Kein Modus-Log mehr
1. Öffne LSO-LINEAR und schaue in das ILIAS-Log.
2. Erwartung: **Keine** temporäre „Modus"-Ausgabe (Provisorium aus AP 1 wurde
   in AP 6 entfernt).

## Block B – Adaptiver Einstieg und geradliniger Ablauf

### T-B1 – Einstieg am Start-Objekt
1. Öffne **LSO-ADAPTIV-GERADE** als Testnutzer (erstmalig).
2. Erwartung: Es erscheint das **Start-Objekt „A"** – nicht zwingend das erste
   Listenobjekt.
3. Prüfe die DB: genau **eine** Zeile, `position = 0`, `ref_id` = ref_id von
   „A".

### T-B2 – Geradlinig vorwärts
1. Klicke „Vor" → „B", weiter → „C", „D", „E".
2. Erwartung: Bei jedem Schritt erscheint genau das nächste Objekt.
3. Prüfe die DB nach jedem Schritt: Der Pfad wächst um genau eine Zeile
   (positions 0,1,2,3,4; ref_ids A,B,C,D,E in dieser Reihenfolge).

### T-B3 – End-Objekt schließt ab
1. Am Objekt „E" (End-Objekt) angekommen.
2. Erwartung: Statt „Vor" erscheint **„Beenden"**; ein Klick beendet die LSO
   regulär.

### T-B4 – „Vor" nicht doppelt zählt
1. Bleibe auf einem Objekt und lade die Seite neu, ohne „Vor" zu klicken.
2. Erwartung: Der Pfad in der DB wächst **nicht** durch bloßes Neuladen (nur
   echte „Vor"/Auswahl-Aktionen schreiben).

## Block C – „Zurück" über die Pfad-Historie

### T-C1 – Schrittweise zurück
1. In LSO-ADAPTIV-GERADE bis „D" vorspielen (Pfad A,B,C,D).
2. Klicke „Zurück".
3. Erwartung: Es erscheint „C"; die DB verliert die oberste Zeile (D entfernt,
   verbleibend A,B,C).
4. Wiederhole „Zurück" bis „A".

### T-C2 – „Zurück" am Start deaktiviert
1. Auf „A" (Pfadlänge 1).
2. Erwartung: „Zurück" ist **deaktiviert**; die DB behält die eine Zeile für
   „A".

### T-C3 – Zurück, dann wieder vor
1. Von „C" einmal „Zurück" (→ „B"), dann „Vor".
2. Erwartung: Es geht wieder auf den regulären Nachfolger; der Pfad in der DB
   spiegelt die tatsächlich begangene Route wider (kein „Loch" in den
   positions, lückenlose Nummerierung 0..n).

## Block D – Verzweigungen

### T-D1 – Auswahlseite erscheint
1. Öffne **LSO-ADAPTIV-VERZWEIGT**, spiele bis zur Verzweigung nach „S".
2. Erwartung: Statt eines automatischen „Vor" erscheint eine **Auswahlseite**
   („Womit möchten Sie fortfahren?") mit je einem Button für „P1" und „P2".
3. „Vor" ist an dieser Stelle **nicht** als einfacher Schritt aktiv.

### T-D2 – Auswahl wird gegangen und protokolliert
1. Wähle „P1".
2. Erwartung: „P1" erscheint; die DB bekommt eine neue Zeile mit ref_id von
   „P1".
3. Spiele weiter bis End-Objekt „Z"; „Beenden" erscheint.

### T-D3 – Blockierte Option verschwindet
1. Setze an „P2" eine **Input-Condition**, die (noch) nicht erfüllt ist.
2. Öffne die Verzweigung erneut (ggf. mit frischem Zustand/anderem Testnutzer).
3. Erwartung: Auf der Auswahlseite erscheint **nur** „P1"; „P2" wird **nicht**
   angeboten.
4. Erfülle die Bedingung für „P2" → beim erneuten Aufruf der Verzweigung
   erscheinen wieder **beide** Optionen.

### T-D4 – Zurück aus einem gewählten Zweig
1. Nach Wahl „P1" ein bis zwei Schritte weiter, dann mehrfach „Zurück".
2. Erwartung: Man landet zurück auf „S" (dem Punkt vor der Auswahl); die DB
   entfernt die Zeilen des Zweigs korrekt in umgekehrter Reihenfolge.

## Block E – Blockade (Bedingung nicht erfüllt)

### T-E1 – „Vor" gesperrt bei nicht erfüllter Output-Condition
1. Öffne **LSO-ADAPTIV-BLOCKADE**, gehe auf „X" (Output-Condition nicht
   erfüllt).
2. Erwartung: „Vor" ist **deaktiviert** und es erscheint die Meldung „Um das
   nächste Objekt zu beginnen, muss eine Bedingung erfüllt sein."

### T-E2 – Nach Erfüllen der Bedingung geht es weiter
1. Erfülle die Output-Condition an „X" (z. B. Objekt bestehen).
2. Kehre zu „X" zurück.
3. Erwartung: Die Meldung ist weg, „Vor" ist wieder aktiv und führt auf „Y".

### T-E3 – Blockade verändert die Historie nicht
1. Während „Vor" gesperrt ist, prüfe die DB.
2. Erwartung: Es wird **keine** neue Zeile geschrieben, solange die Blockade
   besteht.

## Block F – Pfad-Ende ohne End-Objekt

### T-F1 – Sackgassen-Hinweis
1. Öffne **LSO-ADAPTIV-SACKGASSE**, navigiere bis „T" (kein Nachfolger, kein
   End-Objekt).
2. Erwartung: Es erscheint die Hinweisseite „Hey, hier ist der Pfad zu Ende.
   Schaue in die Map …"; „Vor" ist **nicht** aktiv; „Beenden" erscheint hier
   **nicht** (es ist kein End-Objekt).

### T-F2 – Zurück aus der Sackgasse
1. Klicke auf „T" „Zurück".
2. Erwartung: Man kommt entlang der Historie zurück; die DB entfernt die
   oberste Zeile.

## Block G – Suspend / Resume (Wiedereinstieg)

### T-G1 – Wiedereinstieg am zuletzt bearbeiteten Objekt
1. Spiele LSO-ADAPTIV-GERADE bis „C", verlasse die LSO (Seite verlassen /
   „Beenden" ohne Abschluss / Abmelden).
2. Öffne die LSO erneut als **derselbe** Testnutzer.
3. Erwartung: Der Einstieg erfolgt bei **„C"** (dem zuletzt bearbeiteten
   Objekt), nicht am Start-Objekt.
4. „Zurück" führt weiter korrekt entlang A,B,C.

### T-G2 – Zweiter Nutzer hat eigenen Pfad
1. Spiele die gleiche LSO mit einem **zweiten** Testnutzer bis „B".
2. Erwartung: In der DB existieren zwei getrennte Pfade (unterschiedliche
   `usr_id`); der Wiedereinstieg jedes Nutzers ist individuell korrekt.

## Block H – Aufräumen und Bereinigung

### T-H1 – Teilnehmer entfernen setzt Pfad zurück
1. Sorge dafür, dass der Testnutzer in LSO-ADAPTIV-GERADE einen Pfad hat
   (Zeilen in der DB vorhanden).
2. Entferne den Nutzer als **Teilnehmer** der LSO.
3. Prüfe die DB.
4. Erwartung: Die Pfad-Zeilen dieses Nutzers für diese LSO sind **entfernt**;
   keine Fehlermeldung.

### T-H2 – Objekt löschen entfernt verwaiste Einträge
1. Baue in einer adaptiven LSO einen Pfad auf, der ein Objekt „M" enthält.
2. Lösche „M" aus der LearningSequence.
3. Prüfe die DB und spiele weiter.
4. Erwartung: Es verbleiben **keine** verwaisten Zeilen mit der ref_id von „M";
   das Weiterspielen erzeugt keine Fehler.

### T-H3 – Fehlerhafte Conditions setzen LSO offline
1. Konfiguriere eine adaptive LSO absichtlich mit widersprüchlichen/kaputten
   Conditions.
2. Erwartung: Die LSO geht (wie vorgesehen) offline / meldet die fehlerhafte
   Konfiguration; der Player stürzt nicht unkontrolliert ab.

## Block I – Robustheit und Sonderfälle

### T-I1 – Leere LSO
1. Öffne eine adaptive LSO ohne Inhaltsobjekte.
2. Erwartung: Sinnvolle Leer-/Hinweisanzeige, kein Fehler.

### T-I2 – Nur ein Objekt (Start = Ende)
1. Adaptive LSO mit genau einem Objekt, das gleichzeitig Start- und End-Objekt
   ist.
2. Erwartung: Es erscheint dieses Objekt mit „Beenden"; „Zurück" deaktiviert.

### T-I3 – Wechsel Verzweigung ↔ Zurück ↔ andere Wahl
1. In LSO-ADAPTIV-VERZWEIGT: „P1" wählen, „Zurück" zu „S", dann „P2" wählen.
2. Erwartung: Der Pfad in der DB endet konsistent auf „P2"; keine Reste von
   „P1" oberhalb der aktuellen Position.

### T-I4 – Direkte Regressionsprobe linear ↔ adaptiv
1. Wechsle mehrfach zwischen dem Spielen einer linearen und einer adaptiven LSO.
2. Erwartung: Beide Modi verhalten sich unabhängig und korrekt; der lineare
   Modus schreibt weiterhin **nichts** in `lso_item_path`.

## Abnahme-Checkliste (Kurzfassung)

- [ ] Linearer Modus unverändert (Block A), keine DB-Historie, kein Log.
- [ ] Adaptiver Einstieg am Start-Objekt, geradliniger Ablauf, Pfad wächst
      korrekt (Block B).
- [ ] „Zurück" per Pop, am Start deaktiviert (Block C).
- [ ] Verzweigung mit Auswahlseite, blockierte Optionen ausgeblendet (Block D).
- [ ] Blockade sperrt „Vor" mit Meldung, danach frei (Block E).
- [ ] Pfad-Ende zeigt Hinweis, kein „Beenden" (Block F).
- [ ] Suspend/Resume landet am zuletzt bearbeiteten Objekt, pro Nutzer getrennt
      (Block G).
- [ ] Aufräumen bei Teilnehmer-/Objektlöschung ohne verwaiste Einträge
      (Block H).
- [ ] Sonderfälle robust (Block I).
