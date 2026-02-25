<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiControllerBase;

class ImportController extends ApiControllerBase
{
    public function parseAction()
    {
        $rawConfig = '';
        if ($this->request->isPost()) {
            $rawConfig = $this->request->getPost('config', 'string', '');
        } else {
            $body      = json_decode(file_get_contents('php://input'), true);
            $rawConfig = $body['config'] ?? '';
        }

        if (empty(trim($rawConfig))) {
            return ['status' => 'error', 'message' => 'No configuration provided'];
        }

        $data           = $this->parseConf($rawConfig);
        $data['status'] = 'ok';
        return $data;
    }

    private function parseConf(string $raw): array
    {
        $data = [
            'private_key' => '', 'address' => '', 'dns' => '', 'mtu' => '',
            'jc' => '', 'jmin' => '', 'jmax' => '',
            's1' => '', 's2' => '',
            'h1' => '', 'h2' => '', 'h3' => '', 'h4' => '',
            'peer_public_key' => '', 'peer_preshared_key' => '',
            'peer_endpoint' => '', 'peer_allowed_ips' => '',
            'peer_persistent_keepalive' => '',
        ];

        $section = '';
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line === '[Interface]') {
                $section = 'interface';
                continue;
            }
            if ($line === '[Peer]') {
                $section = 'peer';
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $k = strtolower($key);

            if ($section === 'interface') {
                $map = [
                    'privatekey' => 'private_key', 'address' => 'address',
                    'dns' => 'dns', 'mtu' => 'mtu',
                    'jc' => 'jc', 'jmin' => 'jmin', 'jmax' => 'jmax',
                    's1' => 's1', 's2' => 's2',
                    'h1' => 'h1', 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h4',
                ];
                if (isset($map[$k])) {
                    $data[$map[$k]] = $value;
                }
            } elseif ($section === 'peer') {
                $map = [
                    'publickey'           => 'peer_public_key',
                    'presharedkey'        => 'peer_preshared_key',
                    'endpoint'            => 'peer_endpoint',
                    'allowedips'          => 'peer_allowed_ips',
                    'persistentkeepalive' => 'peer_persistent_keepalive',
                ];
                if (isset($map[$k])) {
                    $data[$map[$k]] = $value;
                }
            }
        }
        return $data;
    }
}
