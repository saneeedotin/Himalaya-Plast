<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

(require HPOS_ROOT . '/app/routes.php')->dispatch();
