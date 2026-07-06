<?php
require __DIR__ . '/../../source/btop/usr/local/emhttp/plugins/btop/include/stats_lib.php';

$failures = 0;
function check(string $label, $expected, $actual): void {
    global $failures;
    if ($expected === $actual) { echo "ok - $label\n"; return; }
    $failures++;
    echo "FAIL - $label\n  expected: " . var_export($expected, true)
       . "\n  actual:   " . var_export($actual, true) . "\n";
}

$fx = __DIR__ . '/fixtures';

check('loadavg parsed', [0.42, 0.38, 0.35], btop_read_loadavg("$fx/proc_t0"));
check('loadavg missing file', null, btop_read_loadavg("$fx/does_not_exist"));

check('cpu temp from coretemp package sensor', 52, btop_read_cpu_temp("$fx/hwmon_intel"));
check('cpu temp with no known sensor', null, btop_read_cpu_temp("$fx/hwmon_none"));

$s0 = btop_sample_procs("$fx/proc_t0");
$s1 = btop_sample_procs("$fx/proc_t1");
check('kernel thread excluded', false, isset($s0[300]));
check('process name with space kept', 'qemu-system x', $s0[100]['name']);
check('ticks summed (utime+stime)', 600, $s0[100]['ticks']);
check('rss in bytes (pages * 4096)', 2048 * 4096, $s0[100]['rss']);

$top = btop_top_procs($s0, $s1, 0.5, 5);
check('sorted by cpu desc', 'qemu-system x', $top[0]['name']);
check('cpu percent computed', 84.0, $top[0]['cpu']); // 42 ticks / 100 Hz / 0.5 s * 100
check('idle process has 0 cpu', 0.0, $top[1]['cpu']);
check('process appearing between samples ignored', 2, count($top));

$topOne = btop_top_procs($s0, $s1, 0.5, 1);
check('top limited to n', 1, count($topOne));

echo $failures === 0 ? "ALL TESTS PASSED\n" : "$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
