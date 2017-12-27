<?php
namespace cli;

use esp\core\db\Yac;

class BaseController extends \esp\core\Controller
{

    /**
     * @param string $tab
     * @return Yac
     */
    protected function yac($tab = 'tmp')
    {
        static $yac = [];
        if (!isset($yac[$tab])) $yac[$tab] = new Yac($tab);
        return $yac[$tab];
    }


}