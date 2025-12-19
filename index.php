<?php

declare(strict_types=1);

$frontendPath = '/Frontend/index.html';
header('Location: ' . $frontendPath, true, 302);
exit;
