<?php

namespace Opencart\Admin\Model\Extension\Suawave\Payment;

require_once DIR_EXTENSION . 'suawave/system/library/suawave/autoload.php';

use Suawave\Schema;

class Suawave extends \Opencart\System\Engine\Model
{
    public function install(): void
    {
        (new Schema($this->db, DB_PREFIX))->install();
    }

    public function uninstall(): void
    {
    }
}
