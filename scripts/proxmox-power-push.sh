#!/usr/bin/env bash
#
# Sendet die aktuelle Leistungsaufnahme (Watt) des Proxmox-Hosts an das Pelican-Plugin "Power Metrics".
# Wird per Cron/systemd-Timer jede Minute ausgeführt.
#
# Konfiguration: /etc/pelican-power-push.conf  (oder Umgebungsvariablen)
#
#   PANEL_URL="https://panel.example.com"   # URL deines Pelican Panels
#   TOKEN="..."                              # Token aus Admin -> Plugins -> Power Metrics -> Einstellungen
#   MODE="rapl"                              # rapl | ipmi | command
#   OFFSET_WATTS=0                           # fester Zuschlag, z. B. für Mainboard/Lüfter/Netzteil (nur bei MODE=rapl sinnvoll)
#   COMMAND=""                               # nur bei MODE=command: Befehl, der eine Zahl in Watt ausgibt
#   DRY_RUN=0                                # 1 = nur Wert ausgeben, nichts senden
#
# MODE=rapl     Intel/AMD RAPL (CPU-Package-Leistung aus /sys/class/powercap). Misst NUR die CPU(s),
#               darum OFFSET_WATTS für den Rest setzen oder besser eine Steckdose mit Messung nutzen.
# MODE=ipmi     `ipmitool dcmi power reading` (Server mit BMC/iDRAC/iLO/IPMI): Gesamtleistung des Systems.
# MODE=command  Eigener Befehl, z. B. `curl -s http://shelly/rpc/Switch.GetStatus?id=0 | jq .apower`

set -euo pipefail

CONF="${CONF:-/etc/pelican-power-push.conf}"
# shellcheck disable=SC1090
[[ -f "$CONF" ]] && source "$CONF"

PANEL_URL="${PANEL_URL:-}"
TOKEN="${TOKEN:-}"
MODE="${MODE:-rapl}"
OFFSET_WATTS="${OFFSET_WATTS:-0}"
COMMAND="${COMMAND:-}"
DRY_RUN="${DRY_RUN:-0}"
POWERCAP_DIR="${POWERCAP_DIR:-/sys/class/powercap}"
RAPL_SAMPLE_SECONDS="${RAPL_SAMPLE_SECONDS:-2}"

die() { echo "pelican-power-push: $*" >&2; exit 1; }

[[ "$DRY_RUN" == "1" || ( -n "$PANEL_URL" && -n "$TOKEN" ) ]] || die "PANEL_URL und TOKEN müssen gesetzt sein (siehe $CONF)."

# Summe der Energiezähler aller Top-Level-RAPL-Domains (intel-rapl:0, intel-rapl:1, ...) in µJ
rapl_total_uj() {
  local total=0 dir v
  for dir in "$POWERCAP_DIR"/intel-rapl:[0-9]*; do
    [[ -r "$dir/energy_uj" ]] || continue
    [[ "$(basename "$dir")" =~ ^intel-rapl:[0-9]+$ ]] || continue # Subzonen (intel-rapl:0:0) auslassen, sind in der Package-Zone enthalten
    v="$(<"$dir/energy_uj")"
    total=$((total + v))
  done
  echo "$total"
}

rapl_max_uj() {
  local dir max=0 v
  for dir in "$POWERCAP_DIR"/intel-rapl:[0-9]*; do
    [[ "$(basename "$dir")" =~ ^intel-rapl:[0-9]+$ ]] || continue
    [[ -r "$dir/max_energy_range_uj" ]] || continue
    v="$(<"$dir/max_energy_range_uj")"
    max=$((max + v))
  done
  echo "$max"
}

read_watts() {
  case "$MODE" in
    rapl)
      compgen -G "$POWERCAP_DIR/intel-rapl:[0-9]*" > /dev/null || die "Kein RAPL gefunden ($POWERCAP_DIR). Anderen MODE verwenden."
      local e1 e2 max delta
      e1="$(rapl_total_uj)"
      sleep "$RAPL_SAMPLE_SECONDS"
      e2="$(rapl_total_uj)"
      delta=$((e2 - e1))
      if ((delta < 0)); then # Zähler-Überlauf
        max="$(rapl_max_uj)"
        delta=$((delta + max))
      fi
      awk -v d="$delta" -v s="$RAPL_SAMPLE_SECONDS" -v o="$OFFSET_WATTS" 'BEGIN { printf "%.1f", d / 1000000 / s + o }'
      ;;
    ipmi)
      command -v ipmitool > /dev/null || die "ipmitool nicht installiert (apt install ipmitool)."
      ipmitool dcmi power reading | awk -F: '/Instantaneous power reading/ { gsub(/[^0-9.]/, "", $2); print $2; exit }'
      ;;
    command)
      [[ -n "$COMMAND" ]] || die "COMMAND ist leer."
      bash -c "$COMMAND"
      ;;
    *)
      die "Unbekannter MODE: $MODE"
      ;;
  esac
}

WATTS="$(read_watts | tr -d '[:space:]')"
[[ "$WATTS" =~ ^[0-9]+(\.[0-9]+)?$ ]] || die "Ungültiger Messwert: '$WATTS'"

if [[ "$DRY_RUN" == "1" ]]; then
  echo "$WATTS"
  exit 0
fi

curl --silent --show-error --fail --max-time 10 \
  -X POST "${PANEL_URL%/}/api/power-metrics/push" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  --data-urlencode "watts=$WATTS" > /dev/null
