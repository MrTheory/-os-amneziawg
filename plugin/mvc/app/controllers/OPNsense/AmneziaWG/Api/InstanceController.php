<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\AmneziaWG\Instance';
    protected static $internalModelName  = 'instance';

    public function genKeyPairAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg gen_keypair');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['private_key'])) {
            return $decoded;
        }
        return ['status' => 'error', 'message' => 'Failed to generate key pair'];
    }
}
