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
4. Unter `Admin → Plugins → Power Metrics → Einstellungen` Strompreis und Währung setzen.

## Messen – und schätzen, wenn nicht gemessen werden kann

Das Plugin zeigt immer an, **woher** ein Wert kommt:

* **Gemessen** (grün): echter Wert eines Messgeräts – smarte Steckdose, IPMI/BMC usw.
* **Geschätzt** (gelb): Fallback aus der CPU-Auslastung, die Wings an das Panel meldet:
  `Watt = Leerlauf-Watt + (Volllast-Watt − Leerlauf-Watt) × CPU-Auslastung`

Ablauf je Quelle, jede Minute:

1. Messende Quelle abfragen bzw. auf das Push-Skript warten.
2. Klappt das nicht (Gerät nicht erreichbar, Push seit 5 Minuten still), wird – sofern ein Node hinterlegt und „Bei Messausfall schätzen“ aktiv ist – geschätzt.
3. Kommt wieder ein Messwert, wird sofort wieder gemessen.

**Ohne jede Quelle** legt das Plugin beim ersten Lauf für jeden Wings-Node automatisch eine Schätz-Quelle an. So siehst du sofort Werte und kannst später eine echte Messung ergänzen. In der Monatskachel steht, wie viel Prozent geschätzt waren.

Für eine brauchbare Schätzung bei der Quelle **Leerlauf- und Volllast-Watt** eintragen (Standard: 35 W / 120 W). Am besten einmal mit einem Steckdosen-Messgerät nachmessen.

> **Pelican/Wings in einer Proxmox-VM:** Die Schätzung sieht nur die CPU-Last der Wings-VM, nicht die anderer VMs/Container auf dem Host. Für den echten Verbrauch des ganzen Proxmox-Hosts führt kein Weg an einer Messung vorbei (Steckdose oder IPMI).

## Wenn nichts angezeigt wird

Oben auf der Seite zeigt ein Statusbereich, was fehlt:

* **„Die Erfassung läuft nicht“** – der Pelican-Scheduler läuft nicht. In dem Container/der VM, in der das Panel läuft, muss dieser Cron-Eintrag existieren:
  `* * * * * php /var/www/pelican/artisan schedule:run >> /dev/null 2>&1`
  Ohne ihn wird weder gemessen noch geschätzt. Test: `php artisan power-metrics:collect` von Hand ausführen.
* **Quelle „Keine Daten“** – die Fehlermeldung steht direkt darunter (z. B. Steckdose nicht erreichbar, Wings antwortet nicht).
* Nach einem Update sicherstellen, dass die Migrationen gelaufen sind (Plugin-Update im Panel erledigt das; sonst `php artisan migrate`).

## Datenquellen einrichten

Für **echte Werte** brauchst du eine Messquelle – Wings und Proxmox liefern selbst keine Stromwerte. Unter **Admin → Stromquellen** kannst du beliebig viele anlegen; mehrere Quellen werden zur Gesamtleistung addiert.

### Variante A – Smarte Steckdose / Shelly / Tasmota / Home Assistant (HTTP/JSON, empfohlen)

Genaueste Methode, da das ganze Gerät inkl. Netzteil gemessen wird. Typ **HTTP/JSON**, das Panel fragt jede Minute ab:

| Gerät | URL | JSON-Pfad |
| --- | --- | --- |
| Shelly Gen2/3 (Plug S, Pro) | `http://IP/rpc/Switch.GetStatus?id=0` | `apower` |
| Shelly Gen1 | `http://IP/status` | `meters.0.power` |
| Tasmota | `http://IP/cm?cmnd=Status%208` | `StatusSNS.ENERGY.Power` |
| Home Assistant | `http://HA:8123/api/states/sensor.dein_sensor` | `state` (Authentifizierung: `Bearer <Long-Lived-Token>`) |

Der Faktor ist standardmäßig 1 (Watt). Liefert die Quelle kW, `1000` eintragen.

### Variante B – Push-Skript auf dem Proxmox-Host

Typ **Push** anlegen, Token kopieren, dann auf dem Proxmox-Host:

```bash
curl -fsSL -o /usr/local/bin/pelican-power-push.sh https://raw.githubusercontent.com/christian-hnl/pelican-power-metrics/main/scripts/proxmox-power-push.sh
chmod +x /usr/local/bin/pelican-power-push.sh

cat > /etc/pelican-power-push.conf <<'EOF'
PANEL_URL="https://panel.example.com"
TOKEN="HIER_DAS_TOKEN"
MODE="ipmi"        # ipmi | rapl | command
OFFSET_WATTS=0
EOF
chmod 600 /etc/pelican-power-push.conf

echo '* * * * * root /usr/local/bin/pelican-power-push.sh' > /etc/cron.d/pelican-power-push
```

* `MODE=ipmi` – Server mit BMC (iDRAC, iLO, IPMI): echte Gesamtleistung (`apt install ipmitool`).
* `MODE=rapl` – misst nur die CPU(s) über Intel/AMD RAPL. Für Mainboard, RAM, Platten, Lüfter, Netzteilverluste `OFFSET_WATTS` schätzen. Nur eine Näherung, wird aber als „gemessen“ angezeigt – für echte Werte `ipmi` oder eine Steckdose nehmen.
* `MODE=command` – beliebiger Befehl, der Watt ausgibt.

Direkter API-Aufruf (z. B. aus eigenen Skripten):

```bash
curl -X POST https://panel.example.com/api/power-metrics/push \
  -H "Authorization: Bearer <TOKEN>" -d watts=142.5
```

## Wie wird gerechnet?

* Jeder Messwert wird gespeichert; daraus entstehen **Stunden-Aggregate** (Ø, Min, Max, Energie). Monats-/Jahresauswertungen laufen nur über diese kleinen Aggregate und bleiben schnell.
* **kWh** werden per Trapezregel aus den Messwerten integriert. Lücken > 15 Minuten (Ausfall) werden nicht hochgerechnet, sondern als Lücke gezählt.
* Bei mehreren Quellen werden Ø/Min/Max pro Stunde je Quelle addiert. Peak/Low sind dann Stundenwerte der Summe (bei einer Quelle exakt).
* Rohwerte werden 72 h aufbewahrt (Live-Verläufe), Stundenwerte 730 Tage. Anpassbar über `POWER_METRICS_RAW_RETENTION_HOURS` und `POWER_METRICS_RETENTION_DAYS`.
* Tage/Monate richten sich nach der Zeitzone des angemeldeten Benutzers (sonst `APP_TIMEZONE`).

## Hinweise

* Zugriff haben Admins mit der Berechtigung `power_source` (wird in den Rollen angelegt); Root-Admins immer.
* Das Push-Token ist ein Geheimnis: wer es kennt, kann Werte einspeisen. Bei Verdacht in der Stromquellen-Tabelle „Neues Token erzeugen“.
* Die Push-Route ist auf 120 Anfragen/Minute begrenzt.

## Entwicklung

Lizenz: MIT. Struktur orientiert sich an den offiziellen [Pelican-Plugins](https://github.com/pelican-dev/plugins).
