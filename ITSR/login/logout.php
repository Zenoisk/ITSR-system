<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$portal = itsrPortalContext();
destroyCurrentSession();

header('Location: login.php?portal=' . rawurlencode($portal));
exit;
