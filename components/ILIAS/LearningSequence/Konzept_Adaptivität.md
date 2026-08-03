# Konzept: Adaptivität und Modernisierung der Lernsequenz (LSO)

Dieses Dokument beschreibt das Konzept für die technische Umsetzung der zwei Betriebsmodi (Linear und Adaptiv) sowie die Modernisierung der Benutzeroberfläche innerhalb der Lernsequenz.

## 1. Architektur: Eine oder zwei ContentGUIs?

**Frage:** Macht es Sinn, zwei separate ContentGUIs für den linearen und den adaptiven Modus zu haben?

**Empfehlung:** **Nein, eine zentrale `ilObjLearningSequenceContentGUI` ist vorzuziehen.**

### Begründung:
*   **Gemeinsame Basis:** Beide Modi teilen sich viele Infrastruktur-Elemente (Berechtigungsprüfungen, Tabs-Navigation, Toolbar-Aktionen, Anbindung an das LSO-Objekt).
*   **Wartbarkeit:** Bei zwei Klassen müssten Fehlerkorrekturen oder allgemeine UI-Anpassungen an zwei Stellen gepflegt werden.
*   **Strategie:** Die `ContentGUI` sollte als **Controller** agieren. Sie entscheidet anhand des eingestellten Modus (`$settings->getLSOMod()`), welche Sub-Komponente (Tabelle) gerendert wird.

### Umsetzungsvorschlag:
Die `ContentGUI` behält die Logik, delegiert das Rendering der Hauptansicht aber an spezialisierte Methoden oder View-Objekte:
```php
protected function showContents() {
    if ($this->settings->getLSOMod() === ilLearningSequenceSettings::MODE_ADAPTIVE) {
        return $this->renderAdaptiveTable();
    }
    return $this->renderSequentialTable();
}
```
Die "alten" Actions für den linearen Modus können in der `ContentGUI` verbleiben, sollten aber nur dann aktiv geschaltet/angezeigt werden, wenn der entsprechende Modus aktiv ist.

---

## 2. Modernisierung: Legacy TableGUI zu Kitchen Sink Ordering Table

**Problemstellung:** Die `ilObjLearningSequenceContentTableGUI` (Legacy) soll durch die `Ordering Table` (Kitchen Sink) ersetzt werden. Die Legacy-Tabelle enthält Select-Felder ("Benutzer darf fortfahren"), die die `Ordering Table` nativ nicht unterstützt.

### Die Herausforderung mit Selects in Tabellen
Das ILIAS UI-Framework (Kitchen Sink) verfolgt einen "sauberen" Ansatz. Tabellen dienen primär der Anzeige und einfachen Aktionen. Komplexe Eingabefelder innerhalb von Tabellenzeilen sind im aktuellen Standard der `Ordering Table` nicht vorgesehen, um die Konsistenz und Barrierefreiheit zu wahren.

### Lösungskonzept: "Action-Driven Configuration"
Um die Kitchen Sink nicht mit manuellem PHP/JS zu verbiegen, empfehle ich einen der folgenden zwei Wege:

#### Weg A: Das "Modal-Edit" Muster (Empfohlen für saubere UI)
Statt das Select direkt in der Tabellenspalte anzuzeigen, wird der Status als Text (Badge oder Label) dargestellt.
1.  **Anzeige:** In der Spalte "Fortfahren nach" steht z.B. "Immer" oder "Lernfortschritt".
2.  **Aktion:** Jede Zeile erhält eine Action "Bedingung bearbeiten".
3.  **Interaktion:** Beim Klick öffnet sich ein **Modal**, das den `LSOObjectPicker` (für den adaptiven Modus) oder ein einfaches Formular mit dem Select (für den linearen Modus) enthält.
4.  **Vorteil:** Volle Kompatibilität mit der `Ordering Table`, Drag & Drop funktioniert perfekt, und die UI bleibt übersichtlich.

#### Weg B: Multi-Action Bearbeitung (Bulk-Edit)
Wenn Benutzer viele Objekte gleichzeitig ändern müssen:
1.  Objekte per Checkbox in der Tabelle auswählen.
2.  In der Toolbar eine Action "Bedingung für Auswahl ändern".
3.  Ein zentrales Formular setzt die Werte für alle markierten Objekte.

### Integration der Ordering Table Vorteile
Durch den Wechsel auf die `Ordering Table` gewinnen wir:
*   **Drag & Drop:** Native Unterstützung für die Sortierung der Sequenz.
*   **Massendaten-Aktionen:** Einfaches Löschen oder Verschieben mehrerer Objekte.
*   **Performance:** Schnellere Darstellung und moderne Filter-Integration.

---

## 3. Zusammenfassung & Fazit

1.  **Zentralisierung:** Behalten Sie die `class.ilObjLearningSequenceContentGUI.php` als Steuerzentrale.
2.  **Entkopplung:** Lagern Sie die Tabellen-Logik in moderne Komponenten aus.
3.  **Saubere UI:** Ersetzen Sie die Select-Felder innerhalb der Tabelle durch eine "Edit-via-Modal" Logik. Dies erfüllt die Anforderung, die Kitchen Sink nicht zu "hacken" und bietet gleichzeitig eine modernere User Experience.
4.  **Adaptivität:** Der bereits entwickelte `LSOObjectPicker` lässt sich perfekt in dieses Modal-Konzept integrieren, wenn ein Objekt im adaptiven Modus konfiguriert werden soll.

Dieses Konzept erlaubt es, die Legacy-Altlasten (`TableGUI`) vollständig zu entfernen, ohne die Funktionalität zu opfern.
