# Power Metrics – Stromverbrauch für Pelican Panel

Plugin für das [Pelican Panel](https://pelican.dev), das den Stromverbrauch deines Proxmox-/Wings-Hosts übersichtlich darstellt – auf Handy, Tablet und Desktop (responsives Layout, Hell-/Dunkelmodus vom Panel).

## Was wird angezeigt?

Admin-Bereich → **Stromverbrauch**

| Kachel | Inhalt |
| --- | --- |
| Aktuelle Leistung | Watt jetzt, Mini-Verlauf der letzten Stunde, Anzahl Quellen online |
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

## Datenquellen einrichten

Weder Wings noch Proxmox liefern von sich aus Stromwerte. Du brauchst eine Messquelle. Unter **Admin → Stromquellen** kannst du beliebig viele anlegen; mehrere Quellen werden zur Gesamtleistung addiert.

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
* `MODE=rapl` – misst nur die CPU(s) über Intel/AMD RAPL. Für Mainboard, RAM, Platten, Lüfter, Netzteilverluste `OFFSET_WATTS` schätzen. Nur eine Näherung!
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
* Tage/Monate richten sich nach der Zeitzone des Panels (`APP_TIMEZONE`).

## Hinweise

* Zugriff haben Admins mit der Berechtigung `power_source` (wird in den Rollen angelegt); Root-Admins immer.
* Das Push-Token ist ein Geheimnis: wer es kennt, kann Werte einspeisen. Bei Verdacht in der Stromquellen-Tabelle „Neues Token erzeugen“.
* Die Push-Route ist auf 120 Anfragen/Minute begrenzt.

## Entwicklung

Lizenz: MIT. Struktur orientiert sich an den offiziellen [Pelican-Plugins](https://github.com/pelican-dev/plugins).
