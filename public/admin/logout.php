<?php
declare(strict_types=1);
require_once __DIR__ . '/_layout.php';
\Trafic\Auth::logout();
header('Location: ' . admin_url('/login.php'));
