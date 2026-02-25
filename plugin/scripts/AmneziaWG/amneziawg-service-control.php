#!/usr/local/bin/php
<?php

require_once('config.inc');

define('AWG_CONF_DIR', '/usr/local/etc/amnezia');
define('AWG_BIN',      '/usr/local/bin/awg');
define('AWG_QUICK',    '/usr/local/bin/awg-quick');
define('AWG_PID_FILE', '/var/run/amneziawg.pid');

function awg_get_instances(): array
{
    $config = OPNsense\Core\Config::getInstance()->object();
    $inst = $config->OPNsense->amneziawg->instance ?? null;
    if (!isset($inst) || (string)($inst->enabled ?? '0') !== '1') {
        return [];
    }
    $ifnum = !empty((string)($inst->interface_number ?? '')) ? (int)(string)$inst->interface_number : 0;
    return [[
        'interface'                 => 'awg' . $ifnum,
        'private_key'               => (string)($inst->private_key               ?? ''),
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

function awg_write_conf(array $inst): string
{
    $lines = ['[Interface]'];
    $lines[] = 'PrivateKey = ' . $inst['private_key'];
    $lines[] = 'Address = '    . $inst['address'];
    $lines[] = 'Table = off';

    if (!empty($inst['listen_port'])) {
        $lines[] = 'ListenPort = ' . $inst['listen_port'];
    }
    if (!empty($inst['dns'])) {
        $lines[] = 'DNS = ' . $inst['dns'];
    }
    if (!empty($inst['mtu'])) {
        $lines[] = 'MTU = ' . $inst['mtu'];
    }
    // Obfuscation parameters
    $obf = ['jc'=>'Jc','jmin'=>'Jmin','jmax'=>'Jmax','s1'=>'S1','s2'=>'S2',
            'h1'=>'H1','h2'=>'H2','h3'=>'H3','h4'=>'H4'];
    foreach ($obf as $k => $label) {
        if (!empty($inst[$k])) {
            $lines[] = "$label = " . $inst[$k];
        }
    }

    $lines[] = '';
    $lines[] = '[Peer]';
    $lines[] = 'PublicKey = ' . $inst['peer_public_key'];
    if (!empty($inst['peer_preshared_key'])) {
        $lines[] = 'PresharedKey = ' . $inst['peer_preshared_key'];
    }
    $lines[] = 'Endpoint = '    . $inst['peer_endpoint'];
    $lines[] = 'AllowedIPs = '  . $inst['peer_allowed_ips'];
    if (!empty($inst['peer_persistent_keepalive'])) {
        $lines[] = 'PersistentKeepalive = ' . $inst['peer_persistent_keepalive'];
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

function awg_is_up(string $iface): bool
{
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

$action = $argv[1] ?? 'status';

switch ($action) {
    case 'start':
    case 'reconfigure':
        // Bring down existing interfaces first
        exec('/sbin/ifconfig -l', $out2);
        $existing = explode(' ', trim($out2[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                exec(AWG_QUICK . ' down ' . AWG_CONF_DIR . '/' . $iface . '.conf 2>/dev/null');
            }
        }
        // Bring up configured instances
        foreach (awg_get_instances() as $inst) {
            awg_up($inst);
        }
        echo "OK\n";
        break;

    case 'stop':
        exec('/sbin/ifconfig -l', $out3);
        $existing = explode(' ', trim($out3[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                exec(AWG_QUICK . ' down ' . AWG_CONF_DIR . '/' . $iface . '.conf 2>/dev/null');
            }
        }
        if (file_exists(AWG_PID_FILE)) {
            unlink(AWG_PID_FILE);
        }
        echo "OK\n";
        break;

    case 'restart':
        // Stop
        exec('/sbin/ifconfig -l', $out4);
        $existing = explode(' ', trim($out4[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                exec(AWG_QUICK . ' down ' . AWG_CONF_DIR . '/' . $iface . '.conf 2>/dev/null');
            }
        }
        // Start
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
