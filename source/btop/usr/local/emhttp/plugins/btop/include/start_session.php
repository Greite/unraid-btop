<?php
// Starts the fullscreen btop ttyd session behind Unraid's nginx
// (/logterminal/btop/ -> unix:/var/tmp/btop.sock). Takes NO input:
// socket path and command are fixed, so there is no injection surface.
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// Serialize concurrent start attempts (double-clicks): only one request
// may run the check-then-spawn sequence at a time.
$lock = fopen('/var/tmp/btop-session.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => false, 'status' => 'busy']);
    exit;
}

$sock = '/var/tmp/btop.sock';
$btop = '/usr/bin/btop';

exec('pgrep -f ' . escapeshellarg("ttyd.*$sock"), $pids, $rc);
if ($rc === 0) {
    echo json_encode(['ok' => false, 'status' => 'busy']);
    exit;
}
if (file_exists($sock)) @unlink($sock); // stale socket left by a crashed ttyd

if (!is_executable($btop)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'btop binary missing - reinstall the plugin']);
    exit;
}

// Unraid ships a ttyd-exec wrapper; fall back to plain ttyd
$ttyd = trim((string)shell_exec('command -v ttyd-exec || command -v ttyd'));
if ($ttyd === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ttyd not found']);
    exit;
}

// -s 9: SIGKILL on close, -o: exit once the client disconnects, -m 1: single client
exec(sprintf('nohup %s -s 9 -o -m 1 -i %s %s >/dev/null 2>&1 &',
    escapeshellarg($ttyd), escapeshellarg($sock), escapeshellarg($btop)));

// Wait up to 2 s for the socket to appear
for ($i = 0; $i < 20 && !file_exists($sock); $i++) usleep(100000);

flock($lock, LOCK_UN);

echo json_encode(['ok' => file_exists($sock)]);
