#!/usr/local/bin/php
<?php

require_once('config.inc');

define('AWG_CONF_DIR', '/usr/local/etc/amnezia');
define('AWG_BIN',      '/usr/local/bin/awg');
define('AWG_QUICK',    '/usr/local/bin/awg-quick');

function awg_get_instances(): array
{
    $config = OPNsense\Core\Config::getInstance()->object();
    if (!isset($config->OPNsense->amneziawg->instances->instance)) {
        return [];
    }

    $instances = [];
    $idx = 0;
    $raw = $config->OPNsense->amneziawg->instances->instance;

    // Single instance vs multiple
    $items = (isset($raw->name)) ? [$raw] : $raw;

    foreach ($items as $inst) {
        if ((string)$inst->enabled !== '1') {
            continue;
        }
        $ifnum = !empty((string)$inst->interface_number) ? (int)(string)$inst->interface_number : $idx;
        $instances[] = [
            'interface'                 => 'awg' . $ifnum,
            'private_key'               => (string)$inst->private_key,
            'address'                   => (string)$inst->address,
            'listen_port'               => (string)$inst->listen_port,
            'dns'                       => (string)$inst->dns,
            'mtu'                       => (string)$inst->mtu,
            'jc'                        => (string)$inst->jc,
            'jmin'                      => (string)$inst->jmin,
            'jmax'                      => (string)$inst->jmax,
            's1'                        => (string)$inst->s1,
            's2'                        => (string)$inst->s2,
            'h1'                        => (string)$inst->h1,
            'h2'                        => (string)$inst->h2,
            'h3'                        => (string)$inst->h3,
            'h4'                        => (string)$inst->h4,
            'peer_public_key'           => (string)$inst->peer_public_key,
            'peer_preshared_key'        => (string)$inst->peer_preshared_key,
            'peer_endpoint'             => (string)$inst->peer_endpoint,
            'peer_allowed_ips'          => (string)$inst->peer_allowed_ips,
            'peer_persistent_keepalive' => (string)$inst->peer_persistent_keepalive,
        ];
        $idx++;
    }
    return $instances;
}

function awg_write_conf(array $inst): string
{
    $lines = ['[Interface]'];
    $lines[] = 'PrivateKey = ' . $inst['private_key'];
    $lines[] = 'Address = '    . $inst['address'];

    if (!empty($inst['listen_port'])) {
        $lines[] = 'ListenPort = ' . $inst['listen_port'];
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

function awg_up(array $inst): void
{
    $path = awg_write_conf($inst);
    exec(AWG_QUICK . ' up ' . escapeshellarg($path) . ' 2>&1');
}

function awg_down(array $inst): void
{
    $path = AWG_CONF_DIR . '/' . $inst['interface'] . '.conf';
    if (file_exists($path)) {
        exec(AWG_QUICK . ' down ' . escapeshellarg($path) . ' 2>&1');
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
        $peers = [];
        exec('/sbin/ifconfig -l', $out5);
        $existing = explode(' ', trim($out5[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                $peers[] = ['interface' => $iface, 'up' => true];
            }
        }
        echo json_encode(['status' => 'ok', 'peers' => $peers]) . "\n";
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
