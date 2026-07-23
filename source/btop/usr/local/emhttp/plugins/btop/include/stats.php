<?php
// Dashboard tile endpoint. Collects only the sections enabled in the plugin
// config. Rates are computed against the previous request's samples persisted
// in /var/tmp (see btop_rate_mode in stats_lib.php); only the first request
// after boot/idle pays the 500 ms double-sample.
require_once __DIR__ . '/stats_lib.php';

$cfg = btop_read_cfg();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$out = ['refresh_ms' => $cfg['REFRESH_MS']];

if ($cfg['SHOW_LOAD'] === 'yes') $out['load'] = btop_read_loadavg('/proc');
if ($cfg['SHOW_TEMP'] === 'yes') {
    $unit = btop_display_temp_unit();
    $out['cpu_temp'] = btop_convert_temp(btop_read_cpu_temp('/sys/class/hwmon'), $unit);
    $out['temp_unit'] = $unit;
}
if ($cfg['SHOW_UPTIME'] === 'yes') $out['uptime'] = btop_read_uptime('/proc');

$wantProcs = $cfg['SHOW_PROCS'] === 'yes';
$wantDisk = $cfg['SHOW_DISKIO'] === 'yes';
$wantNet = $cfg['SHOW_NET'] === 'yes';
$wantNprocs = $cfg['SHOW_NPROCS'] === 'yes';

$samples = [];
if ($wantProcs || $wantNprocs) $samples['procs'] = btop_sample_procs('/proc');
if ($wantDisk) $samples['disk'] = btop_sample_diskio('/proc');
if ($wantNet) $samples['net'] = btop_sample_net('/sys/class/net');

if ($wantNprocs) $out['nprocs'] = isset($samples['procs']) ? count($samples['procs']) : null;
if ($wantProcs) $out['mem_total'] = btop_read_memtotal('/proc');

$wanted = array_keys(array_filter(['procs' => $wantProcs, 'disk' => $wantDisk, 'net' => $wantNet]));
if ($wanted) {
    $stateFile = '/var/tmp/btop-tile-state.json';
    $now = microtime(true);
    $prev = btop_state_load($stateFile);
    $mode = btop_rate_mode($prev, $wanted, $now);

    if ($mode === 'reuse') {
        foreach (['procs' => $wantProcs, 'diskio' => $wantDisk, 'net' => $wantNet] as $key => $want) {
            if ($want && array_key_exists($key, $prev['out'])) $out[$key] = $prev['out'][$key];
        }
    } else {
        if ($mode === 'cold') {
            $prevSamples = $samples;
            $t0 = $now;
            usleep(500000);
            if ($wantProcs) $samples['procs'] = btop_sample_procs('/proc');
            if ($wantDisk) $samples['disk'] = btop_sample_diskio('/proc');
            if ($wantNet) $samples['net'] = btop_sample_net('/sys/class/net');
            $now = microtime(true);
            $elapsed = $now - $t0;
        } else {
            $prevSamples = $prev['samples'];
            $elapsed = $now - (float)$prev['ts'];
        }

        $rates = [];
        if ($wantProcs) {
            $rates['procs'] = btop_top_procs($prevSamples['procs'] ?? [], $samples['procs'],
                $elapsed, $cfg['PROC_COUNT'], 100, $cfg['PROC_SORT']);
            if ($cfg['PROC_NAMES'] === 'container') {
                $rates['procs'] = btop_map_proc_labels($rates['procs'], '/proc', '/var/lib/docker');
            }
        }
        if ($wantDisk) {
            $rates['diskio'] = ($prevSamples['disk'] ?? null) !== null && $samples['disk'] !== null
                ? btop_disk_rates($prevSamples['disk'], $samples['disk'], $elapsed) : null;
        }
        if ($wantNet) {
            $rates['net'] = ($prevSamples['net'] ?? null) !== null && $samples['net'] !== null
                ? btop_net_rates($prevSamples['net'], $samples['net'], $elapsed) : null;
        }
        $out += $rates;
        btop_state_save($stateFile, ['ts' => $now, 'samples' => $samples, 'out' => $rates]);
    }
}

echo json_encode($out);
