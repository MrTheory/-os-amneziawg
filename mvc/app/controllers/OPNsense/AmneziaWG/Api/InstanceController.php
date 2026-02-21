<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\AmneziaWG\Instance';
    protected static $internalModelName  = 'instance';

    public function searchInstanceAction()
    {
        return $this->searchBase(
            'instance',
            ['enabled', 'name', 'description', 'address', 'peer_endpoint', 'peer_allowed_ips']
        );
    }

    public function getInstanceAction($uuid = null)
    {
        return $this->getBase('instance', 'instance', $uuid);
    }

    public function addInstanceAction()
    {
        return $this->addBase('instance', 'instance');
    }

    public function setInstanceAction($uuid = null)
    {
        return $this->setBase('instance', 'instance', $uuid);
    }

    public function delInstanceAction($uuid = null)
    {
        return $this->delBase('instance', $uuid);
    }

    public function toggleInstanceAction($uuid = null, $enabled = null)
    {
        return $this->toggleBase('instance', $uuid, $enabled);
    }

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
