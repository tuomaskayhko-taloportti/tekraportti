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
$contactName = trim((string) ($_POST['contact_name'] ?? ''));
$contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
$contactEmail = strtolower(trim((string) ($_POST['contact_email'] ?? '')));
$contactCompany = trim((string) ($_POST['contact_company'] ?? ''));
$contactBusinessId = trim((string) ($_POST['contact_business_id'] ?? ''));
$cookieConsent = (string) ($_COOKIE['tekraportti_cookie_consent'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $message = 'Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.';
        $messageType = 'danger';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $email = strtolower(trim((string) ($_POST['email'] ?? $email)));

        if (in_array($action, ['request-code', 'verify-code'], true) && $cookieConsent !== 'accepted') {
            $message = 'Kirjautuminen edellyttää evästeiden hyväksymistä. Hyväksy evästeet sivun alareunasta ja yritä uudelleen.';
            $messageType = 'warning';
        } elseif ($action === 'request-code') {
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
        } elseif ($action === 'service-request') {
            if ($contactName === '' || mb_strlen($contactName) > 200 || $contactPhone === '' || mb_strlen($contactPhone) > 50 || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($contactCompany) > 200 || mb_strlen($contactBusinessId) > 20) {
                $message = 'Täytä nimi, puhelinnumero ja voimassa oleva sähköpostiosoite. Yrityksen nimi ja Y-tunnus voivat olla valinnaisia.';
                $messageType = 'danger';
            } elseif (sendServiceRequest($contactName, $contactPhone, $contactEmail, $contactCompany, $contactBusinessId)) {
                $message = 'Kiitos yhteydenotosta. Asiakaspalvelu ottaa sinuun yhteyttä mahdollisimman pian.';
                $messageType = 'success';
            } else {
                $message = 'Lomakkeen lähetys epäonnistui. Yritä myöhemmin uudelleen.';
                $messageType = 'danger';
            }
        }
    }
}

$isLoggedIn = isset($_SESSION['laatijat_id']);
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeCsrfToken = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TEKRAPORTTI – Ammattilaisen työkalu raportteihin</title>
    <link rel="icon" type="image/png" href="Logo pyöreä.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --navy:#071a3d; --blue:#1769e8; --muted:#52627a; --line:#dce6f1; }
        * { box-sizing:border-box; }
        body { margin:0; color:var(--navy); font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; background:#fff; }
        .landing { overflow-x:hidden; }
        .landing-header { max-width:1600px; margin:auto; min-height:82px; display:flex; align-items:center; gap:52px; padding:18px 48px; }
        .landing-logo { width:274px; max-width:42vw; height:auto; object-fit:contain; }
        .landing-nav { display:flex; gap:28px; margin-left:auto; align-items:center; font-size:.95rem; }
        .landing-nav a { color:var(--navy); text-decoration:none; white-space:nowrap; }
        .landing-nav a:hover { color:var(--blue); }
        .hero { background:linear-gradient(115deg,#fff 18%,#f4f8fc 100%); border-bottom:1px solid #eef3f8; }
        .hero-inner { max-width:1600px; margin:auto; min-height:570px; padding:56px 48px 70px; display:grid; grid-template-columns:minmax(0,1fr) 390px; gap:150px; align-items:start; }
        .eyebrow { color:var(--blue); font-size:.78rem; font-weight:800; letter-spacing:.2em; margin:35px 0 15px; }
        h1 { max-width:650px; font-size:clamp(2.7rem,5vw,4.5rem); line-height:1.04; letter-spacing:-.055em; margin:0 0 22px; }
        .hero-copy { max-width:580px; color:var(--muted); font-size:1.12rem; line-height:1.55; }
        .hero-cover { display:block; width:100%; height:auto; border-radius:12px; }
        .benefits { display:flex; flex-wrap:wrap; gap:24px; margin:32px 0; color:var(--muted); font-size:.92rem; }
        .benefits span { display:flex; align-items:center; gap:8px; }
        .check { width:20px; height:20px; display:inline-grid; place-items:center; border-radius:50%; background:var(--blue); color:white; font-size:.78rem; font-weight:800; }
        .primary-cta { display:inline-flex; align-items:center; gap:18px; padding:15px 26px; color:white; background:var(--blue); border-radius:8px; text-decoration:none; font-weight:700; font-size:1.05rem; box-shadow:0 8px 18px #1769e83b; }
        .primary-cta:hover { color:#fff; background:#0d59ca; }
        .start-details { max-width:500px; margin-top:22px; padding:0; background:#fff; border:0; border-radius:9px; }
        .start-details summary { display:inline-flex; align-items:center; gap:18px; cursor:pointer; list-style:none; padding:15px 26px; color:#fff; background:var(--blue); border-radius:8px; font-weight:700; font-size:1.05rem; box-shadow:0 8px 18px #1769e83b; }
        .start-details summary::-webkit-details-marker { display:none; }
        .start-details[open] summary { border-radius:8px 8px 0 0; }
        .start-details form { margin-top:0; padding:18px; border:1px solid #cad8e8; border-top:0; border-radius:0 0 9px 9px; box-shadow:0 5px 14px #1a34560d; }
        .login-card { margin-top:-38px; padding:20px 18px; background:#f8fbff; border:1px solid #cad8e8; border-radius:10px; box-shadow:0 8px 22px #24476e12; }
        .login-card h2 { font-size:1.05rem; margin:0 0 10px; }
        .login-card .form-control { height:42px; border-color:#c9d5e4; }
        .login-card .btn { height:42px; font-weight:700; }
        .login-help { color:#687991; font-size:.76rem; margin:8px 0 0; }
        .feature-section { max-width:1600px; margin:auto; padding:55px 48px 78px; text-align:center; }
        .feature-section h2 { font-size:clamp(1.8rem,3vw,2.5rem); letter-spacing:-.035em; margin:0 0 8px; }
        .feature-report-model { display:block; width:min(100%,1100px); height:auto; margin:0 auto 42px; border:1px solid var(--line); border-radius:12px; box-shadow:0 8px 24px #1a345612; }
        .section-lead { color:var(--muted); margin:0 0 30px; }
        .feature-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:32px; text-align:left; }
        .feature { min-height:205px; padding:28px; border:1px solid var(--line); border-radius:9px; box-shadow:0 4px 12px #1a34560d; }
        .feature-icon { width:43px; height:43px; display:grid; place-items:center; border-radius:12px; background:#e8f1ff; color:var(--blue); font-size:1.35rem; margin-bottom:13px; }
        .feature:nth-child(3n) .feature-icon { background:#f2e9ff; color:#7444bd; }
        .feature:nth-child(4n) .feature-icon { background:#fff0e5; color:#dc751c; }
        .feature h3 { font-size:1rem; margin:0 0 7px; }
        .feature p { color:var(--muted); font-size:.9rem; line-height:1.42; margin:0; }
        .audience { background:#f3f8fd; }
        .audience-inner { max-width:1240px; margin:auto; padding:48px 28px; display:flex; justify-content:space-between; gap:50px; align-items:center; }
        .audience h2 { font-size:2rem; margin:0 0 8px; }
        .audience p { color:var(--muted); max-width:510px; }
        .process-section { width:100%; padding:58px 28px 64px; }
        .process-intro { max-width:1240px; margin:0 auto 28px; text-align:center; }
        .process-intro h2 { font-size:clamp(1.8rem,3vw,2.5rem); letter-spacing:-.035em; margin:0 0 8px; }
        .process-intro p { color:var(--muted); margin:0; }
        .process-grid { max-width:1600px; margin:auto; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:42px; }
        .process-tree { position:relative; padding:32px 38px 28px; background:#fff; border:1px solid #d8e5f2; border-radius:16px; box-shadow:0 8px 22px #1a345612; overflow:hidden; }
        .process-tree::before { content:""; position:absolute; top:86px; bottom:34px; left:54px; width:3px; background:linear-gradient(#8db8f4,#d8e5f2); }
        .process-tree h3 { position:relative; margin:0 0 22px 58px; font-size:1.35rem; }
        .process-step { position:relative; display:grid; grid-template-columns:50px 1fr; gap:18px; align-items:start; margin:0 0 17px; }
        .process-step:last-child { margin-bottom:0; }
        .process-number { z-index:1; width:38px; height:38px; display:grid; place-items:center; border-radius:50%; background:var(--blue); color:#fff; font-weight:800; box-shadow:0 0 0 5px #e7f0ff; }
        .process-step h4 { margin:1px 0 3px; font-size:1rem; }
        .process-step p { margin:0; color:var(--muted); font-size:.9rem; line-height:1.42; }
        .report-tree { background:linear-gradient(145deg,#fff,#f8fbff); }
        .report-tree .process-number { background:#7444bd; box-shadow:0 0 0 5px #f0eaff; }
        .report-tree::before { background:linear-gradient(#b69be5,#e1d8f4); }
        .info-section { max-width:1600px; margin:auto; padding:68px 48px; }
        .info-section h2 { font-size:2rem; letter-spacing:-.035em; margin:0 0 10px; }
        .info-section p,.info-section li { color:var(--muted); line-height:1.55; }
        .info-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:36px; margin-top:36px; }
        .info-card { padding:24px; border:1px solid var(--line); border-radius:9px; background:#fff; }
        .info-card h3 { font-size:1.05rem; margin:0 0 8px; }
        .pricing-table { width:100%; margin-top:30px; border-collapse:separate; border-spacing:0; overflow:hidden; border:1px solid var(--line); border-radius:12px; background:#fff; box-shadow:0 6px 18px #1a34560d; text-align:left; }
        .pricing-table th,.pricing-table td { padding:18px 22px; border-bottom:1px solid var(--line); }
        .pricing-table th { color:var(--navy); background:#edf4ff; font-size:.9rem; }
        .pricing-table td { color:var(--muted); }
        .pricing-table tr:last-child td { border-bottom:0; }
        .pricing-table strong { color:var(--navy); }
        .contact-section { background:#f3f8fd; }
        .landing-footer { max-width:1600px; margin:auto; padding:32px 48px; display:flex; align-items:center; justify-content:space-between; color:#6b7c94; font-size:.8rem; }
        .footer-logo { width:180px; }
        .footer-links { display:flex; gap:24px; }
        .footer-links a { color:inherit; text-decoration:none; }
        .alert { font-size:.82rem; padding:.65rem .8rem; }
        .cookie-banner { position:fixed; z-index:20; left:24px; right:24px; bottom:24px; max-width:920px; margin:auto; padding:20px 22px; display:flex; align-items:center; gap:22px; background:#fff; border:1px solid #cad8e8; border-radius:12px; box-shadow:0 12px 35px #071a3d2b; }
        .cookie-banner p { flex:1; margin:0; color:var(--muted); font-size:.88rem; line-height:1.45; }
        .cookie-banner a { color:var(--blue); }
        .cookie-actions { display:flex; gap:10px; flex-shrink:0; }
        .cookie-actions button { border:0; border-radius:7px; padding:10px 15px; font-weight:700; cursor:pointer; }
        .cookie-accept { color:#fff; background:var(--blue); }
        .cookie-reject { color:var(--navy); background:#edf2f8; }
        .cookie-banner[hidden] { display:none; }
        @media (max-width:600px) { .cookie-banner { left:12px; right:12px; bottom:12px; padding:16px; display:block; } .cookie-actions { margin-top:13px; } .cookie-actions button { flex:1; } }
        @media (max-width:850px) { .landing-header { flex-wrap:wrap; gap:18px; } .landing-nav { order:3; width:100%; justify-content:space-between; gap:12px; overflow:auto; } .hero-inner { grid-template-columns:1fr; gap:25px; padding-top:20px; } .login-card { margin-top:0; max-width:420px; } .eyebrow { margin-top:20px; } .feature-grid { grid-template-columns:repeat(2,1fr); } .process-grid { grid-template-columns:1fr; max-width:700px; } .audience-inner,.landing-footer { flex-direction:column; align-items:flex-start; } }
        @media (max-width:520px) { .landing-header,.hero-inner,.feature-section,.audience-inner,.landing-footer { padding-left:18px; padding-right:18px; } .landing-logo { width:235px; } .landing-nav { font-size:.78rem; } .feature-grid { grid-template-columns:1fr; } h1 { font-size:2.75rem; } .benefits { gap:12px; flex-direction:column; } .footer-links { flex-wrap:wrap; gap:10px 18px; } }
    </style>
</head>
<body class="landing">
<header class="landing-header">
    <a href="index.php" aria-label="TEKRAPORTTI etusivu"><img class="landing-logo" src="Logo.png" alt="TEKRAPORTTI"></a>
    <nav class="landing-nav" aria-label="Sivunavigaatio">
        <a href="#ominaisuudet">Ominaisuudet</a><a href="#toimintatapa">Näin se toimii</a><a href="#hinnat">Hinnat</a><a href="#ukk">Usein kysytyt kysymykset</a>
    </nav>
</header>
    <main>
    <section class="hero"><div class="hero-inner">
        <div><img class="hero-cover" src="Kansikuva.png" alt="TEKRAPORTTI-sovellus tietokoneella"><details class="start-details" id="aloita" <?= (($action ?? '') === 'service-request') ? 'open' : '' ?>><summary>Aloita käyttö <span aria-hidden="true">→</span></summary><form method="post" action="index.php#aloita"><input type="hidden" name="csrf_token" value="<?= $safeCsrfToken ?>"><input type="hidden" name="action" value="service-request"><label class="form-label" for="contact-name">Nimi</label><input class="form-control mb-2" id="contact-name" name="contact_name" value="<?= htmlspecialchars($contactName, ENT_QUOTES, 'UTF-8') ?>" maxlength="200" required><label class="form-label" for="contact-phone">Puhelin</label><input class="form-control mb-2" id="contact-phone" name="contact_phone" value="<?= htmlspecialchars($contactPhone, ENT_QUOTES, 'UTF-8') ?>" maxlength="50" required><label class="form-label" for="contact-email">Sähköposti</label><input type="email" class="form-control mb-2" id="contact-email" name="contact_email" value="<?= htmlspecialchars($contactEmail, ENT_QUOTES, 'UTF-8') ?>" maxlength="254" required><label class="form-label" for="contact-company">Yrityksen nimi</label><input class="form-control mb-2" id="contact-company" name="contact_company" value="<?= htmlspecialchars($contactCompany, ENT_QUOTES, 'UTF-8') ?>" maxlength="200"><label class="form-label" for="contact-business-id">Y-tunnus</label><input class="form-control mb-3" id="contact-business-id" name="contact_business_id" value="<?= htmlspecialchars($contactBusinessId, ENT_QUOTES, 'UTF-8') ?>" maxlength="20"><button type="submit" class="btn btn-primary w-100">Lähetä yhteydenottopyyntö</button></form></details></div>
        <aside class="login-card" id="kirjaudu"><h2>Kirjaudu</h2><?php if ($message !== ''): ?><div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>" role="alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><?php if ($isLoggedIn): ?><div class="alert alert-success">Olet kirjautunut sisään.</div><form method="post" action="index.php"><input type="hidden" name="csrf_token" value="<?= $safeCsrfToken ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="btn btn-outline-secondary w-100">Kirjaudu ulos</button></form><?php else: ?><form method="post" action="index.php"><input type="hidden" name="csrf_token" value="<?= $safeCsrfToken ?>"><input type="hidden" name="action" value="request-code"><input type="email" class="form-control mb-2" id="email" name="email" value="<?= $safeEmail ?>" placeholder="Sähköpostiosoite" autocomplete="email" required autofocus><button type="submit" class="btn btn-primary w-100">Kirjaudu</button></form><p class="login-help">Saat kirjautumiskoodin sähköpostiisi.</p><?php if ($email !== ''): ?><hr><form method="post" action="index.php"><input type="hidden" name="csrf_token" value="<?= $safeCsrfToken ?>"><input type="hidden" name="action" value="verify-code"><input type="hidden" name="email" value="<?= $safeEmail ?>"><input type="text" class="form-control mb-2" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" placeholder="Kirjautumiskoodi" autocomplete="one-time-code" required><button type="submit" class="btn btn-success w-100">Kirjaudu sisään</button></form><?php endif; ?><?php endif; ?></aside>
    </div></section>
    <section class="feature-section" id="ominaisuudet"><img class="feature-report-model" src="raporttimalli.png" alt="Esimerkki TEKRAPORTTI-raportista"><h2>Kaikki mitä tarvitset ammattimaisiin raportteihin</h2><p class="section-lead">TEKRAPORTTI tarjoaa kattavat työkalut raporttien laatimiseen, hallintaan ja jakamiseen.</p><div class="feature-grid">
        <article class="feature"><div class="feature-icon">♟</div><h3>Asiakkaiden hallinta</h3><p>Lisää, muokkaa ja hae asiakkaita. Kaikki tärkeät yhteystiedot yhdessä paikassa.</p></article>
        <article class="feature"><div class="feature-icon">■</div><h3>Projektit</h3><p>Luo projekteja ja liitä niihin useita raportteja. Hallitse aikatauluja ja kokonaisuuksia.</p></article>
        <article class="feature"><div class="feature-icon">▤</div><h3>Monipuoliset raportit</h3><p>Laadi tarkastus-, katselmus- ja työmaaraportteja vaivatta. Hae, suodata ja jaa raportteja.</p></article>
        <article class="feature"><div class="feature-icon">▥</div><h3>Joustava rakenne</h3><p>Rakenna raportti vapaasti osioista. Lisää tekstiä, kuvia, taulukoita ja videoita.</p></article>
        <article class="feature"><div class="feature-icon">▣</div><h3>Kuvat ja merkinnät</h3><p>Lisää kuvia, tee merkintöjä ja rajaa kuvia. Kuvan suunta korjataan automaattisesti.</p></article>
        <article class="feature"><div class="feature-icon">▥</div><h3>Taulukot ja diagrammit</h3><p>Luo taulukoita ja muunna ne selkeiksi kaavioiksi: piirakka-, pylväs- ja viivadiagrammit.</p></article>
        <article class="feature"><div class="feature-icon">⌕</div><h3>PDF-liitteet</h3><p>Liitä useita PDF-tiedostoja. Liitteet sisältyvät raportin loppuun allekirjoituksen jälkeen.</p></article>
        <article class="feature"><div class="feature-icon">↓</div><h3>PDF-tuloste</h3><p>Yhdellä klikkauksella ammattimainen PDF, jossa kansilehti, sisällysluettelo, kuvat, taulukot ja liitteet.</p></article>
    </div></section>
    <section class="audience process-section" id="toimintatapa"><div class="process-intro"><h2>Näin se toimii</h2><p>Selkeä prosessi käyttöönotosta valmiiseen, ammattimaiseen PDF-raporttiin.</p></div><div class="process-grid">
        <article class="process-tree"><h3>Käyttöönotto</h3><div class="process-step"><span class="process-number">1</span><div><h4>Tarkista omat tietosi</h4><p>Tarkista laatijan tiedot ja lisää logo, jos haluat sen raporttien ylätunnisteeseen.</p></div></div><div class="process-step"><span class="process-number">2</span><div><h4>Luo asiakkaat</h4><p>Lisää asiakkaat ja heidän tärkeät yhteystietonsa järjestelmään.</p></div></div><div class="process-step"><span class="process-number">3</span><div><h4>Aloita raporttien teko</h4><p>Kun perustiedot ovat kunnossa, voit aloittaa raporttien laatimisen.</p></div></div></article>
        <article class="process-tree report-tree"><h3>Raportin luominen</h3><div class="process-step"><span class="process-number">1</span><div><h4>Täytä perustiedot</h4><p>Lisää otsikko, päivämäärä, asiakas, kansikuva ja tarvittaessa jaa raportti muille laatijoille.</p></div></div><div class="process-step"><span class="process-number">2</span><div><h4>Kirjoita sisältö</h4><p>Lisää asiakkaan tiedot ja johdanto sekä rakenna raportti osioista.</p></div></div><div class="process-step"><span class="process-number">3</span><div><h4>Lisää aineisto</h4><p>Lisää taulukoita, videolinkkejä, kuvia ja PDF-muodossa olevia liitteitä.</p></div></div><div class="process-step"><span class="process-number">4</span><div><h4>Esikatsele ja viimeistele</h4><p>Valitse ennen esikatselua taulukoille haluamasi diagrammit ja muodosta valmis PDF.</p></div></div></article>
    </div></section>
    <section class="info-section" id="hinnat"><h2>Selkeä hinnoittelu</h2><p>Vuosihinta määräytyy käyttäjämäärän mukaan. Hinnat on ilmoitettu euroina.</p><table class="pricing-table"><thead><tr><th>Asiakasryhmä</th><th>Hinta</th><th>Lisätiedot</th></tr></thead><tbody><tr><td><strong>Yksityiset ja yritykset</strong></td><td><strong>100,00 € + ALV 25,50 € = 125,50 € / käyttäjä / vuosi</strong></td><td>Vuosihinta yhdelle käyttäjälle.</td></tr><tr><td><strong>Koulut ja yhdistykset</strong></td><td><strong>Pyydä tarjous</strong></td><td>Ota yhteyttä, niin teemme tarpeisiinne sopivan tarjouksen.</td></tr></tbody></table></section>
    <section class="info-section" id="ukk"><h2>Usein kysytyt kysymykset</h2><div class="info-grid"><div class="info-card"><h3>Millä laitteilla palvelu toimii?</h3><p>Palvelu toimii nykyaikaisella selaimella tietokoneella, tabletilla ja puhelimella.</p></div><div class="info-card"><h3>Voiko raporttiin lisätä kuvia ja PDF-liitteitä?</h3><p>Kyllä. Kuvia ja PDF-liitteitä voi lisätä myös drag and drop -toiminnolla.</p></div><div class="info-card"><h3>Voiko raportin tulostaa PDF:nä?</h3><p>Kyllä. Raportti sisältää kansilehden, sisällysluettelon, sisällön, kuvat, taulukot ja liitteet.</p></div></div></section>
    <section class="info-section contact-section" id="yhteystiedot"><h2>Ota yhteyttä</h2><p>Kysy käyttöönotosta tai palvelusta: <a href="mailto:asiakaspalvelu@taloportti.fi">asiakaspalvelu@taloportti.fi</a></p></section>
</main>
<footer class="landing-footer"><img class="footer-logo" src="Logo.png" alt="TEKRAPORTTI"><div class="footer-links"><a href="#ominaisuudet">Ominaisuudet</a><a href="tietosuoja.php" target="_blank" rel="noopener">Tietosuojaseloste</a><a href="kayttoehdot.php" target="_blank" rel="noopener">Käyttöehdot</a><a href="yhteydenotto.php" target="_blank" rel="noopener">Ota yhteyttä</a></div><span>Tarkempia raportteja. Parempia päätöksiä.</span></footer>
<aside class="cookie-banner" id="cookie-banner" role="dialog" aria-label="Evästeasetukset" <?= $cookieConsent === 'accepted' ? 'hidden' : '' ?>><p>TEKRAPORTTI käyttää kirjautumiseen välttämätöntä evästettä. Lue lisää <a href="tietosuoja.php" target="_blank" rel="noopener">tietosuojaselosteesta</a>. Kirjautuminen edellyttää evästeiden hyväksymistä.</p><div class="cookie-actions"><button type="button" class="cookie-reject" data-cookie-choice="rejected">En hyväksy</button><button type="button" class="cookie-accept" data-cookie-choice="accepted">Hyväksyn</button></div></aside>
<script>
(() => {
    const banner = document.getElementById('cookie-banner');
    if (!banner) return;
    banner.querySelectorAll('[data-cookie-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            const value = button.dataset.cookieChoice;
            document.cookie = `tekraportti_cookie_consent=${value}; Max-Age=31536000; Path=/; SameSite=Lax`;
            banner.hidden = value === 'accepted';
        });
    });
})();
</script>
</body></html>
