<?php

require_once __DIR__ . '/version.php';
require_once __DIR__ . '/PHPMailer/vendor/autoload.php';

/**
 * Avaa sivun HTML-rakenteen ja näyttää ylänavigaation.
 */
function openLayoutShell(string $pageTitle = 'TEKRAPORTTI'): void
{
    global $layoutHasSidebar;

    startApplicationSession();
    $safePageTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    $laatija = getCurrentLaatija();
    $csrfToken = getApplicationCsrfToken();
    $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
    $safeLaatijaNimi = htmlspecialchars((string) ($laatija['nimi'] ?? ''), ENT_QUOTES, 'UTF-8');
    $layoutHasSidebar = $laatija !== null;

    echo <<<HTML
<!doctype html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safePageTitle}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
          crossorigin="anonymous">
    <style>
        :root { --tekra-sidebar-width: 240px; }
        body { background-color: #f4f7f6; }
        .tekra-topbar { min-height: 52px; background-color: #071a33; }
        .tekra-brand { letter-spacing: .02em; }
        .tekra-brand small { color: #aeb9c9; font-size: .65rem; letter-spacing: .08em; }
        .tekra-sidebar { width: var(--tekra-sidebar-width); }
        .tekra-sidebar .nav-link { color: #4d596b; border-radius: .5rem; margin: .15rem .75rem; }
        .tekra-sidebar .nav-link:hover { background-color: #eef8f3; color: #087f4e; }
        .tekra-sidebar .nav-link.active { background-color: #dff6e9; color: #087f4e; font-weight: 600; }
        .tekra-sidebar .submenu { border-left: 1px solid #d7e9df; margin-left: 1.7rem; padding-left: .25rem; }
        .tekra-sidebar .submenu .nav-link { font-size: .9rem; margin-left: 0; }
        .tekra-main { min-width: 0; }
        @media (min-width: 992px) {
            .tekra-sidebar { position: sticky; top: 0; height: calc(100vh - 52px); }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark tekra-topbar" aria-label="Päänavigaatio">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#paavalikko" aria-controls="paavalikko"
                    aria-expanded="false" aria-label="Avaa sivupalkki">
                <span class="navbar-toggler-icon"></span>
            </button>
            <a class="navbar-brand tekra-brand ms-2 me-4" href="#">TEKRAPORTTI <small>RAPORTOINTI</small></a>
HTML;

    if ($laatija !== null) {
        $details = [
            'Sähköposti' => $laatija['sahkoposti'] ?? '',
            'Puhelin' => $laatija['puhelin'] ?? '',
            'Yritys' => $laatija['yritys'] ?? '',
            'Y-tunnus' => $laatija['ytunnus'] ?? '',
            'Osoite' => trim(implode(' ', array_filter([
                $laatija['osoite'] ?? '',
                $laatija['postinro'] ?? '',
                $laatija['postitmp'] ?? '',
            ]))),
        ];

        echo '<div class="dropdown ms-auto">';
        echo '<button class="btn btn-dark dropdown-toggle" type="button" data-bs-toggle="dropdown" '
            . 'aria-expanded="false">' . $safeLaatijaNimi . '</button>';
        echo '<div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 280px;">';
        echo '<h6 class="dropdown-header px-0">Omat tiedot</h6>';
        foreach ($details as $label => $value) {
            if ((string) $value === '') {
                continue;
            }
            echo '<div class="small mb-1"><span class="text-muted">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</span> '
                . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        echo '<hr class="dropdown-divider">';
        echo '<form method="post" action="index.php" class="mb-0">';
        echo '<input type="hidden" name="csrf_token" value="' . $safeCsrfToken . '">';
        echo '<input type="hidden" name="action" value="logout">';
        echo '<button type="submit" class="dropdown-item text-danger">Kirjaudu ulos</button>';
        echo '</form></div></div>';
    }

    echo <<<HTML
        </div>
    </nav>

HTML;

    if ($layoutHasSidebar) {
        echo <<<HTML
    <div class="d-flex align-items-stretch">
        <aside class="collapse bg-white border-end tekra-sidebar" tabindex="-1"
               id="paavalikko" aria-label="Sivupalkin valikko">
            <div class="d-flex justify-content-between align-items-center border-bottom p-3">
                <h2 class="h6 mb-0">Valikko</h2>
            </div>
            <div class="p-2 p-lg-3">
                <nav>
                    <a class="nav-link" href="raportit.php">▣ &nbsp; Raportit</a>
                    <div class="submenu">
                        <a class="nav-link" href="luo_raportti.php">Uusi raportti</a>
                    </div>
                    <a class="nav-link" href="projektit.php">▤ &nbsp; Projektit</a>
                    <a class="nav-link" href="asiakkaat.php">▦ &nbsp; Asiakkaat</a>
                </nav>
            </div>
        </aside>
        <div class="flex-grow-1 tekra-main">
            <main class="container-fluid py-4">
HTML;
    } else {
        echo '<main class="container py-4">';
    }
}

/**
 * Sulkee sivun HTML-rakenteen ja näyttää version alatunnisteessa.
 */
function closeLayoutShell(): void
{
    global $layoutHasSidebar;

    echo $layoutHasSidebar ? "</main>\n</div>\n</div>\n" : "</main>\n";
    echo '<footer class="container-fluid text-start pb-3 text-muted small">TEKRAPORTTI v'
        . htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8')
        . "</footer>\n";
    echo "<script src=\"https://code.jquery.com/jquery-4.0.0.min.js\"></script>\n";
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" '
        . 'integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" '
        . "crossorigin=\"anonymous\"></script>\n";
    echo '</body></html>';
}

/**
 * Lukee sovelluksen salaiset asetukset ../secret.php-tiedostosta.
 */
function getApplicationSecrets(): array
{
    require dirname(__DIR__) . '/secret.php';

    return [
        'mysqluser' => $mysqluser,
        'database' => $database,
        'passwd' => $passwd,
        'mailServer' => $mailServer ?? '',
        'mailUser' => $mailUser ?? '',
        'mailPWD' => $mailPWD ?? '',
        'mailPort' => $mailPort ?? 587,
        'mailEncryption' => $mailEncryption ?? 'tls',
    ];
}

/**
 * Avaa yhteyden sovelluksen tietokantaan ../secret.php-tiedoston tunnuksilla.
 */
function getDatabaseConnection(): mysqli
{
    static $connection;

    if ($connection instanceof mysqli) {
        return $connection;
    }

    $secrets = getApplicationSecrets();

    $connection = new mysqli(
        'localhost',
        $secrets['mysqluser'],
        $secrets['passwd'],
        $secrets['database']
    );
    $connection->set_charset('utf8mb4');

    return $connection;
}

/**
 * Käynnistää turvallisen PHP-session tarvittaessa.
 */
function startApplicationSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}

/**
 * Palauttaa istunnon CSRF-tunnisteen ja luo sen tarvittaessa.
 */
function getApplicationCsrfToken(): string
{
    startApplicationSession();

    if (empty($_SESSION['login_csrf'])) {
        $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['login_csrf'];
}

/**
 * Hakee kirjautuneen laatijan tiedot navigaatiota varten.
 */
function getCurrentLaatija(): ?array
{
    startApplicationSession();

    if (!isset($_SESSION['laatijat_id'])) {
        return null;
    }

    try {
        $query = getDatabaseConnection()->prepare(
            'SELECT nimi, sahkoposti, puhelin, yritys, ytunnus, osoite, postinro, postitmp
             FROM laatijat WHERE id = ? LIMIT 1'
        );
        $laatijatId = (int) $_SESSION['laatijat_id'];
        $query->bind_param('i', $laatijatId);
        $query->execute();
        $laatija = $query->get_result()->fetch_assoc() ?: null;
        $query->close();

        return $laatija;
    } catch (Throwable $error) {
        error_log('Kirjautuneen laatijan tietojen haku epäonnistui: ' . $error->getMessage());
        return null;
    }
}

/**
 * Tallentaa ladatun kuvan tai videon Tiedostot-hakemistoon ja palauttaa URL-polun.
 */
function storeUploadedFile(array $file, string $folder): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Tiedoston lataus epäonnistui.');
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('Tiedosto on liian suuri tai virheellinen. Enimmäiskoko on 10 MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Tiedostomuotoa ei tueta.');
    }

    $folder = preg_replace('/[^a-z0-9_-]/i', '', $folder);
    $directory = __DIR__ . '/Tiedostot/' . $folder;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Tiedostokansiota ei voitu luoda.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Tiedostoa ei voitu tallentaa.');
    }

    return 'Tiedostot/' . $folder . '/' . $filename;
}

/**
 * Poistaa palvelun oman ladatun tiedoston. Ulkopuolisiin tai hakemiston
 * ulkopuolisiin polkuihin ei kosketa.
 */
function deleteStoredFile(string $url): void
{
    if ($url === '' || str_contains($url, '..') || preg_match('/^https?:\/\//i', $url)) {
        return;
    }

    $directory = realpath(__DIR__ . '/Tiedostot');
    $path = realpath(__DIR__ . '/' . ltrim($url, '/'));
    if ($directory && $path && str_starts_with($path, $directory . DIRECTORY_SEPARATOR) && is_file($path)) {
        @unlink($path);
    }
}

/**
 * Lähettää kirjautumiskoodin laatijalle.
 *
 * Paluuarvo on true myös silloin, kun sähköpostiosoitetta ei löydy. Näin
 * kirjautumisrajapinta ei paljasta, mitkä osoitteet ovat järjestelmässä.
 */
function requestLoginCode(string $email): bool
{
    $email = strtolower(trim($email));
    $transactionStarted = false;
    $mailer = null;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        $db = getDatabaseConnection();
        $db->begin_transaction();
        $transactionStarted = true;

        $query = $db->prepare(
            'SELECT id FROM laatijat WHERE sahkoposti = ? LIMIT 1'
        );
        $query->bind_param('s', $email);
        $query->execute();
        $laatija = $query->get_result()->fetch_assoc();
        $query->close();

        if (!$laatija) {
            $db->commit();
            $transactionStarted = false;
            return true;
        }

        $laatijatId = (int) $laatija['id'];

        // Uuden koodin pyytämistä rajoitetaan yhteen minuuttiin.
        $query = $db->prepare(
            'SELECT id FROM kirjautumiskoodit
             WHERE laatijat_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
             ORDER BY id DESC LIMIT 1'
        );
        $query->bind_param('i', $laatijatId);
        $query->execute();
        $recentCode = $query->get_result()->fetch_assoc();
        $query->close();

        if ($recentCode) {
            $db->commit();
            $transactionStarted = false;
            return true;
        }

        $code = (string) random_int(100000, 999999);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);

        // Aiemmat käyttämättömät koodit mitätöidään uuden pyynnön yhteydessä.
        $query = $db->prepare(
            'UPDATE kirjautumiskoodit SET used_at = NOW()
             WHERE laatijat_id = ? AND used_at IS NULL'
        );
        $query->bind_param('i', $laatijatId);
        $query->execute();
        $query->close();

        $query = $db->prepare(
            'INSERT INTO kirjautumiskoodit
             (laatijat_id, code_hash, expires_at, yritykset)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE), 0)'
        );
        $query->bind_param('is', $laatijatId, $codeHash);
        $query->execute();
        $query->close();
        $subject = 'TEKRAPORTTI kirjautumiskoodi';
        $message = "Kirjautumiskoodisi on {$code}. Koodi on voimassa 10 minuuttia.\n\n"
            . 'Jos et pyytänyt koodia, voit jättää tämän viestin huomiotta.';
        $mailSettings = getApplicationSecrets();
        $mailUser = trim((string) $mailSettings['mailUser']);
        $sender = filter_var($mailUser, FILTER_VALIDATE_EMAIL)
            ? $mailUser
            : '';

        $mailServer = trim((string) $mailSettings['mailServer']);
        $mailPassword = (string) $mailSettings['mailPWD'];
        $mailPort = filter_var($mailSettings['mailPort'], FILTER_VALIDATE_INT);
        $mailEncryption = strtolower(trim((string) $mailSettings['mailEncryption']));

        if ($mailServer === '' || $sender === '' || $mailPassword === '') {
            throw new RuntimeException('Sähköpostin SMTP-asetukset puuttuvat.');
        }

        if ($mailPort === false || $mailPort < 1 || $mailPort > 65535) {
            throw new RuntimeException('Sähköpostin SMTP-portti on virheellinen.');
        }

        if ($mailEncryption === 'ssl' || $mailEncryption === 'smtps') {
            $smtpSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($mailEncryption === 'tls' || $mailEncryption === 'starttls') {
            $smtpSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($mailEncryption === 'none' || $mailEncryption === '') {
            $smtpSecure = '';
        } else {
            throw new RuntimeException('Sähköpostin SMTP-salaus on virheellinen.');
        }

        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $mailServer;
        $mailer->SMTPAuth = true;
        $mailer->Username = $sender;
        $mailer->Password = $mailPassword;
        $mailer->Port = $mailPort;
        $mailer->SMTPSecure = $smtpSecure;
        $mailer->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($sender, 'TEKRAPORTTI');
        $mailer->addAddress($email);
        $mailer->Subject = $subject;
        $mailer->Body = $message;
        $mailer->isHTML(false);

        if (!$mailer->send()) {
            throw new RuntimeException('PHPMailer ei vahvistanut sähköpostin lähetystä.');
        }

        $db->commit();
        $transactionStarted = false;
        return true;
    } catch (Throwable $error) {
        if ($transactionStarted && isset($db) && $db instanceof mysqli) {
            $db->rollback();
        }
        $mailError = $mailer instanceof \PHPMailer\PHPMailer\PHPMailer
            ? $mailer->ErrorInfo
            : '';
        error_log('Kirjautumiskoodin lähetys epäonnistui: ' . $error->getMessage()
            . ($mailError !== '' ? ' | PHPMailer: ' . $mailError : ''));
        return false;
    }
}

/**
 * Tarkistaa sähköpostiosoitteen ja kuusinumeroisen kirjautumiskoodin.
 */
function verifyLoginCode(string $email, string $code): bool
{
    $email = strtolower(trim($email));
    $code = trim($code);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/', $code)) {
        return false;
    }

    try {
        $db = getDatabaseConnection();
        $db->begin_transaction();

        $query = $db->prepare(
            'SELECT k.id, k.laatijat_id, k.code_hash
             FROM kirjautumiskoodit k
             INNER JOIN laatijat l ON l.id = k.laatijat_id
             WHERE l.sahkoposti = ?
               AND k.used_at IS NULL
               AND k.expires_at > NOW()
               AND k.yritykset < 5
             ORDER BY k.id DESC LIMIT 1 FOR UPDATE'
        );
        $query->bind_param('s', $email);
        $query->execute();
        $loginCode = $query->get_result()->fetch_assoc();
        $query->close();

        if (!$loginCode) {
            $db->commit();
            return false;
        }

        $codeId = (int) $loginCode['id'];
        $query = $db->prepare(
            'UPDATE kirjautumiskoodit SET yritykset = yritykset + 1 WHERE id = ?'
        );
        $query->bind_param('i', $codeId);
        $query->execute();
        $query->close();

        if (!password_verify($code, $loginCode['code_hash'])) {
            $db->commit();
            return false;
        }

        $query = $db->prepare(
            'UPDATE kirjautumiskoodit SET used_at = NOW() WHERE id = ?'
        );
        $query->bind_param('i', $codeId);
        $query->execute();
        $query->close();
        $db->commit();

        startApplicationSession();
        session_regenerate_id(true);
        $_SESSION['laatijat_id'] = (int) $loginCode['laatijat_id'];

        return true;
    } catch (Throwable $error) {
        if (isset($db) && $db instanceof mysqli) {
            $db->rollback();
        }
        error_log('Kirjautumiskoodin tarkistus epäonnistui: ' . $error->getMessage());
        return false;
    }
}

/**
 * Kirjaa nykyisen laatijan ulos.
 */
function logoutLaatija(): void
{
    startApplicationSession();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'],
            (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}
