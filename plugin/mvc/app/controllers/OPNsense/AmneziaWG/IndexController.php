<?php

namespace OPNsense\AmneziaWG;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->generalForm  = $this->getForm('general');
        $this->view->instanceForm = $this->getForm('instance');
        $this->view->pick('OPNsense/AmneziaWG/general');
    }
}
