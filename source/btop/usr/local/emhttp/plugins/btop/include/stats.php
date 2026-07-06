<?php
// Dashboard tile endpoint. Collects only the sections enabled in the plugin
// config; instantaneous rates need two samples 500 ms apart.
require_once __DIR__ . '/stats_lib.php';

$cfg = btop_read_cfg();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$out = ['refresh_ms' => $cfg['REFRESH_MS']];

if ($cfg['SHOW_LOAD'] === 'yes') $out['load'] = btop_read_loadavg('/proc');
if ($cfg['SHOW_TEMP'] === 'yes') $out['cpu_temp'] = btop_read_cpu_temp('/sys/class/hwmon');
if ($cfg['SHOW_UPTIME'] === 'yes') $out['uptime'] = btop_read_uptime('/proc');

$wantProcs = $cfg['SHOW_PROCS'] === 'yes';
$wantDisk = $cfg['SHOW_DISKIO'] === 'yes';
$wantNet = $cfg['SHOW_NET'] === 'yes';
$wantNprocs = $cfg['SHOW_NPROCS'] === 'yes';

$procs1 = null;
if ($wantProcs || $wantDisk || $wantNet) {
    $procs0 = $wantProcs ? btop_sample_procs('/proc') : null;
    $disk0 = $wantDisk ? btop_sample_diskio('/proc') : null;
    $net0 = $wantNet ? btop_sample_net('/sys/class/net') : null;
    usleep(500000);
    $procs1 = ($wantProcs || $wantNprocs) ? btop_sample_procs('/proc') : null;
    $disk1 = $wantDisk ? btop_sample_diskio('/proc') : null;
    $net1 = $wantNet ? btop_sample_net('/sys/class/net') : null;

    if ($wantProcs) {
        $out['procs'] = btop_top_procs($procs0, $procs1, 0.5, $cfg['PROC_COUNT'], 100, $cfg['PROC_SORT']);
    }
    if ($wantDisk) {
        $out['diskio'] = ($disk0 !== null && $disk1 !== null) ? [
            'read_bps'  => (int)(($disk1['read_sectors'] - $disk0['read_sectors']) * 512 / 0.5),
            'write_bps' => (int)(($disk1['write_sectors'] - $disk0['write_sectors']) * 512 / 0.5),
        ] : null;
    }
    if ($wantNet) {
        $out['net'] = ($net0 !== null && $net1 !== null) ? [
            'rx_bps' => (int)(($net1['rx'] - $net0['rx']) / 0.5),
            'tx_bps' => (int)(($net1['tx'] - $net0['tx']) / 0.5),
        ] : null;
    }
} elseif ($wantNprocs) {
    $procs1 = btop_sample_procs('/proc');
}

if ($wantNprocs) $out['nprocs'] = $procs1 !== null ? count($procs1) : null;

echo json_encode($out);
