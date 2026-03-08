#!/usr/local/bin/php
<?php

// AmneziaWG connection test — checks connectivity through the tunnel
// Called via: configctl amneziawg testconnect

require_once('/usr/local/etc/inc/config.inc');

/**
 * Get interface name from config.xml
 */
function awg_get_interface_name(): string
{
    $config = OPNsense\Core\Config::getInstance()->object();
    $inst = $config->OPNsense->amneziawg->instance ?? null;
    if (!isset($inst)) {
        return 'awg0';
    }
    $ifnum = !empty((string)($inst->interface_number ?? '')) ? (int)(string)$inst->interface_number : 0;
    return 'awg' . $ifnum;
}

$iface = awg_get_interface_name();

// Check interface exists
$out = [];
exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
if ($rc !== 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Interface ' . $iface . ' does not exist',
    ]) . "\n";
    exit(1);
}

// Test HTTP connectivity through the tunnel interface
// Using Cloudflare's connectivity check endpoint (returns 204)
$cmd = '/usr/local/bin/curl'
    . ' --interface ' . escapeshellarg($iface)
    . ' -s -o /dev/null'
    . ' -w "%{http_code}"'
    . ' --connect-timeout 5'
    . ' --max-time 10'
    . ' http://cp.cloudflare.com/generate_204'
    . ' 2>/dev/null';

$httpCode = trim(shell_exec($cmd) ?? '');

if ($httpCode === '204' || $httpCode === '200') {
    echo json_encode([
        'status' => 'ok',
        'http_code' => (int)$httpCode,
        'message' => 'Connection through ' . $iface . ' is working',
    ]) . "\n";
} elseif ($httpCode !== '') {
    echo json_encode([
        'status' => 'error',
        'http_code' => (int)$httpCode,
        'message' => 'Unexpected HTTP code ' . $httpCode . ' (expected 204)',
    ]) . "\n";
    exit(1);
} else {
    echo json_encode([
        'status' => 'error',
        'http_code' => 0,
        'message' => 'Connection failed — no response through ' . $iface,
    ]) . "\n";
    exit(1);
}
