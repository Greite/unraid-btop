<?php
// Dashboard tile endpoint: instantaneous CPU usage needs two /proc samples.
require_once __DIR__ . '/stats_lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$s0 = btop_sample_procs('/proc');
usleep(500000);
$s1 = btop_sample_procs('/proc');

echo json_encode([
    'load'     => btop_read_loadavg('/proc'),
    'cpu_temp' => btop_read_cpu_temp('/sys/class/hwmon'),
    'procs'    => btop_top_procs($s0, $s1, 0.5),
]);
