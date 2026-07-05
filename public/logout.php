<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_logout();
portal_redirect('login.php?logged_out=1');
