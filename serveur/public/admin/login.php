<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

admin_session_start();
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $error = admin_login(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''), (string)($_POST['code'] ?? ''));
    if ($error === '') { header('Location: index.php'); exit; }
}
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion · GANDAL admin</title><link rel="icon" href="assets/logo-emblem.png">
<style><?= admin_css() ?> .box{max-width:360px;margin:12vh auto;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:24px}
.box form{display:flex;flex-direction:column;gap:12px}.box h1{font-size:20px;text-align:center}.box .biglogo{display:block;width:96px;height:auto;margin:0 auto 14px;border-radius:16px;border:1px solid var(--line)}</style></head>
<body><div class="box">
  <img class="biglogo" src="assets/logo.jpeg" alt="Logo GANDAL" width="96" height="90">
  <h1>GANDAL, espace admin</h1>
  <?php if ($error): ?><p class="err" role="alert"><?= h($error) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label for="username">Identifiant<input id="username" name="username" required autocomplete="username"></label>
    <label for="password">Mot de passe<input id="password" name="password" type="password" required autocomplete="current-password"></label>
    <label for="code">Code de l'application d'authentification<input id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autocomplete="one-time-code"></label>
    <button type="submit">Se connecter</button>
  </form>
</div></body></html>
