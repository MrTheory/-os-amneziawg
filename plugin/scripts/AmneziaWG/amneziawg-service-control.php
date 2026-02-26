#!/usr/local/bin/php
<?php

// IMP-9: use absolute path instead of relying on PHP include_path
require_once('/usr/local/etc/inc/config.inc');

define('AWG_CONF_DIR', '/usr/local/etc/amnezia');
define('AWG_BIN',      '/usr/local/bin/awg');
define('AWG_QUICK',    '/usr/local/bin/awg-quick');
define('AWG_PID_FILE', '/var/run/amneziawg.pid');

// IMP-8: check that required binaries exist before any operation
function awg_check_binaries(): bool
{
    foreach ([AWG_BIN, AWG_QUICK] as $bin) {
        if (!file_exists($bin) || !is_executable($bin)) {
            awg_log('ERROR: required binary not found or not executable: ' . $bin);
            return false;
        }
    }
    return true;
}

define('AWG_PRIVKEY_FILE', '/usr/local/etc/amnezia/private.key');
define('AWG_PRIVKEY_SENTINEL', '::file::');

function awg_get_instances(): array
{
    $config = OPNsense\Core\Config::getInstance()->object();
    $inst = $config->OPNsense->amneziawg->instance ?? null;
    if (!isset($inst) || (string)($inst->enabled ?? '0') !== '1') {
        return [];
    }
    $ifnum = !empty((string)($inst->interface_number ?? '')) ? (int)(string)$inst->interface_number : 0;

    // SEC-1: read private key from protected file if sentinel is stored in config.xml
    $privKeyRaw = (string)($inst->private_key ?? '');
    if ($privKeyRaw === AWG_PRIVKEY_SENTINEL) {
        if (!file_exists(AWG_PRIVKEY_FILE)) {
            awg_log('ERROR: private key file not found: ' . AWG_PRIVKEY_FILE);
            return [];
        }
        $privKeyRaw = trim(file_get_contents(AWG_PRIVKEY_FILE));
    }

    return [[
        'interface'                 => 'awg' . $ifnum,
        'private_key'               => $privKeyRaw,
        'address'                   => (string)($inst->address                   ?? ''),
        'listen_port'               => (string)($inst->listen_port               ?? ''),
        'dns'                       => (string)($inst->dns                       ?? ''),
        'mtu'                       => (string)($inst->mtu                       ?? ''),
        'jc'                        => (string)($inst->jc                        ?? ''),
        'jmin'                      => (string)($inst->jmin                      ?? ''),
        'jmax'                      => (string)($inst->jmax                      ?? ''),
        's1'                        => (string)($inst->s1                        ?? ''),
        's2'                        => (string)($inst->s2                        ?? ''),
        'h1'                        => (string)($inst->h1                        ?? ''),
        'h2'                        => (string)($inst->h2                        ?? ''),
        'h3'                        => (string)($inst->h3                        ?? ''),
        'h4'                        => (string)($inst->h4                        ?? ''),
        'peer_public_key'           => (string)($inst->peer_public_key           ?? ''),
        'peer_preshared_key'        => (string)($inst->peer_preshared_key        ?? ''),
        'peer_endpoint'             => (string)($inst->peer_endpoint             ?? ''),
        'peer_allowed_ips'          => (string)($inst->peer_allowed_ips          ?? ''),
        'peer_persistent_keepalive' => (string)($inst->peer_persistent_keepalive ?? ''),
    ]];
}

function awg_sanitize(string $value): string
{
    return str_replace(["\n", "\r"], '', $value);
}

function awg_write_conf(array $inst): string
{
    $lines = ['[Interface]'];
    $lines[] = 'PrivateKey = ' . awg_sanitize($inst['private_key']);
    $lines[] = 'Address = '    . awg_sanitize($inst['address']);
    $lines[] = 'Table = off';

    if (!empty($inst['listen_port'])) {
        $lines[] = 'ListenPort = ' . awg_sanitize($inst['listen_port']);
    }
    if (!empty($inst['dns'])) {
        $lines[] = 'DNS = ' . awg_sanitize($inst['dns']);
    }
    if (!empty($inst['mtu'])) {
        $lines[] = 'MTU = ' . awg_sanitize($inst['mtu']);
    }
    // Obfuscation parameters
    $obf = ['jc'=>'Jc','jmin'=>'Jmin','jmax'=>'Jmax','s1'=>'S1','s2'=>'S2',
            'h1'=>'H1','h2'=>'H2','h3'=>'H3','h4'=>'H4'];
    foreach ($obf as $k => $label) {
        if (!empty($inst[$k])) {
            $lines[] = "$label = " . awg_sanitize($inst[$k]);
        }
    }

    $lines[] = '';
    $lines[] = '[Peer]';
    $lines[] = 'PublicKey = ' . awg_sanitize($inst['peer_public_key']);
    if (!empty($inst['peer_preshared_key'])) {
        $lines[] = 'PresharedKey = ' . awg_sanitize($inst['peer_preshared_key']);
    }
    $lines[] = 'Endpoint = '    . awg_sanitize($inst['peer_endpoint']);
    $lines[] = 'AllowedIPs = '  . awg_sanitize($inst['peer_allowed_ips']);
    if (!empty($inst['peer_persistent_keepalive'])) {
        $lines[] = 'PersistentKeepalive = ' . awg_sanitize($inst['peer_persistent_keepalive']);
    }

    $conf = implode("\n", $lines) . "\n";
    $path = AWG_CONF_DIR . '/' . $inst['interface'] . '.conf';

    if (!is_dir(AWG_CONF_DIR)) {
        mkdir(AWG_CONF_DIR, 0700, true);
    }
    file_put_contents($path, $conf);
    chmod($path, 0600);
    return $path;
}

function awg_log(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    file_put_contents('/var/log/amneziawg.log', "[$ts] $msg\n", FILE_APPEND);
}

function awg_up(array $inst): void
{
    $path = awg_write_conf($inst);
    $out  = [];
    $rc   = 0;
    exec(AWG_QUICK . ' up ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    awg_log('up ' . $inst['interface'] . ' rc=' . $rc . ' | ' . implode(' | ', $out));
    // Create PID file so OPNsense dashboard shows service as running
    if ($rc === 0) {
        file_put_contents(AWG_PID_FILE, getmypid());
    }
}

function awg_down(array $inst): void
{
    $path = AWG_CONF_DIR . '/' . $inst['interface'] . '.conf';
    if (file_exists($path)) {
        $out = [];
        $rc  = 0;
        exec(AWG_QUICK . ' down ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        awg_log('down ' . $inst['interface'] . ' rc=' . $rc . ' | ' . implode(' | ', $out));
    }
    // Remove PID file so OPNsense dashboard shows service as stopped
    if (file_exists(AWG_PID_FILE)) {
        unlink(AWG_PID_FILE);
    }
}

// BUG-3: awg_is_up() was declared but never used — removed.

$action = $argv[1] ?? 'status';

// IMP-10: flock() protection against concurrent reconfigure/start/stop/restart
$lockActions = ['start', 'stop', 'restart', 'reconfigure'];
$lockFp = null;
if (in_array($action, $lockActions, true)) {
    $lockFp = fopen('/var/run/amneziawg.lock', 'c');
    if ($lockFp === false) {
        awg_log('ERROR: cannot open lock file');
        echo "ERROR: cannot open lock file\n";
        exit(1);
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        awg_log('SKIP: another instance of service-control is already running (action=' . $action . ')');
        echo "OK\n"; // return OK so configd doesn't treat it as failure
        fclose($lockFp);
        exit(0);
    }
}

switch ($action) {
    case 'start':
    case 'reconfigure':
        // IMP-8: verify binaries before proceeding
        if (!awg_check_binaries()) {
            echo "ERROR: awg/awg-quick binaries not found. Install amnezia-tools package.\n";
            break;
        }
        // Bring down existing interfaces first
        $ifcfgOut2 = [];
        exec('/sbin/ifconfig -l', $ifcfgOut2);
        $existing = explode(' ', trim($ifcfgOut2[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                $downOut2 = []; $downRc2 = 0;
                $confPath2 = AWG_CONF_DIR . '/' . $iface . '.conf';
                exec(AWG_QUICK . ' down ' . escapeshellarg($confPath2) . ' 2>&1', $downOut2, $downRc2);
                awg_log('pre-down ' . $iface . ' rc=' . $downRc2 . ' | ' . implode(' | ', $downOut2));
            }
        }
        // Remove stale PID if no instances will be brought up
        $instances = awg_get_instances();
        if (empty($instances) && file_exists(AWG_PID_FILE)) {
            unlink(AWG_PID_FILE);
        }
        // Bring up configured instances
        foreach ($instances as $inst) {
            awg_up($inst);
        }
        echo "OK\n";
        break;

    case 'stop':
        $ifcfgOut3 = [];
        exec('/sbin/ifconfig -l', $ifcfgOut3);
        $existing = explode(' ', trim($ifcfgOut3[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                $downOut3 = []; $downRc3 = 0;
                $confPath3 = AWG_CONF_DIR . '/' . $iface . '.conf';
                exec(AWG_QUICK . ' down ' . escapeshellarg($confPath3) . ' 2>&1', $downOut3, $downRc3);
                awg_log('stop down ' . $iface . ' rc=' . $downRc3 . ' | ' . implode(' | ', $downOut3));
            }
        }
        if (file_exists(AWG_PID_FILE)) {
            unlink(AWG_PID_FILE);
        }
        echo "OK\n";
        break;

    case 'restart':
        // Stop phase — bring down all awg interfaces and clear PID
        $ifcfgOut4 = [];
        exec('/sbin/ifconfig -l', $ifcfgOut4);
        $existing = explode(' ', trim($ifcfgOut4[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                $downOut4 = []; $downRc4 = 0;
                $confPath4 = AWG_CONF_DIR . '/' . $iface . '.conf';
                exec(AWG_QUICK . ' down ' . escapeshellarg($confPath4) . ' 2>&1', $downOut4, $downRc4);
                awg_log('restart down ' . $iface . ' rc=' . $downRc4 . ' | ' . implode(' | ', $downOut4));
            }
        }
        // BUG-4 fix: remove PID after stop phase so it reflects correct state
        if (file_exists(AWG_PID_FILE)) {
            unlink(AWG_PID_FILE);
        }
        // Start phase
        foreach (awg_get_instances() as $inst) {
            awg_up($inst);
        }
        echo "OK\n";
        break;

    case 'status':
        $tunnels = [];
        exec('/sbin/ifconfig -l', $out5);
        $existing = explode(' ', trim($out5[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                $awgShow = [];
                exec(AWG_BIN . ' show ' . escapeshellarg($iface) . ' 2>/dev/null', $awgShow);
                $tunnels[] = [
                    'interface' => $iface,
                    'up'        => true,
                    'details'   => implode("\n", $awgShow),
                ];
            }
        }
        $running = !empty($tunnels);
        echo json_encode([
            'status'  => $running ? 'ok' : 'stopped',
            'tunnels' => $tunnels,
        ]) . "\n";
        break;

    case 'gen_keypair':
        $privkey = trim(shell_exec(AWG_BIN . ' genkey'));
        $pubkey  = trim(shell_exec('echo ' . escapeshellarg($privkey) . ' | ' . AWG_BIN . ' pubkey'));
        echo json_encode(['status' => 'ok', 'private_key' => $privkey, 'public_key' => $pubkey]) . "\n";
        break;

    default:
        echo "Unknown action: $action\n";
        exit(1);
}

// IMP-10: release the exclusive lock
if ($lockFp !== null) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
