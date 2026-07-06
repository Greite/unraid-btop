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

// --- v2: uptime ---
check('uptime parsed (rounded)', 123457, btop_read_uptime("$fx/proc_t0"));
check('uptime missing file', null, btop_read_uptime("$fx/does_not_exist"));

// --- v2: aggregated disk I/O ---
$d0 = btop_sample_diskio("$fx/proc_t0");
check('diskio t0 read sectors (physical disks only)', 150000, $d0['read_sectors']);
check('diskio t0 write sectors (physical disks only)', 230000, $d0['write_sectors']);
$d1 = btop_sample_diskio("$fx/proc_t1");
check('diskio t1 read sectors', 151000, $d1['read_sectors']);
check('diskio t1 write sectors', 231500, $d1['write_sectors']);
check('diskio missing file', null, btop_sample_diskio("$fx/does_not_exist"));
check('diskio no physical disk', null, btop_sample_diskio("$fx/proc_nophys"));

// --- v2: aggregated network ---
check('net sums physical interfaces only', ['rx' => 1000000, 'tx' => 500000], btop_sample_net("$fx/sys_net"));
check('net no physical interface', null, btop_sample_net("$fx/sys_net_none"));

// --- v2: process sort ---
$a0 = [1 => ['name' => 'cpuhog', 'ticks' => 0, 'rss' => 4096], 2 => ['name' => 'memhog', 'ticks' => 0, 'rss' => 999424]];
$a1 = [1 => ['name' => 'cpuhog', 'ticks' => 50, 'rss' => 4096], 2 => ['name' => 'memhog', 'ticks' => 1, 'rss' => 999424]];
check('sort cpu (default) unchanged', 'cpuhog', btop_top_procs($a0, $a1, 0.5)[0]['name']);
check('sort mem puts memhog first', 'memhog', btop_top_procs($a0, $a1, 0.5, 5, 100, 'mem')[0]['name']);

// --- v2: config validation ---
check('cfg defaults (empty input)', [
    'SHOW_LOAD' => 'yes', 'SHOW_TEMP' => 'yes', 'SHOW_UPTIME' => 'no',
    'SHOW_NPROCS' => 'no', 'SHOW_DISKIO' => 'no', 'SHOW_NET' => 'no',
    'SHOW_PROCS' => 'yes', 'PROC_SORT' => 'cpu', 'PROC_COUNT' => 5,
    'REFRESH_MS' => 3000,
], btop_validate_cfg([]));
check('cfg nprocs toggle independent from uptime', 'yes', btop_validate_cfg(['SHOW_NPROCS' => 'yes'])['SHOW_NPROCS']);
check('cfg invalid bool falls back to default', 'yes', btop_validate_cfg(['SHOW_LOAD' => 'maybe'])['SHOW_LOAD']);
check('cfg valid toggle kept', 'yes', btop_validate_cfg(['SHOW_DISKIO' => 'yes'])['SHOW_DISKIO']);
check('cfg invalid sort falls back to cpu', 'cpu', btop_validate_cfg(['PROC_SORT' => 'disk'])['PROC_SORT']);
check('cfg count clamped high', 10, btop_validate_cfg(['PROC_COUNT' => '99'])['PROC_COUNT']);
check('cfg count clamped low', 3, btop_validate_cfg(['PROC_COUNT' => '1'])['PROC_COUNT']);
check('cfg count non-numeric -> default', 5, btop_validate_cfg(['PROC_COUNT' => 'abc'])['PROC_COUNT']);
check('cfg refresh clamped', 2000, btop_validate_cfg(['REFRESH_MS' => '500'])['REFRESH_MS']);

check('read_cfg returns validated defaults without dynamix', btop_validate_cfg([]), btop_read_cfg());

echo $failures === 0 ? "ALL TESTS PASSED\n" : "$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
