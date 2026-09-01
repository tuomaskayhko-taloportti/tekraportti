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
    ob_start();
    $safePageTitle = htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8');
    $laatija = getCurrentLaatija();
    $csrfToken = getApplicationCsrfToken();
    $safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
    $safeLaatijaNimi = htmlspecialchars((string) ($laatija['nimi'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeSearch = htmlspecialchars((string) ($_GET['haku'] ?? ''), ENT_QUOTES, 'UTF-8');
    $currentPath = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    $adminNavLink = ((int) ($laatija['id'] ?? 0) === 1)
        ? '<a class="nav-link' . ($currentPath === 'yllapito.php' ? ' active' : '') . '" style="display:block!important;width:auto;color:#52627a;text-decoration:none!important;border-radius:8px;margin:.35rem .7rem;padding:14px 18px;font-size:1.05rem;" href="yllapito.php"><svg class="tekra-menu-icon" xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="m14.5 6.5 3-3a4 4 0 0 0-5.7-.1l-1.1 1.1 3 3z"/><path d="m3 21 9.5-9.5"/><path d="m3 5 5 5"/><path d="m5 3 5 5"/><path d="M3 5 5 3l5 5-2 2z"/><path d="m14 14 5 5"/><path d="m16 12 5 5"/></svg> Ylläpito</a>'
        : '';
    $layoutHasSidebar = $laatija !== null;
    $reportsNavClass = in_array($currentPath, ['raportit.php', 'luo_raportti.php', 'muokkaa_raportti.php', 'esikatsele_raportti.php'], true) ? ' active' : '';
    $reportsNavOpen = $reportsNavClass !== '' ? ' open' : '';
    $projectsNavClass = $currentPath === 'projektit.php' ? ' active' : '';
    $customersNavClass = $currentPath === 'asiakkaat.php' ? ' active' : '';

    echo <<<HTML
<!doctype html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safePageTitle}</title>
    <link rel="icon" type="image/png" href="Logo pyöreä.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
          crossorigin="anonymous">
    <style>
        :root { --tekra-sidebar-width: 273px; --tekra-navy: #071a3d; --tekra-blue: #1769e8; }
        body { background: #f5f8fc; color: #152747; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .tekra-markdown-toolbar { display:flex; gap:.25rem; margin-bottom:.35rem; }
        .tekra-markdown-toolbar button { min-width:2.1rem; }
        .tekra-markdown-help { color:#6c757d; font-size:.8rem; margin-left:.35rem; align-self:center; }
        .tekra-rich-text h4 { margin:.7rem 0 .35rem; font-size:1.05em; }
        .tekra-rich-text p { margin:0 0 .45rem; }
        .tekra-rich-text p:last-child { margin-bottom:0; }
        .tekra-topbar { min-height: 100px; background: linear-gradient(110deg, #f7fafc, #e5edf6); box-shadow: 0 1px 0 #d7e1ec; }
        .tekra-topbar .container-fluid { min-height: 100px; padding: 0 20px; gap: 16px; }
        .tekra-topbar .navbar-toggler { border: 1px solid #b7c7d9; border-radius: 8px; padding: 10px 14px; }
        .tekra-brand { display: inline-flex; align-items: center; }
        .tekra-brand img { display: block; width: 220px; height: auto; max-height: 62px; object-fit: contain; }
        .tekra-search { width: min(775px, 48vw); margin-left: 34px; }
        .tekra-search .form-control { height: 62px; color: #152747; background: rgba(255,255,255,.8); border: 1px solid #c5d3e2; border-radius: 9px; padding-left: 62px; }
        .tekra-search .form-control::placeholder { color: #63748a; }
        .tekra-search-icon { position: absolute; left: 21px; top: 17px; color: #526a86; font-size: 1.5rem; }
        .tekra-search-shortcut { position: absolute; right: 14px; top: 17px; border: 1px solid #aabbd0; border-radius: 6px; color: #526a86; padding: 3px 8px; font-size: .8rem; }
        .tekra-user-menu { color: #152747; display: flex; align-items: center; gap: 10px; font-weight: 600; }
        .tekra-user-menu:hover, .tekra-user-menu:focus { color: #071a3d; }
        .tekra-avatar { width: 46px; height: 46px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; background: #f1f4f8; color: #55708f; font-weight: 600; }
        .tekra-sidebar { width: var(--tekra-sidebar-width); background: #fff !important; border-right-color: #e2e8f1 !important; }
        .tekra-sidebar .nav-link { display: block !important; width: auto; color: #52627a; text-decoration: none !important; border-radius: 8px; margin: .35rem .7rem; padding: 14px 18px; font-size: 1.05rem; }
        .tekra-sidebar .nav-link:hover { background-color: #edf4ff; color: var(--tekra-blue); }
        .tekra-sidebar .nav-link.active { background-color: #e9f1ff; color: var(--tekra-blue); font-weight: 600; }
        .tekra-sidebar.is-collapsed { display: none !important; }
        .tekra-sidebar nav, .tekra-sidebar .submenu { display: block !important; border-left: 0; margin-left: 0; padding-left: 0; }
        .tekra-reports-menu summary { cursor: pointer; list-style-position: inside; color: #52627a; }
        .tekra-reports-menu summary::marker { color: #52627a; }
        .tekra-reports-menu summary .nav-link { display: inline-block !important; width: calc(100% - 1.5rem); margin-left: 0; vertical-align: middle; }
        .tekra-sidebar .submenu .nav-link { font-size: .98rem; padding-top: 9px; padding-bottom: 9px; margin-top: 0; }
        .tekra-sidebar-title { padding: 30px 28px 18px; font-size: 1.05rem; font-weight: 600; }
        .tekra-menu-icon { display: inline-block; vertical-align: -.22em; margin-right: .55rem; }
        .tekra-main { min-width: 0; }
        .tekra-main > main { padding: 54px 30px !important; }
        .table > :not(caption) > * > * { padding: 20px 28px; }
        .card { border-color: #e1e8f2 !important; border-radius: 9px !important; box-shadow: 0 5px 14px rgba(26, 52, 86, .06) !important; }
        .btn-primary { background-color: var(--tekra-blue); border-color: var(--tekra-blue); }
        .btn-outline-primary { color: var(--tekra-blue); border-color: #a9c7fb; }
        .tekra-main { min-width: 0; }
        @media (min-width: 992px) {
            .tekra-sidebar { display: block !important; position: sticky; top: 0; height: calc(100vh - 100px); }
        }
        @media (max-width: 991.98px) {
            .tekra-search { display: none; }
            .tekra-topbar .container-fluid { min-height: 72px; }
            .tekra-topbar { min-height: 72px; }
            .tekra-brand img { width: 180px; max-height: 48px; }
            .tekra-main > main { padding: 28px 16px !important; }
            .tekra-sidebar { display: none !important; position: absolute; z-index: 1050; top: 72px; left: 0; height: calc(100vh - 72px); box-shadow: 4px 0 14px rgba(26, 52, 86, .12); }
            .tekra-sidebar.is-open { display: block !important; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-light tekra-topbar" aria-label="Päänavigaatio">
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-menu-toggle
                    aria-controls="paavalikko" aria-expanded="false" aria-label="Avaa sivupalkki">
                <span class="navbar-toggler-icon"></span>
            </button>
            <a class="navbar-brand tekra-brand ms-2 me-4" href="raportit.php" aria-label="Raportit">
                <img src="Logo.png" alt="TEKRAPORTTI">
            </a>
            <form class="tekra-search position-relative" method="get" action="raportit.php">
                <span class="tekra-search-icon" aria-hidden="true">⌕</span>
                <input class="form-control" type="search" name="haku" value="{$safeSearch}" placeholder="Hae raportteja, asiakkaita tai yrityksiä..." aria-label="Hae raportteja, asiakkaita tai yrityksiä">
            </form>
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

        echo '<div class="d-flex align-items-center gap-3 ms-auto"><div class="dropdown">';
        $initials = mb_strtoupper(mb_substr((string) ($laatija['nimi'] ?? ''), 0, 2));
        echo '<button class="btn tekra-user-menu dropdown-toggle" type="button" data-bs-toggle="dropdown" '
            . 'aria-expanded="false"><span class="tekra-avatar">' . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') . '</span>' . $safeLaatijaNimi . '</button>';
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
        echo '</form></div></div></div>';
    }

    echo <<<HTML
        </div>
    </nav>

HTML;

    if ($layoutHasSidebar) {
        echo <<<HTML
    <div class="d-flex align-items-stretch">
        <aside class="bg-white border-end tekra-sidebar" tabindex="-1"
               id="paavalikko" aria-label="Sivupalkin valikko">
            <div class="p-2 p-lg-3">
                <nav style="display:block;">
                    <details class="tekra-reports-menu"{$reportsNavOpen} style="display:block;">
                        <summary><a class="nav-link{$reportsNavClass}" style="display:inline-block!important;width:calc(100% - 1.5rem);color:#52627a;text-decoration:none!important;border-radius:8px;margin:.35rem 0;padding:14px 18px;font-size:1.05rem;" href="raportit.php"><svg class="tekra-menu-icon" xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6z"/><path d="M15 3v5h4M9 13h6M9 17h6"/></svg> Raportit</a></summary>
                        <a class="nav-link" style="display:block!important;width:auto;color:#52627a;text-decoration:none!important;border-radius:8px;margin:0 .7rem;padding:9px 18px;font-size:.98rem;" href="luo_raportti.php"><svg class="tekra-menu-icon" xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> Uusi raportti</a>
                    </details>
                    <a class="nav-link{$projectsNavClass}" style="display:block!important;width:auto;color:#52627a;text-decoration:none!important;border-radius:8px;margin:.35rem .7rem;padding:14px 18px;font-size:1.05rem;" href="projektit.php"><svg class="tekra-menu-icon" xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h4l2 2H18.5A2.5 2.5 0 0 1 21 9.5v7A2.5 2.5 0 0 1 18.5 19h-13A2.5 2.5 0 0 1 3 16.5z"/></svg> Projektit</a>
                    <a class="nav-link{$customersNavClass}" style="display:block!important;width:auto;color:#52627a;text-decoration:none!important;border-radius:8px;margin:.35rem .7rem;padding:14px 18px;font-size:1.05rem;" href="asiakkaat.php"><svg class="tekra-menu-icon" xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 19a6 6 0 0 1 12 0M16 11a3 3 0 1 0 0-6M16 14a5 5 0 0 1 5 5"/></svg> Asiakkaat</a>
                    {$adminNavLink}
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

/** Muuntaa sovelluksen rajatun tekstimuotoilun turvalliseksi HTML:ksi. */
function markdownToHtml(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [''];
    $html = '<div class="tekra-rich-text">';
    $paragraph = [];
    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if ($paragraph) {
            $html .= '<p>' . implode('<br>', $paragraph) . '</p>';
            $paragraph = [];
        }
    };
    foreach ($lines as $line) {
        if (preg_match('/^\s*##\s+(.+)$/u', $line, $matches)) {
            $flushParagraph();
            $html .= '<h4>' . markdownInlineToHtml($matches[1]) . '</h4>';
        } elseif (trim($line) === '') {
            $flushParagraph();
        } else {
            $paragraph[] = markdownInlineToHtml($line);
        }
    }
    $flushParagraph();
    return $html . '</div>';
}

function markdownInlineToHtml(string $text): string
{
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $safe = preg_replace('/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', $safe) ?? $safe;
    return preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', '<em>$1</em>', $safe) ?? $safe;
}

/**
 * Sulkee sivun HTML-rakenteen ja näyttää version alatunnisteessa.
 */
function closeLayoutShell(): void
{
    global $layoutHasSidebar;

    echo $layoutHasSidebar ? "</main>\n</div>\n</div>\n" : "</main>\n";
    echo '<footer class="container-fluid text-end pb-3 text-muted small">© Helsingin Kiinteistömanagerit Oy 2026 · TEKRAPORTTI v'
        . htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8')
        . "</footer>\n";
    echo "<script src=\"https://code.jquery.com/jquery-4.0.0.min.js\"></script>\n";
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" '
        . 'integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" '
        . "crossorigin=\"anonymous\"></script>\n";
    echo '</body></html>';

    $html = ob_get_clean();
    echo str_replace('<?', '&lt;?', $html);
    echo '<script>
    document.querySelectorAll("[data-menu-toggle]").forEach(function(button){button.addEventListener("click",function(){var menu=document.getElementById(button.getAttribute("aria-controls"));var desktop=window.matchMedia("(min-width: 992px)").matches;var open;if(desktop){var collapsed=menu.classList.toggle("is-collapsed");open=!collapsed;}else{open=menu.classList.toggle("is-open");}button.setAttribute("aria-expanded",open?"true":"false");});});
    document.querySelectorAll("textarea[data-markdown-editor]").forEach(function(textarea){
        var toolbar=document.createElement("div"); toolbar.className="tekra-markdown-toolbar";
        var headingButton=textarea.closest("table")?"":"<button type=\"button\" class=\"btn btn-sm btn-outline-secondary\" data-md-action=\"heading\">Otsikko</button>";
        toolbar.innerHTML=headingButton+"<button type=\"button\" class=\"btn btn-sm btn-outline-secondary\" data-md-action=\"bold\"><strong>B</strong></button><button type=\"button\" class=\"btn btn-sm btn-outline-secondary\" data-md-action=\"italic\"><em>I</em></button><span class=\"tekra-markdown-help\">## väliotsikko · **lihavoitu** · *kursivoitu*</span>";
        textarea.parentNode.insertBefore(toolbar, textarea);
    });
        document.addEventListener("click",function(event){var button=event.target.closest("[data-md-action]");if(!button)return;var textarea=button.closest(".tekra-markdown-toolbar").parentNode.querySelector("textarea[data-markdown-editor]");if(!textarea)return;var start=textarea.selectionStart,end=textarea.selectionEnd,value=textarea.value,selected=value.slice(start,end),action=button.dataset.mdAction;if(action==="heading"){var lineStart=value.lastIndexOf("\\n",start-1)+1;textarea.setRangeText("## ",lineStart,lineStart,"end");}else{var marker=action==="bold"?"**":"*";textarea.setRangeText(marker+selected+marker,start,end,"select");}textarea.focus();});
    </script>';
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
            'SELECT id, nimi, sahkoposti, puhelin, yritys, ytunnus, osoite, postinro, postitmp
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

/** Tarkistaa ja normalisoi kuvan merkintäobjektit ennen tietokantaan tallentamista. */
function normalizeImageAnnotations(string $json): string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Kuvan merkinnät ovat virheellisiä.');
    }

    $normalized = [];
    foreach (array_slice($decoded, 0, 200) as $annotation) {
        if (!is_array($annotation) || !in_array((string) ($annotation['type'] ?? ''), ['arrow', 'rectangle', 'circle', 'freehand', 'text'], true)) {
            continue;
        }
        $item = [
            'type' => (string) $annotation['type'],
            'x1' => max(0, min(1, (float) ($annotation['x1'] ?? 0))),
            'y1' => max(0, min(1, (float) ($annotation['y1'] ?? 0))),
            'x2' => max(0, min(1, (float) ($annotation['x2'] ?? 0))),
            'y2' => max(0, min(1, (float) ($annotation['y2'] ?? 0))),
            'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($annotation['color'] ?? ''))
                ? (string) $annotation['color']
                : '#dc3545',
        ];
        if ($item['type'] === 'freehand') {
            $item['points'] = [];
            foreach (array_slice((array) ($annotation['points'] ?? []), 0, 2000) as $point) {
                if (is_array($point)) {
                    $item['points'][] = [
                        max(0, min(1, (float) ($point['x'] ?? $point[0] ?? 0))),
                        max(0, min(1, (float) ($point['y'] ?? $point[1] ?? 0))),
                    ];
                }
            }
        }
        if ($item['type'] === 'text') {
            $item['fontSize'] = max(0.005, min(0.2, (float) ($annotation['fontSize'] ?? 0.025)));
            $item['text'] = function_exists('mb_substr')
                ? mb_substr((string) ($annotation['text'] ?? ''), 0, 500)
                : substr((string) ($annotation['text'] ?? ''), 0, 500);
            if ($item['text'] === '') {
                continue;
            }
        }
        $normalized[] = $item;
    }
    return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function decodeImageAnnotations(?string $json): array
{
    if (!$json) {
        return [];
    }
    try {
        return json_decode(normalizeImageAnnotations($json), true, 512, JSON_THROW_ON_ERROR) ?: [];
    } catch (Throwable $error) {
        return [];
    }
}

/**
 * Ilmoittaa laatijalle, että raportti on jaettu hänen kanssaan.
 */
function sendReportShareNotification(string $recipientEmail, string $itemTitle, string $sharerName, string $sharerCompany = '', string $itemType = 'Raportti'): bool
{
    $recipientEmail = strtolower(trim($recipientEmail));
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        $mailSettings = getApplicationSecrets();
        $sender = filter_var(trim((string) $mailSettings['mailUser']), FILTER_VALIDATE_EMAIL)
            ? trim((string) $mailSettings['mailUser'])
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
        $mailer->addAddress($recipientEmail);
        $mailer->Subject = $itemType . ' jaettu kanssasi – ' . $itemTitle;
        $itemObject = $itemType === 'Projekti' ? 'projektin' : 'raportin';
        $message = "Hei,\n\n"
            . $sharerName . ' on jakanut ' . $itemObject . " kanssasi TEKRAPORTTI-palvelussa.\n\n"
            . $itemType . ': ' . $itemTitle . "\n"
            . 'Jakaja: ' . $sharerName . "\n";
        if (trim($sharerCompany) !== '') {
            $message .= 'Yritys: ' . trim($sharerCompany) . "\n";
        }
        $message .= "\nVoit kirjautua TEKRAPORTTI-palveluun tarkastelemaan ja muokkaamaan raporttia.\n";
        $mailer->Body = $message;
        $mailer->isHTML(false);

        if (!$mailer->send()) {
            throw new RuntimeException('PHPMailer ei vahvistanut sähköpostin lähetystä.');
        }
        return true;
    } catch (Throwable $error) {
        $mailError = isset($mailer) && $mailer instanceof \PHPMailer\PHPMailer\PHPMailer
            ? $mailer->ErrorInfo
            : '';
        error_log('Raportin jakamisilmoituksen lähetys epäonnistui: ' . $error->getMessage()
            . ($mailError !== '' ? ' | PHPMailer: ' . $mailError : ''));
        return false;
    }
}

/**
 * Muuntaa raportin jakokentän yksilöidyiksi sähköpostiosoitteiksi.
 */
function parseReportShareEmails(string $value): array
{
    $emails = [];
    foreach (preg_split('/[\s,;]+/', trim($value)) ?: [] as $email) {
        $email = strtolower(trim($email));
        if ($email !== '' && !in_array($email, $emails, true)) {
            $emails[] = $email;
        }
    }
    return $emails;
}

function isLaatijaLoginBlocked(string $email): bool
{
    $email = strtolower(trim($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    try {
        $query = getDatabaseConnection()->prepare(
            'SELECT id FROM laatijat WHERE sahkoposti = ? AND aktiivinen = 0 LIMIT 1'
        );
        $query->bind_param('s', $email);
        $query->execute();

        return (bool) $query->get_result()->fetch_assoc();
    } catch (Throwable $error) {
        error_log('Estetyn käyttäjän tarkistus epäonnistui: ' . $error->getMessage());
        return false;
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
            'SELECT id FROM laatijat WHERE sahkoposti = ? AND aktiivinen = 1 LIMIT 1'
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
               AND l.aktiivinen = 1
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
