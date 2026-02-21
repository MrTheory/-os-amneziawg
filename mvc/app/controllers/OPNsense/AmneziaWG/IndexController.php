<?php

namespace OPNsense\AmneziaWG;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->generalForm        = $this->getForm('general');
        $this->view->formDialogInstance = $this->getForm('dialogEditInstance');
        $this->view->formGridInstance   = $this->getFormGrid('dialogEditInstance');
        $this->view->pick('OPNsense/AmneziaWG/general');
    }
}
