# Power Metrics – Stromverbrauch für Pelican Panel

Plugin für das [Pelican Panel](https://pelican.dev), das den Stromverbrauch deines Proxmox-/Wings-Hosts übersichtlich darstellt – auf Handy, Tablet und Desktop (responsives Layout, Hell-/Dunkelmodus vom Panel).

## Was wird angezeigt?

Admin-Bereich → **Stromverbrauch**

| Kachel | Inhalt |
| --- | --- |
| Aktuelle Leistung | Watt jetzt, Mini-Verlauf der letzten Stunde, ob gemessen oder geschätzt |
| Verbrauch heute / Monat | kWh, dazu Kosten bzw. Vergleich zum Vormonat |
| Kosten diesen Monat | kWh × Strompreis |
| Prognose Monat | Hochrechnung auf den ganzen Monat |
| Ø Leistung | Durchschnitt im Monat (und heute) |
| Peak / Low | Höchster und niedrigster Wert im Monat inkl. Zeitpunkt |
| Kosten letzter Monat | Abgeschlossener Vormonat |

Diagramme:

* **Leistungsverlauf** mit Durchschnitt, Peak und Low – wählbar: 1 h, 6 h, 24 h, 7 T, 30 T, 90 T, 1 Jahr
* **Verbrauch pro Tag** (kWh) – dieser Monat / 30 / 90 Tage
* **Ø Leistung nach Tageszeit** (zeigt, wann dein Server am meisten zieht)

Alle Werte aktualisieren sich automatisch (Polling alle 30 s).

## Installation

1. Ordner/Zip als Plugin ins Panel laden (`Admin → Plugins → Importieren`) oder nach `plugins/power-metrics` kopieren.
2. Plugin **installieren** und **aktivieren** (legt die Datenbanktabellen an).
3. Sicherstellen, dass der Panel-Scheduler läuft (ist bei Pelican ohnehin nötig):
   `* * * * * php /var/www/pelican/artisan schedule:run >> /dev/null 2>&1`
4. Unter `Admin → Plugins → Power Metrics → Einstellungen` Hosts/Messung einrichten sowie Strompreis und Währung setzen.

## Einrichtung: messen, sonst Steckdose, sonst schätzen

Alles wird an einer Stelle eingerichtet: **Admin → Plugins → Power Metrics → Einstellungen** (oder auf der Stromverbrauch-Seite oben rechts „Einrichten“).

Pro **Host** (z. B. dein Proxmox-Server) gibt es drei Stufen. Jede Minute wird in dieser Reihenfolge versucht – die erste Stufe mit einem Wert gewinnt:

| Stufe | Möglichkeiten | Anzeige |
| --- | --- | --- |
| **1. Direkte Messung** | Push-Skript auf dem Host (`MODE=ipmi` = echte Gesamtleistung, `MODE=rapl` = nur CPU) · Server-BMC über Netzwerk (Redfish: iDRAC, iLO, Supermicro) · RAPL auf dem Panel-Rechner | grün „Direkt gemessen“ |
| **2. Steckdose** | Shelly Gen2/Gen3, Shelly Gen1, Tasmota (nur IP eintragen) · Home Assistant · eigene JSON-URL | grün „Steckdose“ |
| **3. Schätzung** | aus der CPU-Auslastung, die Wings meldet: `Leerlauf + (Volllast − Leerlauf) × Last` | gelb „Geschätzt“ |

Fällt eine Stufe aus, springt die nächste ein; liefert die höhere wieder, wird sofort zurückgeschaltet. Der Grund für einen Ausfall steht im Statusbereich der Seite. In der Monatskachel steht, wie viel Prozent geschätzt waren.

**Ohne jeden Host** legt das Plugin beim ersten Lauf je Wings-Node automatisch einen Host mit Schätzung an – die Messung ergänzt du dann in den Einstellungen.

Hinweise:

* **Pelican in einer Proxmox-VM/einem LXC:** „RAPL auf diesem Rechner“ geht dort meist nicht (VM: nie, LXC: nur privilegiert). Nimm das **Push-Skript auf dem Proxmox-Host**, einen **BMC** oder eine **Steckdose**.
* Die Schätzung sieht nur die CPU-Last der Wings-VM, nicht die anderer VMs auf dem Host. Leerlauf-/Volllast-Watt am besten einmal nachmessen.
* Der BMC wird per HTTPS ohne Zertifikatsprüfung abgefragt (BMCs haben fast immer selbstsignierte Zertifikate) – nur im eigenen Netz nutzen.
* Passwörter/Tokens werden verschlüsselt gespeichert und im Formular nicht wieder angezeigt; leer lassen = unverändert.
* Einen Host in den Einstellungen zu entfernen löscht auch seinen Verlauf.

## Wenn nichts angezeigt wird

Oben auf der Seite zeigt ein Statusbereich, was fehlt:

* **„Die Erfassung läuft nicht“** – der Pelican-Scheduler läuft nicht. In dem Container/der VM, in der das Panel läuft, muss genau **ein** Cron-Eintrag für den Web-Benutzer existieren (nicht zusätzlich für root):
  `* * * * * php /var/www/pelican/artisan schedule:run >> /dev/null 2>&1`
  Prüfen: `crontab -l -u www-data`, `systemctl status cron`, `php artisan power-metrics:collect` (zeigt je Host die verwendete Stufe und Fehler).
  Bis dahin erfasst die Seite selbst, solange sie geöffnet ist.
* **Host „Keine Daten“** – die Fehlermeldung jeder Stufe steht direkt darunter.
* Nach einem Update müssen die Migrationen laufen (Plugin-Update im Panel erledigt das; sonst `php artisan migrate`).

## Push-Skript auf dem Proxmox-Host

In den Einstellungen bei „1. Direkte Messung“ **Push-Skript** wählen, speichern, dann Push-URL und Token kopieren. Auf dem Proxmox-Host:

```bash
curl -fsSL -o /usr/local/bin/pelican-power-push.sh https://raw.githubusercontent.com/christian-hnl/pelican-power-metrics/main/scripts/proxmox-power-push.sh
chmod +x /usr/local/bin/pelican-power-push.sh

cat > /etc/pelican-power-push.conf <<'CONF'
PANEL_URL="https://panel.example.com"
TOKEN="HIER_DAS_TOKEN"
MODE="ipmi"        # ipmi | rapl | command
OFFSET_WATTS=0
CONF
chmod 600 /etc/pelican-power-push.conf

DRY_RUN=1 /usr/local/bin/pelican-power-push.sh   # Test: gibt Watt aus
echo '* * * * * root /usr/local/bin/pelican-power-push.sh' > /etc/cron.d/pelican-power-push
```

* `MODE=ipmi` – Server mit BMC: echte Gesamtleistung (`apt install ipmitool`).
* `MODE=rapl` – nur CPU (Intel/AMD RAPL); den Rest über `OFFSET_WATTS` grob ergänzen.
* `MODE=command` – eigener Befehl, der Watt ausgibt.

Direkter API-Aufruf: `curl -X POST https://panel.example.com/api/power-metrics/push -H "Authorization: Bearer <TOKEN>" -d watts=142.5`

## Wie wird gerechnet?

* Jeder Messwert wird gespeichert; daraus entstehen **Stunden-Aggregate** (Ø, Min, Max, Energie). Monats-/Jahresauswertungen laufen nur über diese kleinen Aggregate und bleiben schnell.
* **kWh** werden per Trapezregel aus den Messwerten integriert. Lücken > 15 Minuten (Ausfall) werden nicht hochgerechnet, sondern als Lücke gezählt.
* Bei mehreren Quellen werden Ø/Min/Max pro Stunde je Quelle addiert. Peak/Low sind dann Stundenwerte der Summe (bei einer Quelle exakt).
* Rohwerte werden 72 h aufbewahrt (Live-Verläufe), Stundenwerte 730 Tage. Anpassbar über `POWER_METRICS_RAW_RETENTION_HOURS` und `POWER_METRICS_RETENTION_DAYS`.
* Tage/Monate richten sich nach der Zeitzone des angemeldeten Benutzers (sonst `APP_TIMEZONE`).

## Hinweise

* Die Seite sehen Admins mit der Berechtigung `power_source` (wird in den Rollen angelegt); Root-Admins immer. Einrichten darf, wer Plugins verwalten darf.
* Das Push-Token ist ein Geheimnis: wer es kennt, kann Werte einspeisen. Bei Verdacht in den Einstellungen „Neues Token erzeugen“.
* Die Push-Route ist auf 120 Anfragen/Minute begrenzt.

## Entwicklung

Lizenz: MIT. Struktur orientiert sich an den offiziellen [Pelican-Plugins](https://github.com/pelican-dev/plugins).
