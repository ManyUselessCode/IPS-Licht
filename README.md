# IPS-Licht

Symcon-Modul für Licht mit KNX. Eine Instanz steuert eine Lampengruppe.

## Funktionen

- Tasten schalten die Gruppe um: Ist eine Lampe an, gehen alle aus, sonst alle an.
- MDT-LEDs (Farbwert) und Jung-LEDs (je eine GA für Rot und Grün) zeigen den Zustand: grün = an, rot = aus.
- Nachlauf 0: Der Präsenzmelder schaltet aus, sobald kein Melder mehr Präsenz meldet. Ohne Melder geht das Licht nie automatisch aus.
- Nachlauf größer 0: Präsenz wird ignoriert. Das Licht geht nach dieser Zeit aus, sie beginnt bei jedem Einschalten einer Lampe neu.
- Beim Symcon-Start liest das Modul Status und Melder und schaltet bei Nachlauf 0 aus, wenn Licht an ist und keine Präsenz gemeldet wird.
- Ein Melder schaltet nie ein.

## Variablen

- **Licht:** Schalter für die Gruppe in WebFront und App.
- **Grund:** Text zur letzten Änderung, zum Beispiel "Aus: keine Präsenz". Zum Auswerten im Archiv aufzeichnen.

## Voraussetzung

KNX-DPT-Instanzen: DPT 1 für Lampen und Jung-LEDs, DPT 5 für MDT-LEDs. Schalten und Status sind getrennte Instanzen.
