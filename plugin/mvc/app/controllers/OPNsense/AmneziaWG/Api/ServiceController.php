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

    private function runAction(string $command): array
    {
        $backend = new Backend();
        $output  = trim((string)$backend->configdRun($command));
        $failed  = empty($output)
                || stripos($output, 'ERROR') !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        $general = new \OPNsense\AmneziaWG\General();
        if ((string)$general->enabled !== '1') {
            return [
                'result' => 'ok',
                'status' => 'disabled',
                'output' => 'Service is disabled in General settings',
            ];
        }
        $res = $this->runAction('amneziawg reconfigure');
        $success = ($res['result'] === 'ok')
            && strpos($res['message'], 'OK') !== false;
        return [
            'result' => $success ? 'ok' : 'failed',
            'status' => $success ? 'ok' : 'failed',
            'output' => $res['message'],
        ];
    }

    /**
     * POST /api/amneziawg/service/start
     */
    public function startAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return $this->runAction('amneziawg start');
    }

    /**
     * POST /api/amneziawg/service/stop
     */
    public function stopAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return $this->runAction('amneziawg stop');
    }

    /**
     * POST /api/amneziawg/service/restart
     */
    public function restartAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return $this->runAction('amneziawg restart');
    }

    // statusAction() is inherited from ApiMutableServiceControllerBase.

    /**
     * GET /api/amneziawg/service/version
     */
    public function versionAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg version');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['version' => 'unknown'];
    }

    /**
     * GET /api/amneziawg/service/tunnel_status
     */
    public function tunnelStatusAction()
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
