<?php
declare(strict_types=1);
require __DIR__ . '/inc/start.php';
sitzung();

if (isset($_GET['abmelden'])) { $_SESSION = []; session_destroy(); header('Location: login.php'); exit; }
$fehler = '';
$redirect = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/login.php?ms=zurueck';
$ziel = (string)($_GET['weiter'] ?? $_SESSION['weiter'] ?? './');
if (!preg_match('#^[a-z0-9_./?=&%-]*$#i', $ziel) || str_contains($ziel, '//')) $ziel = './';

$anmelden = function (array $b) use ($ziel) {
    session_regenerate_id(true); $_SESSION['uid'] = (int)$b['id']; unset($_SESSION['weiter']);
    header('Location: ' . $ziel); exit;
};

// Microsoft
if (!empty(ms()['login_aktiv'])) {
    if (isset($_GET['ms']) && $_GET['ms'] === 'start') { $_SESSION['weiter'] = $ziel; header('Location: ' . ms_login_url($redirect)); exit; }
    if (isset($_GET['ms']) && $_GET['ms'] === 'zurueck') {
        try {
            $mail = ms_login_abschliessen($redirect);
            $b = eine('SELECT * FROM benutzer WHERE LOWER(email) = ? AND aktiv = 1', [$mail]);
            if ($b) $anmelden($b);
            $fehler = "Ihr Microsoft-Konto ($mail) ist noch nicht freigeschaltet. Bitte an den Admin wenden.";
        } catch (Throwable $e) { $fehler = $e->getMessage(); }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = eine('SELECT * FROM benutzer WHERE LOWER(login) = ? AND aktiv = 1', [mb_strtolower(trim((string)($_POST['login'] ?? '')))]);
    if ($b && $b['pw_hash'] && password_verify((string)($_POST['passwort'] ?? ''), $b['pw_hash'])) $anmelden($b);
    usleep(700000); $fehler = 'Anmeldename oder Passwort stimmt nicht.';
}
if (benutzer()) { header('Location: ./'); exit; }

seite_kopf('Anmelden');
?>
<form class="box login" method="post">
  <h1>Anmelden</h1>
  <?php if (!empty(ms()['login_aktiv'])): ?>
    <a class="btn voll" href="login.php?ms=start&amp;weiter=<?= h(rawurlencode($ziel)) ?>">Mit Microsoft anmelden</a>
    <p class="hint" style="text-align:center">oder mit Anmeldename und Passwort</p>
  <?php endif; ?>
  <label class="f">Anmeldename<input type="text" name="login" id="login" autocomplete="username" autofocus required></label>
  <label class="f">Passwort<input type="password" name="passwort" id="passwort" autocomplete="current-password" required></label>
  <?php if ($fehler): ?><div class="msg err"><?= h($fehler) ?></div><?php endif; ?>
  <button class="btn voll" type="submit">Anmelden</button>
  <?php if (cfg('testbetrieb')): ?>
    <div class="msg info">Testbetrieb – Passwort jeweils <b>test</b>:<br>
      <b>buero</b> (Pflege + Organisation), <b>chef</b> (Auswertung + Organisation), <b>admin</b> (alles),<br>
      Techniker: <?= h(implode(', ', array_map(fn($m) => mb_strtolower($m['name']), mitarbeiter_liste()))) ?></div>
  <?php endif; ?>
</form>
<?php seite_fuss();
