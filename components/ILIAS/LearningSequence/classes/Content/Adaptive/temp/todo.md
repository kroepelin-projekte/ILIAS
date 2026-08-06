wir haben uns mal angeschaut, wie wir daten für die Map zusammen bauen. soweit nett. Wir haben auch über JS Libs gesprochen und aktuell überlegen wir, es selber zu machen. Baue einen Prototypen hier.
Wichtig: schreibe css, js und html direkt in eine PHP datei hier in den Ordner. Baue es mit Kitchen Sink elemente und setze es in die /home/kc/Projekte/ILIAS/Instanzen/trunk84/ilias_12/components/ILIAS/LearningSequence/classes/Content/Adaptive/LSOAdaptiveContent.php unterhalb der Tabelle.

Nun wie stelle ich es mir vor

- es ist quasi ein Wasserfallmodell mit verzweigungen und ggf Pfad zusammenführunen.
- Also Boxen, die mit Pfeilen verbunden werden
- Das JS soll dafür sorgen, das die boxen schön sortiert dargestellt werden und
kein durcheinander herscht
- nutze nur plan js. kein JQuery
- Nutze Kitchen Sink, wenn es logisch erscheint
- Überlege dir fake daten um es darzustellen
- Es soll skalibar sein. hat es nur 3 Objekte, passt.. muss aber auch für 100 Objekte passen
- Oben start objekt unten end Objekt

## Boxen
- Eine Box pro Objekt mit Icon, Titel und Beschreibung + button für Link zum Player Objekt
- Umrandung: Wenn der SPieler da ist, irgendwie nett umranden
- Abgeschlossene Objekte mit grünen rahmen
- Boxen sollten schon recht klein sein um am besten der Bereich zoombar

## Pfeile
- Unterscheidung zwischen: Du kannst fortfahren und du kannst nicht fortfahren
- Der Pfad soll erkennbar sein


stelle Fragen, wnen du welche hast.
