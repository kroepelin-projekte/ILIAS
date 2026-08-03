# Konzept: Refactoring der ContentGUI (Lernsequenz)

## Ausgangslage
Die aktuelle `ilObjLearningSequenceContentGUI` verwaltet zwei grundlegend unterschiedliche Betriebsmodi:
1. **Sequenzieller Modus (Sequential Mode):** Lineare Abfolge, Verwaltung über die `Ordering Table`.
2. **Adaptiver Modus (Adaptive Mode):** Dynamische Pfade, Verwaltung über die `Presentation Table` mit Filtern und Bedingungen.

Mit über 860 Zeilen Code ist die Klasse schwer wartbar geworden. Viele Methoden sind spezifisch für einen Modus (z. B. `reorder` für sequenziell, `renderAdaptiveTable` für adaptiv), werden aber in einer gemeinsamen Klasse vorgehalten.

## Zielsetzung
- **Trennung der Verantwortlichkeiten (Separation of Concerns):** Jeder Modus erhält seine eigene Logik-Klasse.
- **Schlanker Controller:** `ilObjLearningSequenceContentGUI` dient nur noch als Einstiegspunkt und delegiert die Arbeit an spezialisierte Klassen.
- **Bessere Testbarkeit und Wartbarkeit:** Modusspezifische Fehler können isoliert behoben werden.

## Die neue Architektur

### 1. Der Controller: `ilObjLearningSequenceContentGUI`
Diese Klasse bleibt bestehen, wird aber radikal gekürzt. Sie behält nur:
- Den Konstruktor und die Dependency Injection.
- Die `executeCommand`-Methode zur Verteilung der Anfragen.
- Gemeinsame Hilfsmethoden (z. B. Zugriff auf LSO-Einstellungen oder das Abrufen der LSO-Items).

### 2. Die neue Ordnerstruktur
Wir führen zwei neue Verzeichnisse unter `classes/Content/` ein:

- `Sequential/`: Beinhaltet alle Klassen für den linearen Modus.
- `Adaptive/`: Beinhaltet alle Klassen für den adaptiven Modus.

### 3. Die Delegaten (Logic Holder)
Innerhalb dieser Ordner erstellen wir die zentralen Logik-Halter:
- `ILIAS\LearningSequence\Content\Sequential\LSOSequentialContent`
- `ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveContent`

Diese Klassen übernehmen die gesamte modusspezifische Steuerung. Sie sind keine `ilCtrl`-Knoten (keine GUI-Endung im Namen), sondern werden vom Haupt-Controller instanziiert.

**Wichtig:** Der Haupt-Controller (`ilObjLearningSequenceContentGUI`) übergibt sich selbst (`$this`) an den Konstruktor der Logik-Klassen. Dadurch haben die Logik-Klassen Zugriff auf alle gemeinsamen Ressourcen und können bei Bedarf Änderungen an der GUI-Instanz vornehmen.

### 4. Verschiebung der Tabellen und Hilfsklassen
Die bestehenden Klassen werden in die neuen Ordner verschoben und erhalten ein einheitliches `LSO`-Präfix:

**Ordner `Sequential/`:**
- `class.ilObjLearningSequenceContentSequentialTable.php` -> `LSOSequentialTable.php`

**Ordner `Adaptive/`:**
- `class.ilObjLearningSequenceContentAdaptiveTable.php` -> `LSOAdaptiveTable.php`
- `class.ilObjLearningSequenceContentBoundaries.php` -> `LSOAdaptiveBoundaries.php`
- `class.ilObjLearningSequenceContentFilter.php` -> `LSOAdaptiveFilter.php`

## Zuordnung der Methoden (Optimiert)

### Gemeinsam (Controller)
- `executeCommand` (Dispatcher)
- `manageContent` (Delegation basierend auf Modus)
- `confirmDelete` / `delete` (Zentralisiert)

### Modus Sequenziell -> `Sequential\LSOSequentialContent`
- `manageContent()` (früher `manageSequentialContent`)
- `reorder()`
- `setOnline()` / `setOffline()`
- `setConditionAlways()` / `setConditionLP()`
- `getTableData()` (früher `buildSequentialTableData`)

### Modus Adaptiv -> `Adaptive\LSOAdaptiveContent`
- `manageContent()` (früher `manageAdaptiveContent`)
- `save()` (früher `saveTable`)
- `getTableData()` (früher `buildAdaptiveData`)
- `getConditionOptions()` (Zusammengefasst)

## Implementierungsvorschlag

Die `executeCommand` im Controller:

```php
public function executeCommand(): void
{
    $next_class = $this->ctrl->getNextClass($this);
    $cmd = $this->ctrl->getCmd('manageContent');

    $mode = $this->settings->getMode();

    if ($mode === ilLearningSequenceSettings::MODE_ADAPTIVE) {
        $delegate = new \ILIAS\LearningSequence\Content\Adaptive\LSOAdaptiveContent($this);
    } else {
        $delegate = new \ILIAS\LearningSequence\Content\Sequential\LSOSequentialContent($this);
    }

    if (method_exists($delegate, $cmd)) {
        $delegate->$cmd();
    }
}
```

## Vorteile dieser Lösung
- **Klarheit:** Wenn ich am sequenziellen Modus arbeite, öffne ich die `SequentialContentGUI`.
- **Sicherheit:** Änderungen am adaptiven Filter können nicht versehentlich die Sortierlogik der Ordering Table zerstören.
- **Framework-Konformität:** Wir folgen dem Muster der "Composition over Inheritance".

## Nächste Schritte
1. Erstellung der Dateistrukturen für die neuen Klassen.
2. Schrittweises Verschieben der Methoden (Refactoring).
3. Anpassung der `ilCtrl`-Targets, da diese weiterhin auf die `ilObjLearningSequenceContentGUI` zeigen sollten, um die URL-Struktur stabil zu halten.
