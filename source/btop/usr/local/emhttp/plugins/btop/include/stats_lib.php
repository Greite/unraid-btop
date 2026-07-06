<?php
// Data collection library for the btop dashboard tile.
// All functions take their /proc and /sys roots as parameters so they can
// be tested against fixtures.

function btop_read_loadavg(string $procRoot): ?array {
    $raw = @file_get_contents("$procRoot/loadavg");
    if ($raw === false) return null;
    $parts = preg_split('/\s+/', trim($raw));
    if (count($parts) < 3) return null;
    return [(float)$parts[0], (float)$parts[1], (float)$parts[2]];
}

function btop_read_cpu_temp(string $hwmonRoot): ?int {
    $known = ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal'];
    foreach (glob("$hwmonRoot/hwmon*") ?: [] as $dir) {
        $name = trim(@file_get_contents("$dir/name") ?: '');
        if (!in_array($name, $known, true)) continue;
        $best = null;
        foreach (glob("$dir/temp*_input") ?: [] as $input) {
            $label = trim(@file_get_contents(preg_replace('/_input$/', '_label', $input)) ?: '');
            $val = (int)trim(@file_get_contents($input) ?: '0');
            if ($val <= 0) continue;
            $celsius = (int)round($val / 1000);
            // Prefer the package/die sensor; otherwise keep the hottest core
            if (preg_match('/^(Package id|Tctl|Tdie)/', $label)) return $celsius;
            $best = max($best ?? 0, $celsius);
        }
        if ($best !== null) return $best;
    }
    return null;
}

function btop_sample_procs(string $procRoot): array {
    $sample = [];
    foreach (glob("$procRoot/[0-9]*", GLOB_ONLYDIR) ?: [] as $dir) {
        $pid = (int)basename($dir);
        $stat = @file_get_contents("$dir/stat");
        if ($stat === false) continue;
        // Kernel threads have an empty cmdline — skip them
        $cmdline = @file_get_contents("$dir/cmdline");
        if ($cmdline === false || $cmdline === '') continue;
        // comm is wrapped in parentheses and may contain spaces/parens
        $open = strpos($stat, '(');
        $close = strrpos($stat, ')');
        if ($open === false || $close === false) continue;
        $name = substr($stat, $open + 1, $close - $open - 1);
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));
        // After comm: [0]=state ... [11]=utime [12]=stime ... [21]=rss (pages)
        if (count($fields) < 22) continue;
        $sample[$pid] = [
            'name'  => $name,
            'ticks' => (int)$fields[11] + (int)$fields[12],
            'rss'   => (int)$fields[21] * 4096,
        ];
    }
    return $sample;
}

function btop_top_procs(array $s0, array $s1, float $elapsed, int $n = 5, int $clkTck = 100, string $sort = 'cpu'): array {
    $out = [];
    foreach ($s1 as $pid => $proc) {
        if (!isset($s0[$pid])) continue; // appeared between samples
        $delta = $proc['ticks'] - $s0[$pid]['ticks'];
        $cpu = $elapsed > 0 ? round($delta / $clkTck / $elapsed * 100, 1) : 0.0;
        $out[] = ['name' => $proc['name'], 'cpu' => $cpu, 'mem' => $proc['rss']];
    }
    if ($sort === 'mem') {
        usort($out, fn($a, $b) => $b['mem'] <=> $a['mem'] ?: $b['cpu'] <=> $a['cpu']);
    } else {
        usort($out, fn($a, $b) => $b['cpu'] <=> $a['cpu'] ?: $b['mem'] <=> $a['mem']);
    }
    return array_slice($out, 0, $n);
}

function btop_read_uptime(string $procRoot): ?int {
    $raw = @file_get_contents("$procRoot/uptime");
    if ($raw === false) return null;
    $parts = preg_split('/\s+/', trim($raw));
    if (!isset($parts[0]) || !is_numeric($parts[0])) return null;
    return (int)round((float)$parts[0]);
}

function btop_sample_diskio(string $procRoot): ?array {
    $raw = @file_get_contents("$procRoot/diskstats");
    if ($raw === false) return null;
    $read = $write = 0;
    $found = false;
    foreach (explode("\n", trim($raw)) as $line) {
        $f = preg_split('/\s+/', trim($line));
        // Whole physical disks only: partitions and md/loop/dm devices would
        // double-count the traffic already accounted on the underlying disks
        if (count($f) < 10 || !preg_match('/^(sd[a-z]+|hd[a-z]+|nvme\d+n\d+)$/', $f[2])) continue;
        $found = true;
        $read += (int)$f[5];   // sectors read
        $write += (int)$f[9];  // sectors written
    }
    return $found ? ['read_sectors' => $read, 'write_sectors' => $write] : null;
}

function btop_sample_net(string $sysRoot): ?array {
    $rx = $tx = 0;
    $found = false;
    foreach (glob("$sysRoot/*", GLOB_ONLYDIR) ?: [] as $dir) {
        // Physical interfaces only: bridges/bonds aggregate the same traffic
        if (!file_exists("$dir/device")) continue;
        $r = @file_get_contents("$dir/statistics/rx_bytes");
        $t = @file_get_contents("$dir/statistics/tx_bytes");
        if ($r === false || $t === false) continue;
        $found = true;
        $rx += (int)trim($r);
        $tx += (int)trim($t);
    }
    return $found ? ['rx' => $rx, 'tx' => $tx] : null;
}

function btop_validate_cfg(array $raw): array {
    $bool = fn($v, string $def) => in_array($v, ['yes', 'no'], true) ? $v : $def;
    $int = function ($v, int $def, int $min, int $max) {
        if (!is_numeric($v)) return $def;
        return max($min, min($max, (int)$v));
    };
    return [
        'SHOW_LOAD'   => $bool($raw['SHOW_LOAD']   ?? null, 'yes'),
        'SHOW_TEMP'   => $bool($raw['SHOW_TEMP']   ?? null, 'yes'),
        'SHOW_UPTIME' => $bool($raw['SHOW_UPTIME'] ?? null, 'no'),
        'SHOW_NPROCS' => $bool($raw['SHOW_NPROCS'] ?? null, 'no'),
        'SHOW_DISKIO' => $bool($raw['SHOW_DISKIO'] ?? null, 'no'),
        'SHOW_NET'    => $bool($raw['SHOW_NET']    ?? null, 'no'),
        'SHOW_PROCS'  => $bool($raw['SHOW_PROCS']  ?? null, 'yes'),
        'PROC_SORT'   => in_array($raw['PROC_SORT'] ?? null, ['cpu', 'mem'], true) ? $raw['PROC_SORT'] : 'cpu',
        'PROC_COUNT'  => $int($raw['PROC_COUNT'] ?? null, 5, 3, 10),
        'REFRESH_MS'  => $int($raw['REFRESH_MS'] ?? null, 3000, 2000, 10000),
    ];
}

function btop_read_cfg(): array {
    $raw = [];
    if (is_file('/usr/local/emhttp/plugins/dynamix/include/Wrappers.php')) {
        require_once '/usr/local/emhttp/plugins/dynamix/include/Wrappers.php';
        try {
            $parsed = parse_plugin_cfg('btop', true);
            if (is_array($parsed)) $raw = $parsed;
        } catch (\Throwable $e) {
            $raw = []; // syntactically corrupt cfg: fall back to defaults
        }
    }
    return btop_validate_cfg($raw);
}
