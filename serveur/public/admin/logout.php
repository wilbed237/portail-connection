<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
admin_session_start();
if (!empty($_SESSION['user'])) { audit($_SESSION['user'], 'déconnexion'); }
$_SESSION = [];
session_destroy();
header('Location: login.php');
