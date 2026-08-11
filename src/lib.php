<?php
/*
 * Corsair PSU Center - shared library.
 *
 * PSU model table + sysfs helpers + input-power estimate + energy summary.
 * Included by the energy collector daemon (energyd.php) and by api.php.
 * Kept dependency-free (sysfs only) so the collector needs no USB and no
 * python - it can run in every fan mode with near-zero cost.
 */

if (!defined('CFG_DIR'))  define('CFG_DIR',  '/boot/config/plugins/corsairpsucenter');
if (!defined('CFG_FILE')) define('CFG_FILE', CFG_DIR . '/settings.cfg');
define('CPC_EDIR',  CFG_DIR . '/energy');
define('CPC_STATE', CPC_EDIR . '/state.json');
define('CPC_DAILY', CPC_EDIR . '/daily.csv');
define('CPC_EPID',  '/var/run/corsairpsucenter-energyd.pid');
define('CPC_LIVE',  '/var/run/corsairpsucenter-energy.json');  // RAM (tmpfs): fresh copy for the UI, no flash wear

/*
 * Known Corsair digital PSUs, keyed by USB HID product id (vendor is always
 * 0x1b1c). i115/i230 are quadratic fits mapping output power -> input power;
 * model specific, from liquidctl's corsair_hid_psu driver.
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

/* Identify the PSU from sysfs (uevent HID_ID=0003:00001B1C:00001C07). Cheap
 * enough to run on every poll. */
function detect_psu($H) {
    $vendor = null; $product = null;
    $uevent = @file_get_contents("$H/device/uevent");
    if ($uevent && preg_match('/HID_ID=[0-9A-Fa-f]+:([0-9A-Fa-f]+):([0-9A-Fa-f]+)/', $uevent, $m)) {
        $vendor  = hexdec($m[1]);
        $product = hexdec($m[2]);
    }
    $known = ($product !== null && isset(PSU_MODELS[$product])) ? PSU_MODELS[$product] : null;
    $name  = $known ? $known['name'] : 'Corsair PSU';
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

/*
 * Manual mains-voltage override. Some PSU firmware (notably the HX1000i 2022
 * revision) reports input voltage at ~2x the real value over PMBus, and both
 * the kernel corsair-psu driver and liquidctl echo the same bad number, so it
 * cannot be auto-detected or safely halved (a genuine 230V user must not be
 * "corrected"). When the user pins their real mains we use that for the
 * efficiency curve and the displayed input voltage instead of the sensor.
 * 'auto' (default) trusts the sensor.
 */
function mains_setting() {
    if (!file_exists(CFG_FILE)) return 'auto';
    $c = @parse_ini_file(CFG_FILE);
    $m = (is_array($c) && isset($c['mains'])) ? trim((string)$c['mains']) : 'auto';
    return ($m === '115' || $m === '230') ? $m : 'auto';
}

/* Sensor input voltage corrected by the manual override when one is set. */
function effective_vin($sensorVin) {
    $m = mains_setting();
    return ($m === 'auto') ? $sensorVin : (float)$m;
}

/*
 * Estimated INPUT power (W, wall draw) and efficiency (%) from the model
 * efficiency curve, interpolated by input voltage. Mirrors the math in
 * api.php get_status(). Returns [pIn|null, eff|null].
 */
function input_power($H, $psu) {
    $pTot = rd("$H/power1_input", 1000000);
    $vin  = effective_vin(rd("$H/in0_input", 1000));
    if (!$psu['known'] || $pTot === null || $vin === null) return [null, null];
    $a = $psu['coeffs']['i115']; $b = $psu['coeffs']['i230'];
    $q115 = $a[0]*$pTot*$pTot + $a[1]*$pTot + $a[2];
    $q230 = $b[0]*$pTot*$pTot + $b[1]*$pTot + $b[2];
    $pIn  = $q115 + ($q230 - $q115) * ($vin - 115) / 115;
    $eff  = ($pIn > 0) ? round($pTot / $pIn * 100, 1) : null;
    return [round($pIn, 2), $eff];
}

/* ---- energy accounting (shared read side; energyd.php owns the write side) ---- */

function energyd_running() {
    if (!file_exists(CPC_EPID)) return false;
    $pid = trim(@file_get_contents(CPC_EPID));
    return $pid !== '' && file_exists("/proc/$pid");
}

/*
 * Load the freshest saved state. The RAM live copy (updated every sample) and
 * the flash checkpoint (every 5 min + on stop) can each be the newer one: after
 * a reboot the RAM copy is gone so flash wins; after a quick daemon restart the
 * RAM copy is newer. Pick whichever has the larger last_ts.
 */
function energy_load_state() {
    $def = ['total_wh' => 0.0, 'today' => '', 'today_wh' => 0.0, 'last_ts' => 0, 'since' => 0];
    $best = $def;
    foreach ([CPC_STATE, CPC_LIVE] as $path) {
        if (!file_exists($path)) continue;
        $j = @json_decode(@file_get_contents($path), true);
        if (is_array($j) && ($j['last_ts'] ?? 0) >= ($best['last_ts'] ?? 0)) $best = array_merge($def, $j);
    }
    return $best;
}

/* Completed days from daily.csv -> ['YYYY-MM-DD' => wh]. */
function energy_read_daily() {
    $out = [];
    if (!file_exists(CPC_DAILY)) return $out;
    foreach (file(CPC_DAILY, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $p = explode(',', $line);
        if (count($p) >= 2 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p[0]))
            $out[$p[0]] = (isset($out[$p[0]]) ? $out[$p[0]] : 0) + (float)$p[1];
    }
    return $out;
}

/*
 * Roll the raw energy store up into the windows the UI shows. $rate is $/kWh,
 * $currency a display symbol. Lifetime uses the authoritative running counter;
 * today/week/month/year come from the per-day buckets (best effort).
 */
function energy_summary($rate = 0.0, $currency = '$') {
    $st    = energy_load_state();
    $daily = energy_read_daily();
    // fold today's in-progress bucket in
    if ($st['today'] !== '')
        $daily[$st['today']] = (isset($daily[$st['today']]) ? $daily[$st['today']] : 0) + (float)$st['today_wh'];

    $todayStr  = date('Y-m-d');
    $weekStart = strtotime('-6 days', strtotime($todayStr));
    $ym = date('Y-m'); $y = date('Y');

    $wh_today = isset($daily[$todayStr]) ? $daily[$todayStr] : 0.0;
    $wh_week = 0.0; $wh_month = 0.0; $wh_year = 0.0;
    foreach ($daily as $d => $wh) {
        $ts = strtotime($d);
        if ($ts !== false && $ts >= $weekStart) $wh_week += $wh;
        if (strpos($d, $ym) === 0)      $wh_month += $wh;
        if (strpos($d, $y . '-') === 0) $wh_year  += $wh;
    }

    // last 30 calendar days, oldest..newest, for a chart
    $series = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $series[] = ['date' => $d, 'kwh' => round((isset($daily[$d]) ? $daily[$d] : 0) / 1000, 3)];
    }

    $mk = function ($wh) use ($rate) {
        $kwh = $wh / 1000.0;
        return ['kwh' => round($kwh, 3), 'cost' => round($kwh * $rate, 2)];
    };

    return [
        'today'    => $mk($wh_today),
        'week'     => $mk($wh_week),
        'month'    => $mk($wh_month),
        'year'     => $mk($wh_year),
        'lifetime' => $mk((float)$st['total_wh']),
        'rate'     => (float)$rate,
        'currency' => $currency,
        'since'    => $st['since'] ? intval($st['since']) : null,
        'running'  => energyd_running(),
        'series'   => $series,
    ];
}
