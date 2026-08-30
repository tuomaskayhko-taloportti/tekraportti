<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_SESSION['laatijat_id'])) {
    header('Location: raportit.php');
    exit;
}

$csrfToken = getApplicationCsrfToken();
$message = '';
$messageType = 'info';
$email = trim((string) ($_SESSION['login_email'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $message = 'Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.';
        $messageType = 'danger';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $email = strtolower(trim((string) ($_POST['email'] ?? $email)));

        if ($action === 'request-code') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Anna voimassa oleva sähköpostiosoite.';
                $messageType = 'danger';
            } elseif (isLaatijaLoginBlocked($email)) {
                $message = 'Kirjautuminen estetty, ole yhteydessä ylläpitoon: asiakaspalvelu@taloportti.fi';
                $messageType = 'danger';
            } elseif (requestLoginCode($email)) {
                $_SESSION['login_email'] = $email;
                $message = 'Jos osoite kuuluu järjestelmään, lähetimme siihen kirjautumiskoodin.';
                $messageType = 'success';
            } else {
                $message = 'Kirjautumiskoodin lähetys epäonnistui. Yritä hetken kuluttua uudelleen.';
                $messageType = 'danger';
            }
        } elseif ($action === 'verify-code') {
            $code = trim((string) ($_POST['code'] ?? ''));

            if (verifyLoginCode($email, $code)) {
                unset($_SESSION['login_email']);
                header('Location: raportit.php');
                exit;
            }

            $message = 'Kirjautumiskoodi on virheellinen tai vanhentunut.';
            $messageType = 'danger';
        } elseif ($action === 'logout') {
            logoutLaatija();
            header('Location: index.php');
            exit;
        }
    }
}

$isLoggedIn = isset($_SESSION['laatijat_id']);
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');

openLayoutShell('Kirjautuminen – TEKRAPORTTI');
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6 col-xl-5">
        <div class="card shadow-sm">
            <div class="card-body p-4 p-md-5">
                <h1 class="h3 mb-3">Kirjaudu TEKRAPORTTIIN</h1>
                <p class="text-body-secondary mb-4">
                    Kirjautumiseen käytetään sähköpostiin lähetettävää kertakäyttöistä koodia.
                </p>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>" role="alert">
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($isLoggedIn): ?>
                    <div class="alert alert-success" role="status">
                        Olet kirjautunut sisään.
                    </div>
                    <form method="post" action="index.php">
                        <input type="hidden" name="csrf_token" value="<?= $safeCsrfToken ?>">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn btn-outline-secondary w-100">Kirjaudu ulos</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="index.php" class="mb-4">
                        <input type="hidden" name="csrf_token" value="<?= $safeCsrfToken ?>">
                        <input type="hidden" name="action" value="request-code">
                        <div class="mb-3">
                            <label for="email" class="form-label">Sähköpostiosoite</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= $safeEmail ?>" autocomplete="email" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Lähetä kirjautumiskoodi</button>
                    </form>

                    <?php if ($email !== ''): ?>
                        <hr>
                        <form method="post" action="index.php">
                            <input type="hidden" name="csrf_token" value="<?= $safeCsrfToken ?>">
                            <input type="hidden" name="action" value="verify-code">
                            <input type="hidden" name="email" value="<?= $safeEmail ?>">
                            <div class="mb-3">
                                <label for="code" class="form-label">Kirjautumiskoodi</label>
                                <input type="text" class="form-control" id="code" name="code"
                                       inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6"
                                       autocomplete="one-time-code" required>
                            </div>
                            <button type="submit" class="btn btn-success w-100">Kirjaudu sisään</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
closeLayoutShell();
