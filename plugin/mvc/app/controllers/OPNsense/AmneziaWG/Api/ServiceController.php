<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass    = '\OPNsense\AmneziaWG\General';
    protected static $internalServiceTemplate = 'OPNsense/AmneziaWG';
    protected static $internalServiceEnabled  = 'enabled';
    protected static $internalServiceName     = 'amneziawg';

    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        // IMP-7 fix: check general.enabled before running reconfigure
        $general = new \OPNsense\AmneziaWG\General();
        if ((string)$general->enabled !== '1') {
            return [
                'result' => 'ok',
                'status' => 'disabled',
                'output' => 'Service is disabled in General settings',
            ];
        }
        $backend = new Backend();
        $output  = trim($backend->configdRun('amneziawg reconfigure'));
        // BUG-12 FIX: robust status check (matches os-xray pattern)
        $success = !empty($output)
            && strpos($output, 'OK') !== false
            && stripos($output, 'ERROR') === false
            && stripos($output, 'failed') === false;
        return [
            'result' => $success ? 'ok' : 'failed',
            'status' => $success ? 'ok' : 'failed',
            'output' => $output,
        ];
    }

    public function statusAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg status');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['status' => 'error', 'message' => $result];
    }
}
