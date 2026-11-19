<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/auth.php';
require dirname(__DIR__, 2) . '/src/admin_ui.php';

// Statuts qui correspondent à de l'argent encaissé
const PAID_STATUSES = "('paid','complete','paid_no_ticket')";
