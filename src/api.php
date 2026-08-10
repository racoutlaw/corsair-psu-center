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
 */

header('Content-Type: application/json');

define('CFG_DIR',  '/boot/config/plugins/corsairpsucenter');
define('CFG_FILE', CFG_DIR . '/settings.cfg');
define('PID_FILE', '/var/run/corsairpsucenter-fand.pid');
define('DAEMON',   '/usr/local/emhttp/plugins/corsairpsucenter/fand.sh');

/*
 * Known Corsair digital PSUs, keyed by USB HID product id (vendor is always
 * 0x1b1c). fpowin115/fpowin230 are quadratic fits mapping output power ->
 * input power; they are model specific and come from liquidctl's
 * corsair_hid_psu driver, which sources them from measured efficiency data.
 * efficiency = output / input * 100.
 */
const PSU_MODELS = [
    0x1c05 => ['name' => 'Corsair HX750i',
               'i115' => [0.00013153276902318052, 1.0118732314945875,  9.783796618886313],
               'i230' => [9.268856467314546e-05,  1.0183515407387007,  8.279822175342481]],
    0x1c06 => ['name' => 'Corsair HX850i',
               'i115' => [0.00011552923724840388, 1.0111311876704099, 12.015296651918918],
               'i230' => [8.126644224872423e-05,  1.0176256272095185, 10.290640442373850]],
    0x1c07 => ['name' => 'Corsair HX1000i',
               'i115' => [9.48609754417109e-05,   1.0170509865269720, 11.619826520447452],
               'i230' => [9.649987544008507e-05,  1.0018241767296636, 12.759957859756842]],
    0x1c08 => ['name' => 'Corsair HX1200i',
               'i115' => [6.244705156199815e-05,  1.0234738310580973, 15.293509559389241],
               'i230' => [5.9413179794350966e-05, 1.0023670927127724, 15.886126793547152]],
    0x1c23 => ['name' => 'Corsair HX1200i ATX 3.1',
               'i115' => [9.930197967499293e-05,  1.003634953854399,  13.956713659543981],
               'i230' => [4.716701557627399e-05,  1.031689131040792,   8.562560345390088]],
    0x1c27 => ['name' => 'Corsair HX1200i ATX 3.1',
               'i115' => [8.701178559061476e-05,  1.0119502460041445, 12.725770701505295],
               'i230' => [3.4692421780176756e-05, 1.0391630676290817,  7.429785098514605]],
    0x1c0a => ['name' => 'Corsair RM650i',
               'i115' => [0.00017323493381072683, 1.0047044721686030, 12.376592422281606],
               'i230' => [0.00012413136310310370, 1.0284317478987164,  9.465259079360674]],
    0x1c0b => ['name' => 'Corsair RM750i',
               'i115' => [0.00015013694263596336, 1.0047044721686027, 14.280683564171110],
               'i230' => [0.00010460621468919797, 1.0173089573727216, 11.495900706372142]],
    0x1c0c => ['name' => 'Corsair RM850i',
               'i115' => [0.00012280002467981107, 1.0159421430340847, 13.555472968718759],
               'i230' => [8.816054254801031e-05,  1.0234738318592156, 10.832902491655597]],
    0x1c0d => ['name' => 'Corsair RM1000i',
               'i115' => [0.00010018433053123574, 1.0272313660072225, 14.092187353321624],
               'i230' => [8.600634771656125e-05,  1.0289245073649413, 13.701515390258626]],
    0x1c1e => ['name' => 'Corsair HX1000i (2022)',
               'i115' => [0.00012038623467957958, 0.9899868099948035, 13.125601514017152],
               'i230' => [8.725695209710315e-05,  1.0017598021499974,  9.789546063300154]],
    0x1c1f => ['name' => 'Corsair HX1500i',
               'i115' => [6.605968230747892e-05,  1.0125991461405333, 17.96728350708451],
               'i230' => [4.634428233657273e-05,  1.0183515407387007, 16.559644350684962]],
];

function hwmon_path() {
    foreach (glob('/sys/class/hwmon/hwmon*') as $h) {
        $n = @trim(file_get_contents("$h/name"));
        if ($n === 'corsairpsu') return $h;
    }
    return null;
}

/*
 * Identify the PSU straight from sysfs - no liquidctl call, so this is cheap
 * enough to run on every poll. uevent carries e.g.
 *   HID_ID=0003:00001B1C:00001C07
 */
function detect_psu($H) {
    $vendor = null; $product = null;
    $uevent = @file_get_contents("$H/device/uevent");
    if ($uevent && preg_match('/HID_ID=[0-9A-Fa-f]+:([0-9A-Fa-f]+):([0-9A-Fa-f]+)/', $uevent, $m)) {
        $vendor  = hexdec($m[1]);
        $product = hexdec($m[2]);
    }
    $known = ($product !== null && isset(PSU_MODELS[$product])) ? PSU_MODELS[$product] : null;
    $name  = $known ? $known['name'] : 'Corsair PSU';

    // Wattage is carried in the model name (HX750i -> 750). Derive it rather
    // than keeping a second table in sync.
    $capacity = null;
    if (preg_match('/(\d{3,4})/', $name, $m)) $capacity = intval($m[1]);

    return [
        'vendor'   => $vendor,
        'product'  => $product,
        'name'     => $name,
        'capacity' => $capacity,
        'coeffs'   => $known,
        'known'    => $known !== null,
    ];
}

function rd($path, $div = 1.0) {
    $v = @file_get_contents($path);
    if ($v === false) return null;
    return floatval(trim($v)) / $div;
}

function read_config() {
    $def = ['mode' => 'auto', 'fixed' => 40, 'curve' => '20,30 40,35 55,55 70,80 85,100', 'ocp' => 'unknown'];
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

function get_status() {
    $H = hwmon_path();
    if (!$H) return ['connected' => false];

    $psu = detect_psu($H);

    $vin   = rd("$H/in0_input", 1000);
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
        'v_in'       => round($vin, 1),
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
