<?php
/*
 * Corsair PSU Center - energy collector daemon.
 *
 * Samples the PSU's estimated INPUT power (wall draw) from sysfs and integrates
 * it into watt-hours, persistently, so the dashboard can show usage + cost per
 * day / week / month / year / lifetime.
 *
 * Deliberately independent of the fan-curve daemon (fand.sh): that one only
 * runs in curve mode, but energy must accrue in every fan mode. Reads sysfs
 * only - no USB, no python, no contention with liquidctl.
 *
 * Persistence respects the Unraid USB flash: the running counter lives in this
 * process's memory and is checkpointed to flash only every CKPT seconds (and on
 * a clean stop), not every sample.
 */

require_once __DIR__ . '/lib.php';

$SAMPLE = 10;    // seconds between samples
$CKPT   = 300;   // seconds between flash checkpoints

// ---- single instance (confirm the pid is really us) ----
if (file_exists(CPC_EPID)) {
    $old = trim(@file_get_contents(CPC_EPID));
    if ($old !== '' && file_exists("/proc/$old")) {
        $cl = @file_get_contents("/proc/$old/cmdline");
        if ($cl !== false && strpos($cl, 'energyd.php') !== false) {
            fwrite(STDERR, "energyd already running (pid $old)\n");
            exit(0);
        }
    }
}
@mkdir(CPC_EDIR, 0755, true);
@file_put_contents(CPC_EPID, getmypid());

$state = energy_load_state();
if (empty($state['since'])) $state['since'] = time();

function elive() {   // RAM copy every sample - free (tmpfs), keeps the UI live
    global $state;
    @file_put_contents(CPC_LIVE, json_encode($state));
}
function eflash() {  // persist to USB flash - infrequent, spares the stick.
    // Atomic: write a temp file then rename over the real one, so a crash or
    // power loss mid-write can never corrupt the saved total (worst case the
    // previous good checkpoint survives untouched).
    global $state;
    $tmp = CPC_STATE . '.tmp';
    if (@file_put_contents($tmp, json_encode($state)) !== false) @rename($tmp, CPC_STATE);
}

// graceful stop: flush + drop pidfile
$RUN = true;
pcntl_async_signals(true);
$stop = function () use (&$RUN) { $RUN = false; };
pcntl_signal(SIGTERM, $stop);
pcntl_signal(SIGINT,  $stop);
register_shutdown_function(function () {
    eflash();
    if (file_exists(CPC_EPID) && trim(@file_get_contents(CPC_EPID)) == getmypid()) @unlink(CPC_EPID);
});

elive(); eflash();       // write both immediately so the UI has something
$lastCkpt = time();

while ($RUN) {
    $now = time();
    $H = hwmon_path();
    if ($H) {
        $psu = detect_psu($H);
        list($pIn, $eff) = input_power($H, $psu);
        if ($pIn !== null && $pIn > 0) {
            $dt = $state['last_ts'] ? ($now - $state['last_ts']) : 0;
            // Only integrate over a plausible sample gap. A large gap means the
            // daemon (or box) was down - don't invent energy for that time.
            if ($dt > 0 && $dt <= 2 * $SAMPLE + 5) {
                $wh    = $pIn * $dt / 3600.0;
                $today = date('Y-m-d', $now);
                if ($state['today'] !== $today) {          // day rollover
                    if ($state['today'] !== '' && $state['today_wh'] > 0)
                        @file_put_contents(CPC_DAILY,
                            $state['today'] . ',' . round($state['today_wh'], 3) . "\n",
                            FILE_APPEND);
                    $state['today']    = $today;
                    $state['today_wh'] = 0.0;
                }
                $state['total_wh'] += $wh;
                $state['today_wh'] += $wh;
            }
        }
    }
    $state['last_ts'] = $now;

    elive();                                                   // live RAM copy every sample
    if ($now - $lastCkpt >= $CKPT) { eflash(); $lastCkpt = $now; }   // flash checkpoint
    sleep($SAMPLE);
}

eflash();   // clean exit persist (shutdown function also flushes as a backstop)
