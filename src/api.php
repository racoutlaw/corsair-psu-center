<?php
/*
 * Corsair PSU Center - backend API
 *
 * Telemetry comes from the kernel corsair-psu hwmon driver (sysfs) - instant,
 * no USB contention, no python startup cost.
 *
 * Control (fan duty + OCP rail mode) has to go over USB HID, because the
 * kernel driver exposes pwm1/pwm1_enable read-only. liquidctl is used for
 * those two writes only.
 *
 * The PSU model is detected at runtime from the HID product id in sysfs, so
 * this works on any corsair-psu supported unit - nothing is hardcoded to one
 * model.
 *
 * Shared PSU table + sysfs helpers + input-power/energy math live in lib.php,
 * which the energy collector daemon (energyd.php) also uses.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/lib.php';   // CFG_DIR/CFG_FILE, PSU_MODELS, hwmon_path(), detect_psu(), rd(), input_power(), energy_*()

define('PID_FILE', '/var/run/corsairpsucenter-fand.pid');
define('DAEMON',   '/usr/local/emhttp/plugins/corsairpsucenter/fand.sh');
define('ENERGYD',  '/usr/local/emhttp/plugins/corsairpsucenter/energyd.php');

function read_config() {
    $def = ['mode' => 'auto', 'fixed' => 40, 'curve' => '20,30 40,35 55,55 70,80 85,100',
            'ocp' => 'unknown', 'rate' => 0, 'currency' => '$', 'mains' => 'auto'];
    if (!file_exists(CFG_FILE)) return $def;
    $c = @parse_ini_file(CFG_FILE);
    return is_array($c) ? array_merge($def, $c) : $def;
}

function write_config($c) {
    if (!is_dir(CFG_DIR)) @mkdir(CFG_DIR, 0755, true);
    $out = '';
    foreach ($c as $k => $v) $out .= "$k=\"$v\"\n";
    file_put_contents(CFG_FILE, $out);
}

/*
 * Target the exact detected device by vendor/product rather than a model
 * name substring, so this is correct on any supported unit.
 * Retries on "possible conflict", which happens if something else (e.g. the
 * old corsairpsu plugin's corsairmi) polls the same HID endpoint.
 */
function liquidctl($args, $psu, $maxAttempts = 5) {
    $sel = '';
    if ($psu && $psu['vendor'] !== null && $psu['product'] !== null) {
        $sel = sprintf('--vendor 0x%04x --product 0x%04x', $psu['vendor'], $psu['product']);
    }
    $py = is_executable('/usr/local/emhttp/plugins/corsairpsucenter/py/bin/python3')
        ? '/usr/local/emhttp/plugins/corsairpsucenter/py/bin/python3' : 'python3';
    $base = "$py -m liquidctl $sel";
    $text = '';
    for ($i = 1; $i <= $maxAttempts; $i++) {
        $out = [];
        exec("$base $args 2>&1", $out, $rc);
        $text = implode("\n", $out);
        if ($rc === 0) return ['ok' => true, 'output' => $text, 'attempts' => $i];
        if (stripos($text, 'possible conflict') === false) {
            return ['ok' => false, 'output' => $text, 'attempts' => $i];
        }
        usleep((300 + random_int(0, 400)) * 1000);
    }
    return ['ok' => false, 'output' => $text, 'attempts' => $maxAttempts];
}

function daemon_running() {
    if (!file_exists(PID_FILE)) return false;
    $pid = trim(@file_get_contents(PID_FILE));
    return $pid !== '' && file_exists("/proc/$pid");
}

function daemon_stop() {
    if (file_exists(PID_FILE)) {
        $pid = trim(@file_get_contents(PID_FILE));
        if ($pid !== '') @exec("kill $pid 2>/dev/null");
        @unlink(PID_FILE);
    }
}

function daemon_start() {
    daemon_stop();
    // setsid detaches into its own session/process group. Without it php-fpm
    // tears the child down when the request finishes (nohup alone is not
    // enough - the daemon was dying seconds after every start).
    @exec('setsid ' . DAEMON . ' >/dev/null 2>&1 < /dev/null &');
    usleep(400000);
}

/*
 * The energy collector runs independently of the fan daemon (it must accrue in
 * every fan mode). It self-guards against duplicates, so calling this when it
 * is already up is a no-op.
 */
function energyd_start() {
    if (energyd_running()) return;
    @exec('setsid /usr/bin/php ' . ENERGYD . ' >/dev/null 2>&1 < /dev/null &');
    usleep(300000);
}

function energyd_stop() {
    if (!file_exists(CPC_EPID)) return;
    $pid = trim(@file_get_contents(CPC_EPID));
    if ($pid !== '') { @exec("kill $pid 2>/dev/null"); usleep(400000); }
}

function get_status() {
    $H = hwmon_path();
    if (!$H) return ['connected' => false];

    $psu = detect_psu($H);

    $vinRaw = rd("$H/in0_input", 1000);
    $vin    = effective_vin($vinRaw);   // honours the manual 115/230 override
    $v12   = rd("$H/in1_input", 1000);
    $v5    = rd("$H/in2_input", 1000);
    $v33   = rd("$H/in3_input", 1000);
    $c12   = rd("$H/curr2_input", 1000);
    $c5    = rd("$H/curr3_input", 1000);
    $c33   = rd("$H/curr4_input", 1000);
    $pTot  = rd("$H/power1_input", 1000000);
    $p12   = rd("$H/power2_input", 1000000);
    $p5    = rd("$H/power3_input", 1000000);
    $p33   = rd("$H/power4_input", 1000000);
    $tVrm  = rd("$H/temp1_input", 1000);
    $tCase = rd("$H/temp2_input", 1000);
    $fan   = rd("$H/fan1_input");
    $pwm   = rd("$H/pwm1");

    // Input power / efficiency need model specific coefficients. On an
    // unrecognised model report null rather than a made-up number.
    $pIn = null; $eff = null;
    if ($psu['known'] && $pTot !== null && $vin !== null) {
        $a = $psu['coeffs']['i115']; $b = $psu['coeffs']['i230'];
        $q115 = $a[0]*$pTot*$pTot + $a[1]*$pTot + $a[2];
        $q230 = $b[0]*$pTot*$pTot + $b[1]*$pTot + $b[2];
        $pIn  = $q115 + ($q230 - $q115) * ($vin - 115) / 115;
        if ($pIn > 0) $eff = round($pTot / $pIn * 100, 1);
        $pIn = round($pIn, 1);
    }

    $cfg = read_config();
    $cap = $psu['capacity'];

    return [
        'connected'  => true,
        'model'      => strtoupper($psu['name']),
        'known'      => $psu['known'],
        'capacity'   => $cap,
        'fan_rpm'    => $fan !== null ? round($fan) : null,
        'fan_duty'   => $pwm !== null ? round($pwm / 255 * 100) : null,
        'efficiency' => $eff,
        'temp_vrm'   => round($tVrm, 2),
        'temp_case'  => round($tCase, 2),
        'power_in'   => $pIn,
        'power_out'  => round($pTot, 1),
        'load_pct'   => $cap ? round($pTot / $cap * 100, 1) : null,
        'v_in'       => $vin    !== null ? round($vin, 1)    : null,
        'v_in_sensor'=> $vinRaw !== null ? round($vinRaw, 1) : null,
        'mains'      => $cfg['mains'],
        'v_12'       => round($v12, 2),  'c_12' => round($c12, 2), 'p_12' => round($p12, 1),
        'v_5'        => round($v5, 2),   'c_5'  => round($c5, 2),  'p_5'  => round($p5, 1),
        'v_33'       => round($v33, 2),  'c_33' => round($c33, 2), 'p_33' => round($p33, 1),
        'fan_mode'   => $cfg['mode'],
        'fan_fixed'  => intval($cfg['fixed']),
        'fan_curve'  => $cfg['curve'],
        'ocp_mode'   => $cfg['ocp'],
        'daemon'     => daemon_running(),
    ];
}

$action = $_REQUEST['action'] ?? 'status';
$H   = hwmon_path();
$psu = $H ? detect_psu($H) : null;

switch ($action) {

    case 'status':
        echo json_encode(get_status());
        break;

    case 'energy':
        energyd_start();                    // revive the collector if it ever died
        $cfg  = read_config();
        $rate = isset($cfg['rate']) ? floatval($cfg['rate']) : 0.0;
        $cur  = isset($cfg['currency']) ? $cfg['currency'] : '$';
        echo json_encode(energy_summary($rate, $cur));
        break;

    case 'setrate':
        $cfg = read_config();
        if (isset($_REQUEST['rate']))     $cfg['rate'] = max(0, floatval($_REQUEST['rate']));
        if (isset($_REQUEST['currency'])) {
            $cur = preg_replace('/[^\p{L}\p{Sc}.\s]/u', '', (string)$_REQUEST['currency']);
            $cfg['currency'] = ($cur === '') ? '$' : mb_substr($cur, 0, 4);
        }
        write_config($cfg);
        echo json_encode(['ok' => true, 'energy' => energy_summary(floatval($cfg['rate']), $cfg['currency'] ?? '$')]);
        break;

    case 'resetenergy':
        energyd_stop();
        @unlink(CPC_STATE);
        @unlink(CPC_LIVE);
        @unlink(CPC_DAILY);
        energyd_start();
        echo json_encode(['ok' => true]);
        break;

    case 'setmains':
        $cfg = read_config();
        $m = $_REQUEST['mains'] ?? 'auto';
        $cfg['mains'] = in_array($m, ['auto', '115', '230'], true) ? $m : 'auto';
        write_config($cfg);
        echo json_encode(['ok' => true, 'status' => get_status()]);
        break;

    case 'setocp':
        $mode = $_REQUEST['mode'] ?? 'multi';
        $flag = ($mode === 'single') ? '--single-12v-ocp' : '';
        $r = liquidctl("initialize --direct-access $flag", $psu);
        if ($r['ok']) {
            $cfg = read_config();
            $cfg['ocp'] = ($mode === 'single') ? 'Single rail' : 'Multi rail';
            write_config($cfg);
            // initialize() also resets fan control to hardware mode, so a
            // running curve/fixed setting has to be re-applied afterwards.
            if ($cfg['mode'] === 'curve') daemon_start();
            elseif ($cfg['mode'] === 'fixed') liquidctl('set fan speed ' . intval($cfg['fixed']), $psu);
        }
        echo json_encode(['ok' => $r['ok'], 'output' => $r['output'], 'status' => get_status()]);
        break;

    case 'setfanmode':
        $cfg = read_config();
        $cfg['mode'] = $_REQUEST['mode'] ?? 'auto';
        if (isset($_REQUEST['fixed'])) $cfg['fixed'] = max(30, min(100, intval($_REQUEST['fixed'])));
        if (isset($_REQUEST['curve'])) {
            $pts = [];
            foreach (preg_split('/\s+/', trim($_REQUEST['curve'])) as $p) {
                if (preg_match('/^(\d+),(\d+)$/', $p, $m)) $pts[] = "{$m[1]},{$m[2]}";
            }
            if ($pts) $cfg['curve'] = implode(' ', $pts);
        }
        write_config($cfg);

        if ($cfg['mode'] === 'auto') {
            daemon_stop();
            $r = liquidctl('initialize --direct-access', $psu);   // hands fan back to PSU firmware
        } elseif ($cfg['mode'] === 'fixed') {
            daemon_stop();
            $r = liquidctl('set fan speed ' . intval($cfg['fixed']), $psu);
        } else {
            daemon_start();                                        // curve loop owns the duty
            $r = ['ok' => true, 'output' => 'curve daemon started'];
        }
        echo json_encode(['ok' => $r['ok'], 'output' => $r['output'], 'status' => get_status()]);
        break;

    default:
        echo json_encode(['ok' => false, 'output' => 'unknown action']);
}
