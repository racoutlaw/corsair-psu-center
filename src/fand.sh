#!/bin/bash
#
# Corsair PSU Center - fan curve daemon
#
# These PSUs cannot store a fan curve in firmware (liquidctl's
# set_speed_profile is NotSupportedByDevice) - they only accept a single fixed
# duty. iCUE on Windows works around this the same way: poll temperature and
# rewrite the fixed duty. This does that on Unraid.
#
# The PSU is identified at runtime from sysfs, so nothing here is tied to a
# specific model.
#
# Reads:  /boot/config/plugins/corsairpsucenter/settings.cfg
# Writes: PSU fan duty via liquidctl (USB HID), only when the target changes.

CFG=/boot/config/plugins/corsairpsucenter/settings.cfg
PIDFILE=/var/run/corsairpsucenter-fand.pid
INTERVAL=10
LOG=/var/log/corsairpsucenter.log
MIN_DUTY=30      # liquidctl clamps below this anyway
PYBIN=/usr/local/emhttp/plugins/corsairpsucenter/py/bin/python3; [ -x "$PYBIN" ] || PYBIN=python3
MAX_LOG_BYTES=262144

# Single instance. Identify the previous copy precisely via the pidfile and
# verify /proc says it really is this script.
#
# Do NOT use `pgrep -f fand.sh` here: that matches any process whose command
# line merely *mentions* the script - the shell that launched us, an admin's
# ssh command, an editor - and killing those is real collateral damage.
if [ -f "$PIDFILE" ]; then
    OLD=$(cat "$PIDFILE" 2>/dev/null)
    if [ -n "$OLD" ] && [ "$OLD" != "$$" ] && [ -d "/proc/$OLD" ] \
       && tr '\0' ' ' < "/proc/$OLD/cmdline" 2>/dev/null | grep -q 'fand\.sh'; then
        kill "$OLD" 2>/dev/null
        for _ in 1 2 3 4 5 6 7 8 9 10; do
            [ -d "/proc/$OLD" ] || break
            sleep 0.2
        done
    fi
fi

# Only ever remove the pidfile if it still names US. A replaced daemon's trap
# used to fire *after* the incoming daemon had already written its own pid,
# wiping it - which left a healthy daemon running with no pidfile, so the UI
# reported "curve service NOT running".
release_pidfile() {
    [ "$(cat "$PIDFILE" 2>/dev/null)" = "$$" ] && rm -f "$PIDFILE"
}

echo $$ > "$PIDFILE"
trap 'release_pidfile; exit 0' TERM INT

log() {
    # keep the log from growing without bound
    if [ -f "$LOG" ] && [ "$(stat -c %s "$LOG" 2>/dev/null || echo 0)" -gt "$MAX_LOG_BYTES" ]; then
        tail -c 65536 "$LOG" > "$LOG.tmp" 2>/dev/null && mv "$LOG.tmp" "$LOG"
    fi
    echo "$(date '+%F %T') $*" >> "$LOG"
}

hwmon_path() {
    for h in /sys/class/hwmon/hwmon*; do
        [ "$(cat "$h/name" 2>/dev/null)" = "corsairpsu" ] && { echo "$h"; return; }
    done
}

# Pull vendor/product out of e.g. HID_ID=0003:00001B1C:00001C07 so liquidctl
# targets this exact device instead of a hardcoded model name.
device_selector() {
    local H=$1 hid vid pid
    hid=$(grep -o 'HID_ID=[0-9A-Fa-f]*:[0-9A-Fa-f]*:[0-9A-Fa-f]*' "$H/device/uevent" 2>/dev/null)
    [ -z "$hid" ] && return
    vid=$(echo "$hid" | cut -d: -f2)
    pid=$(echo "$hid" | cut -d: -f3)
    printf -- "--vendor 0x%s --product 0x%s" \
        "$(printf '%04x' $((16#$vid)))" "$(printf '%04x' $((16#$pid)))"
}

# Linear interpolation across the curve points; clamps outside the end points.
duty_for_temp() {
    local temp=$1 curve=$2
    echo "$temp $curve" | awk '{
        t = $1
        n = 0
        for (i = 2; i <= NF; i++) {
            split($i, p, ",")
            tp[n] = p[1] + 0
            dp[n] = p[2] + 0
            n++
        }
        if (n == 0) { print 50; exit }
        if (t <= tp[0])   { print dp[0]; exit }
        if (t >= tp[n-1]) { print dp[n-1]; exit }
        for (i = 0; i < n-1; i++) {
            if (t >= tp[i] && t <= tp[i+1]) {
                span = tp[i+1] - tp[i]
                if (span == 0) { print dp[i]; exit }
                frac = (t - tp[i]) / span
                printf "%d\n", dp[i] + frac * (dp[i+1] - dp[i])
                exit
            }
        }
        print dp[n-1]
    }'
}

H=$(hwmon_path)
if [ -z "$H" ]; then
    log "ERROR: corsairpsu hwmon not found, exiting"
    rm -f "$PIDFILE"
    exit 1
fi

SEL=$(device_selector "$H")
log "fan curve daemon started (pid $$) device[${SEL:-auto}]"
LAST_DUTY=-1

while true; do
    MODE=$(grep -oP '^mode="\K[^"]+' "$CFG" 2>/dev/null)
    CURVE=$(grep -oP '^curve="\K[^"]+' "$CFG" 2>/dev/null)

    if [ "$MODE" != "curve" ]; then
        log "mode is '$MODE', curve daemon exiting"
        release_pidfile
        exit 0
    fi

    TEMP_RAW=$(cat "$H/temp1_input" 2>/dev/null)
    [ -z "$TEMP_RAW" ] && { sleep "$INTERVAL"; continue; }
    TEMP=$(( TEMP_RAW / 1000 ))
    DUTY=$(duty_for_temp "$TEMP" "$CURVE")

    [ "$DUTY" -lt "$MIN_DUTY" ] && DUTY=$MIN_DUTY
    [ "$DUTY" -gt 100 ] && DUTY=100

    # Only write when it actually changes - avoids hammering the USB endpoint
    # and colliding with anything else polling it.
    if [ "$DUTY" -ne "$LAST_DUTY" ]; then
        RC=1
        for attempt in 1 2 3 4 5; do
            OUT=$("$PYBIN" -m liquidctl $SEL set fan speed "$DUTY" 2>&1)
            RC=$?
            [ $RC -eq 0 ] && break
            echo "$OUT" | grep -qi "possible conflict" || break
            sleep 0.5
        done
        if [ $RC -eq 0 ]; then
            log "temp ${TEMP}C -> duty ${DUTY}%"
            LAST_DUTY=$DUTY
        else
            log "WARN: failed to set duty ${DUTY}%: $OUT"
        fi
    fi

    sleep "$INTERVAL"
done
