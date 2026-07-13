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

// --- temperature display unit (Unraid Settings > Display Settings) ---
check('temp unit F from dynamix cfg', 'F', btop_display_temp_unit("$fx/dynamix_f.cfg"));
check('temp unit C from dynamix cfg', 'C', btop_display_temp_unit("$fx/dynamix_c.cfg"));
check('temp unit defaults to C without cfg', 'C', btop_display_temp_unit("$fx/does_not_exist.cfg"));
check('temp unit invalid value falls back to C', 'C', btop_display_temp_unit("$fx/dynamix_bad.cfg"));
check('temp converted to fahrenheit', 126, btop_convert_temp(52, 'F'));
check('temp kept in celsius', 52, btop_convert_temp(52, 'C'));
check('temp null passthrough', null, btop_convert_temp(null, 'F'));

$s0 = btop_sample_procs("$fx/proc_t0");
$s1 = btop_sample_procs("$fx/proc_t1");
check('kernel thread excluded', false, isset($s0[300]));
check('name prefers cmdline argv0 basename over truncated comm', 'qemu-system', $s0[100]['name']);
check('name falls back to comm (parens/spaces kept) when argv0 unusable', 'weird (name) x', $s0[500]['name']);
check('ticks summed (utime+stime)', 600, $s0[100]['ticks']);
check('rss in bytes (pages * 4096)', 2048 * 4096, $s0[100]['rss']);

$top = btop_top_procs($s0, $s1, 0.5, 5);
check('sorted by cpu desc', 'qemu-system', $top[0]['name']);
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
    'SHOW_PROCS' => 'yes', 'PROC_SORT' => 'cpu', 'PROC_NAMES' => 'process',
    'PROC_COUNT' => 5, 'REFRESH_MS' => 3000,
], btop_validate_cfg([]));
check('cfg proc_names container kept', 'container', btop_validate_cfg(['PROC_NAMES' => 'container'])['PROC_NAMES']);
check('cfg proc_names invalid falls back to process', 'process', btop_validate_cfg(['PROC_NAMES' => 'pod'])['PROC_NAMES']);
check('cfg nprocs toggle independent from uptime', 'yes', btop_validate_cfg(['SHOW_NPROCS' => 'yes'])['SHOW_NPROCS']);
check('cfg invalid bool falls back to default', 'yes', btop_validate_cfg(['SHOW_LOAD' => 'maybe'])['SHOW_LOAD']);
check('cfg valid toggle kept', 'yes', btop_validate_cfg(['SHOW_DISKIO' => 'yes'])['SHOW_DISKIO']);
check('cfg invalid sort falls back to cpu', 'cpu', btop_validate_cfg(['PROC_SORT' => 'disk'])['PROC_SORT']);
check('cfg count clamped high', 10, btop_validate_cfg(['PROC_COUNT' => '99'])['PROC_COUNT']);
check('cfg count clamped low', 3, btop_validate_cfg(['PROC_COUNT' => '1'])['PROC_COUNT']);
check('cfg count non-numeric -> default', 5, btop_validate_cfg(['PROC_COUNT' => 'abc'])['PROC_COUNT']);
check('cfg refresh clamped', 2000, btop_validate_cfg(['REFRESH_MS' => '500'])['REFRESH_MS']);

check('read_cfg returns validated defaults without dynamix', btop_validate_cfg([]), btop_read_cfg());

// --- v3: pid reuse between samples (ticks went backwards) ---
$r0 = [1 => ['name' => 'x', 'ticks' => 100, 'rss' => 4096]];
$r1 = [1 => ['name' => 'x', 'ticks' => 40, 'rss' => 4096]];
check('pid reuse clamps cpu to 0', 0.0, btop_top_procs($r0, $r1, 0.5)[0]['cpu']);

// --- v3: total memory (for the tile's memory bars) ---
check('memtotal parsed from meminfo (kB -> bytes)', 16384000 * 1024, btop_read_memtotal("$fx/proc_t0"));
check('memtotal missing file', null, btop_read_memtotal("$fx/does_not_exist"));

// --- v3: rates over a real elapsed interval ---
check('disk rates over elapsed', ['read_bps' => 256000, 'write_bps' => 384000],
    btop_disk_rates($d0, $d1, 2.0)); // (151000-150000)*512/2 ; (231500-230000)*512/2
check('disk rates counter regression -> null', null, btop_disk_rates($d1, $d0, 2.0));
check('disk rates zero elapsed -> null', null, btop_disk_rates($d0, $d1, 0.0));
check('net rates over elapsed', ['rx_bps' => 500, 'tx_bps' => 250],
    btop_net_rates(['rx' => 0, 'tx' => 0], ['rx' => 1000, 'tx' => 500], 2.0));
check('net rates counter regression -> null', null,
    btop_net_rates(['rx' => 1000, 'tx' => 500], ['rx' => 0, 'tx' => 0], 2.0));

// --- v3: sampling mode decided from the persisted state ---
$prev = ['ts' => 100.0, 'samples' => ['disk' => $d0], 'out' => ['diskio' => null]];
check('mode delta on fresh state', 'delta', btop_rate_mode($prev, ['disk'], 103.0));
check('mode cold without state', 'cold', btop_rate_mode(null, ['disk'], 103.0));
check('mode cold on stale state', 'cold', btop_rate_mode($prev, ['disk'], 200.0));
check('mode cold when a wanted sample is missing', 'cold', btop_rate_mode($prev, ['disk', 'net'], 103.0));
check('mode reuse on sub-second poll', 'reuse', btop_rate_mode($prev, ['disk'], 100.4));
check('mode cold on sub-second poll without stored output', 'cold',
    btop_rate_mode(['ts' => 100.0, 'samples' => ['disk' => $d0]], ['disk'], 100.4));
check('mode cold on clock going backwards', 'cold', btop_rate_mode($prev, ['disk'], 99.0));

// --- v3: state persistence ---
$tmpState = sys_get_temp_dir() . '/btop-test-state-' . getmypid() . '.json';
@unlink($tmpState);
check('state load missing -> null', null, btop_state_load($tmpState));
btop_state_save($tmpState, ['ts' => 1.5, 'samples' => []]);
check('state roundtrip', ['ts' => 1.5, 'samples' => []], btop_state_load($tmpState));
file_put_contents($tmpState, '{corrupt');
check('state corrupt -> null', null, btop_state_load($tmpState));
@unlink($tmpState);

// --- v3: docker container names ---
$cidA = str_repeat('ab', 32);
check('container id from cgroup v2', $cidA, btop_container_id("0::/docker/$cidA\n"));
check('container id from cgroup v1 line', $cidA, btop_container_id("12:memory:/docker/$cidA\n"));
check('container id from systemd scope', $cidA, btop_container_id("0::/system.slice/docker-$cidA.scope\n"));
check('no container id for host process', null, btop_container_id("0::/\n"));
check('no container id for non-hex path', null, btop_container_id("0::/docker/not-a-container\n"));

check('container name resolved (leading slash trimmed)', 'plex', btop_container_name($cidA, "$fx/docker"));
check('container name unknown id', null, btop_container_name(str_repeat('ff', 32), "$fx/docker"));
check('container name malformed id rejected', null, btop_container_name('../../etc/passwd', "$fx/docker"));

// --- issue #1: overlong process names are capped so the dashboard tile's
//     name column can't stretch the tile past its width ---
check('short name kept as-is', 'nginx', btop_truncate_name('nginx'));
check('name at the cap kept as-is', str_repeat('a', 48), btop_truncate_name(str_repeat('a', 48)));
check('overlong name truncated with ellipsis', str_repeat('a', 47) . '…', btop_truncate_name(str_repeat('a', 100)));
check('truncation counts characters, not bytes (utf-8 safe)', str_repeat('é', 47) . '…', btop_truncate_name(str_repeat('é', 100)));
$sl = btop_sample_procs("$fx/proc_longname");
check('sampled overlong argv0 is capped to 48 chars', 48, mb_strlen($sl[700]['name']));

check('top rows carry pid', 100, btop_top_procs($s0, $s1, 0.5, 5)[0]['pid']);

// proc_t1: pid 200 (sshd) has a docker cgroup, pid 100 (qemu) has none
$mapped = btop_map_container_names(btop_top_procs($s0, $s1, 0.5, 5), "$fx/proc_t1", "$fx/docker");
check('containerized proc gets cname', 'plex', $mapped[1]['cname'] ?? null);
check('containerized proc keeps its process name', 'sshd', $mapped[1]['name']);
check('host proc gets no cname', false, isset($mapped[0]['cname']));

echo $failures === 0 ? "ALL TESTS PASSED\n" : "$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
