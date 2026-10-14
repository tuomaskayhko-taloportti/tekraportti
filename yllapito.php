<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();
if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$currentLaatijaId = (int) ($_SESSION['laatijat_id'] ?? 0);
$isAdmin = $currentLaatijaId === 1;
$requestedEditId = (int) ($_GET['muokkaa'] ?? $_POST['id'] ?? 0);
$editId = $isAdmin ? max(0, $requestedEditId) : ($requestedEditId > 0 ? $requestedEditId : $currentLaatijaId);
if (!$isAdmin && $editId !== $currentLaatijaId) {
    http_response_code(403);
    exit('Voit muokata vain omia tietojasi.');
}

$csrfToken = getApplicationCsrfToken();
$message = (string) ($_GET['saved'] ?? '') === '1' ? 'Muutokset tallennettiin.' : '';
$messageType = 'success';
$laatijat = [];
$transactionStarted = false;
$form = [
    'nimi' => '', 'titteli' => '', 'sahkoposti' => '', 'puhelin' => '',
    'yritys' => '', 'ytunnus' => '', 'osoite' => '', 'postinro' => '', 'postitmp' => '',
];
$logoUrl = '';

try {
    $db = getDatabaseConnection();
    if ($editId > 0) {
        $query = $db->prepare('SELECT nimi, titteli, sahkoposti, puhelin, yritys, ytunnus, osoite, postinro, postitmp, logo_url FROM laatijat WHERE id = ? LIMIT 1');
        $query->bind_param('i', $editId);
        $query->execute();
        $existing = $query->get_result()->fetch_assoc();
        $query->close();
        if (!$existing) {
            $editId = 0;
            $message = 'Laatijaa ei löytynyt.';
            $messageType = 'danger';
        } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $form = array_merge($form, $existing);
            $logoUrl = trim((string) ($existing['logo_url'] ?? ''));
        } else {
            $logoUrl = trim((string) ($existing['logo_url'] ?? ''));
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.');
        }
        if ($action === 'toggle-status') {
            if (!$isAdmin) {
                throw new RuntimeException('Vain pääkäyttäjä voi muuttaa laatijan tilaa.');
            }
            $statusId = (int) ($_POST['id'] ?? 0);
            if ($statusId === 1) {
                throw new RuntimeException('Ylläpitokäyttäjän kirjautumista ei voi estää.');
            }
            $query = $db->prepare('UPDATE laatijat SET aktiivinen = IF(aktiivinen = 1, 0, 1) WHERE id = ?');
            $query->bind_param('i', $statusId);
            $query->execute();
            $query->close();
            header('Location: yllapito.php?saved=1');
            exit;
        }
        if ($action === 'delete') {
            if (!$isAdmin) {
                throw new RuntimeException('Vain pääkäyttäjä voi poistaa laatijoita.');
            }
            $deleteId = (int) ($_POST['id'] ?? 0);
            if ($deleteId === 1) {
                throw new RuntimeException('Ylläpitokäyttäjää ei voi poistaa.');
            }
            $mediaToDelete = [];
            $db->begin_transaction();
            $transactionStarted = true;
            $query = $db->prepare('SELECT id, kansikuva FROM raportit WHERE laatijat_id = ?');
            $query->bind_param('i', $deleteId); $query->execute();
            $reportResult = $query->get_result();
            while ($report = $reportResult->fetch_assoc()) {
                $reportId = (int) $report['id'];
                $cover = trim((string) ($report['kansikuva'] ?? ''));
                if ($cover !== '') { $mediaToDelete[] = $cover; }
                $mediaQuery = $db->prepare('SELECT url FROM raporttimedia WHERE raportti_id = ? AND laatijat_id = ?');
                $mediaQuery->bind_param('ii', $reportId, $deleteId); $mediaQuery->execute();
                foreach ($mediaQuery->get_result()->fetch_all(MYSQLI_ASSOC) as $media) { $mediaToDelete[] = (string) $media['url']; }
                $mediaQuery->close();
                $shareQuery = $db->prepare('SELECT laatijat_id FROM raporttijaot WHERE raportti_id = ? ORDER BY id LIMIT 1');
                $shareQuery->bind_param('i', $reportId); $shareQuery->execute();
                $share = $shareQuery->get_result()->fetch_assoc(); $shareQuery->close();
                if ($share) {
                    $newOwnerId = (int) $share['laatijat_id'];
                    $update = $db->prepare('UPDATE raportit SET laatijat_id = ? WHERE id = ? AND laatijat_id = ?');
                    $update->bind_param('iii', $newOwnerId, $reportId, $deleteId); $update->execute(); $update->close();
                    $removeShare = $db->prepare('DELETE FROM raporttijaot WHERE raportti_id = ? AND laatijat_id = ?');
                    $removeShare->bind_param('ii', $reportId, $newOwnerId); $removeShare->execute(); $removeShare->close();
                } else {
                    $remove = $db->prepare('DELETE FROM raportit WHERE id = ? AND laatijat_id = ?');
                    $remove->bind_param('ii', $reportId, $deleteId); $remove->execute(); $remove->close();
                }
            }
            $query->close();

            $query = $db->prepare('SELECT id FROM projektit WHERE laatijat_id = ?');
            $query->bind_param('i', $deleteId); $query->execute();
            $projectResult = $query->get_result();
            while ($project = $projectResult->fetch_assoc()) {
                $projectId = (int) $project['id'];
                $shareQuery = $db->prepare('SELECT laatijat_id FROM projektijaot WHERE projekti_id = ? ORDER BY id LIMIT 1');
                $shareQuery->bind_param('i', $projectId); $shareQuery->execute();
                $share = $shareQuery->get_result()->fetch_assoc(); $shareQuery->close();
                if ($share) {
                    $newOwnerId = (int) $share['laatijat_id'];
                    $update = $db->prepare('UPDATE projektit SET laatijat_id = ? WHERE id = ? AND laatijat_id = ?');
                    $update->bind_param('iii', $newOwnerId, $projectId, $deleteId); $update->execute(); $update->close();
                    $removeShare = $db->prepare('DELETE FROM projektijaot WHERE projekti_id = ? AND laatijat_id = ?');
                    $removeShare->bind_param('ii', $projectId, $newOwnerId); $removeShare->execute(); $removeShare->close();
                } else {
                    $nullProject = $db->prepare('UPDATE raportit SET projekti_id = NULL WHERE projekti_id = ?');
                    $nullProject->bind_param('i', $projectId); $nullProject->execute(); $nullProject->close();
                    $remove = $db->prepare('DELETE FROM projektit WHERE id = ? AND laatijat_id = ?');
                    $remove->bind_param('ii', $projectId, $deleteId); $remove->execute(); $remove->close();
                }
            }
            $query->close();
            $moveCustomers = $db->prepare('UPDATE asiakkaat SET laatijat_id = 1 WHERE laatijat_id = ?');
            $moveCustomers->bind_param('i', $deleteId); $moveCustomers->execute(); $moveCustomers->close();
            $query = $db->prepare('DELETE FROM laatijat WHERE id = ?');
            $query->bind_param('i', $deleteId); $query->execute();
            if ($query->affected_rows < 1) { throw new RuntimeException('Laatijaa ei löytynyt.'); }
            $query->close();
            $db->commit();
            $transactionStarted = false;
            foreach ($mediaToDelete as $mediaFile) { deleteStoredFile($mediaFile); }
            header('Location: yllapito.php?saved=1');
            exit;
        }
        if ($action !== 'save') {
            throw new RuntimeException('Tuntematon toiminto.');
        }

        foreach (array_keys($form) as $field) {
            if ($field === 'sahkoposti' && !$isAdmin) {
                continue;
            }
            $form[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        if (!$isAdmin) {
            $query = $db->prepare('SELECT sahkoposti FROM laatijat WHERE id = ? LIMIT 1');
            $query->bind_param('i', $currentLaatijaId);
            $query->execute();
            $form['sahkoposti'] = (string) ($query->get_result()->fetch_assoc()['sahkoposti'] ?? '');
            $query->close();
        }
        $form['sahkoposti'] = strtolower($form['sahkoposti']);
        if ($form['nimi'] === '' || $form['sahkoposti'] === '' || $form['puhelin'] === '') {
            throw new RuntimeException('Täytä nimi, sähköposti ja puhelin.');
        }
        if (!filter_var($form['sahkoposti'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Anna voimassa oleva sähköpostiosoite.');
        }
        $limits = ['nimi' => 200, 'titteli' => 200, 'sahkoposti' => 254, 'puhelin' => 50, 'yritys' => 200, 'ytunnus' => 20, 'osoite' => 255, 'postinro' => 20, 'postitmp' => 100];
        foreach ($limits as $field => $limit) {
            if (mb_strlen($form[$field]) > $limit) {
                throw new RuntimeException('Kenttä ' . $field . ' on liian pitkä.');
            }
        }
        $oldLogoUrl = $logoUrl;
        if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $logoUrl = storeUploadedFile($_FILES['logo'], 'logot', [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            ]);
        } elseif (isset($_POST['poista_logo'])) {
            $logoUrl = '';
        }
        $values = array_values(array_map(static fn (string $value): ?string => $value !== '' ? $value : null, $form));
        $logoValue = $logoUrl !== '' ? $logoUrl : null;
        if ($editId > 0) {
            $query = $db->prepare('UPDATE laatijat SET nimi = ?, titteli = ?, sahkoposti = ?, puhelin = ?, yritys = ?, ytunnus = ?, osoite = ?, postinro = ?, postitmp = ?, logo_url = ? WHERE id = ?');
            $query->bind_param('ssssssssssi', $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], $values[6], $values[7], $values[8], $logoValue, $editId);
        } else {
            $query = $db->prepare('INSERT INTO laatijat (nimi, titteli, sahkoposti, puhelin, yritys, ytunnus, osoite, postinro, postitmp, logo_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $query->bind_param('ssssssssss', $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], $values[6], $values[7], $values[8], $logoValue);
        }
        $query->execute();
        $query->close();
        if ($oldLogoUrl !== '' && $oldLogoUrl !== $logoUrl) {
            deleteStoredFile($oldLogoUrl);
        }
        header('Location: yllapito.php?saved=1');
        exit;
    }

    $query = $isAdmin
        ? $db->prepare('SELECT id, nimi, titteli, aktiivinen, sahkoposti, puhelin, yritys, ytunnus, osoite, postinro, postitmp FROM laatijat ORDER BY nimi, id')
        : $db->prepare('SELECT id, nimi, titteli, aktiivinen, sahkoposti, puhelin, yritys, ytunnus, osoite, postinro, postitmp FROM laatijat WHERE id = ?');
    if (!$isAdmin) {
        $query->bind_param('i', $currentLaatijaId);
    }
    $query->execute();
    $laatijat = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $query->close();
} catch (Throwable $error) {
    if ($transactionStarted && isset($db) && $db instanceof mysqli) {
        $db->rollback();
    }
    $message = $error->getMessage();
    $messageType = 'danger';
    error_log('Ylläpitosivun käsittely epäonnistui: ' . $error->getMessage());
}

function adminValue(array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

openLayoutShell('Ylläpito – TEKRAPORTTI');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Ylläpito</h1><p class="text-body-secondary mb-0">Laatijoiden käyttäjähallinta</p></div>
</div>
<?php if ($message !== ''): ?><div class="alert alert-<?= adminValue(['value' => $messageType], 'value') ?>" role="alert"><?= adminValue(['value' => $message], 'value') ?></div><?php endif; ?>
<div class="card border-0 shadow-sm mb-4" id="laatijalomake">
    <div class="card-header bg-white"><h2 class="h5 mb-0"><?= $editId > 0 ? 'Muokkaa omia tietoja' : 'Lisää laatija' ?></h2></div>
    <form method="post" enctype="multipart/form-data" action="yllapito.php<?= $editId > 0 ? '?muokkaa=' . $editId : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= adminValue(['value' => $csrfToken], 'value') ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
        <div class="card-body p-4"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="nimi">Nimi *</label><input class="form-control" id="nimi" name="nimi" value="<?= adminValue($form, 'nimi') ?>" maxlength="200" required></div>
            <div class="col-md-6"><label class="form-label" for="titteli">Titteli</label><input class="form-control" id="titteli" name="titteli" value="<?= adminValue($form, 'titteli') ?>" maxlength="200"></div>
            <div class="col-md-6"><label class="form-label" for="sahkoposti">Sähköposti *</label><input type="email" class="form-control" id="sahkoposti" name="sahkoposti" value="<?= adminValue($form, 'sahkoposti') ?>" maxlength="254" required <?= !$isAdmin ? 'readonly' : '' ?>><?php if (!$isAdmin): ?><div class="form-text">Sähköpostiosoitteen voi vaihtaa vain pääkäyttäjä.</div><?php endif; ?></div>
            <div class="col-md-6"><label class="form-label" for="puhelin">Puhelin *</label><input class="form-control" id="puhelin" name="puhelin" value="<?= adminValue($form, 'puhelin') ?>" maxlength="50" required></div>
            <div class="col-md-6"><label class="form-label" for="yritys">Yritys</label><input class="form-control" id="yritys" name="yritys" value="<?= adminValue($form, 'yritys') ?>" maxlength="200"></div>
            <div class="col-md-6"><label class="form-label" for="ytunnus">Y-tunnus</label><input class="form-control" id="ytunnus" name="ytunnus" value="<?= adminValue($form, 'ytunnus') ?>" maxlength="20"></div>
            <div class="col-12"><label class="form-label" for="osoite">Osoite</label><input class="form-control" id="osoite" name="osoite" value="<?= adminValue($form, 'osoite') ?>" maxlength="255"></div>
            <div class="col-md-4"><label class="form-label" for="postinro">Postinumero</label><input class="form-control" id="postinro" name="postinro" value="<?= adminValue($form, 'postinro') ?>" maxlength="20"></div>
            <div class="col-md-8"><label class="form-label" for="postitmp">Postitoimipaikka</label><input class="form-control" id="postitmp" name="postitmp" value="<?= adminValue($form, 'postitmp') ?>" maxlength="100"></div>
            <div class="col-12"><label class="form-label" for="logo">Ylätunnisteen logo</label><input type="file" class="form-control" id="logo" name="logo" accept="image/jpeg,image/png,image/webp"><div class="form-text">JPG, PNG tai WebP, enintään 10 MB. Uusi logo korvaa nykyisen.</div><?php if ($logoUrl !== ''): ?><div class="d-flex align-items-center gap-3 mt-3"><img src="<?= adminValue(['value' => $logoUrl], 'value') ?>" alt="Nykyinen logo" style="max-width:220px;max-height:62px;object-fit:contain"><div class="form-check"><input class="form-check-input" type="checkbox" id="poista_logo" name="poista_logo"><label class="form-check-label" for="poista_logo">Poista nykyinen logo</label></div></div><?php endif; ?></div>
        </div></div><div class="card-footer bg-white border-0 px-4 pb-4"><button class="btn btn-primary">Tallenna</button><?php if ($editId > 0): ?> <a class="btn btn-outline-secondary" href="yllapito.php">Peruuta</a><?php endif; ?></div>
    </form>
</div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Nimi</th><th>Titteli</th><th>Sähköposti</th><th>Yritys</th><th>Tila</th><th class="text-end">Toiminnot</th></tr></thead><tbody>
<?php foreach ($laatijat as $laatija): ?><tr><td><?= adminValue($laatija, 'nimi') ?></td><td><?= adminValue($laatija, 'titteli') ?></td><td><?= adminValue($laatija, 'sahkoposti') ?></td><td><?= adminValue($laatija, 'yritys') ?></td><td><?= (int) $laatija['aktiivinen'] === 1 ? 'Aktiivinen' : 'Estetty' ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="yllapito.php?muokkaa=<?= (int) $laatija['id'] ?>#laatijalomake">Muokkaa</a><?php if ($isAdmin && (int) $laatija['id'] !== 1): ?> <form method="post" action="yllapito.php" class="d-inline"><input type="hidden" name="csrf_token" value="<?= adminValue(['value' => $csrfToken], 'value') ?>"><input type="hidden" name="action" value="toggle-status"><input type="hidden" name="id" value="<?= (int) $laatija['id'] ?>"><button class="btn btn-sm btn-outline-warning"><?= (int) $laatija['aktiivinen'] === 1 ? 'Estä kirjautuminen' : 'Salli kirjautuminen' ?></button></form> <form method="post" action="yllapito.php" class="d-inline" onsubmit="return confirm('Poistetaanko laatija ja kaikki hänen tietonsa?');"><input type="hidden" name="csrf_token" value="<?= adminValue(['value' => $csrfToken], 'value') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $laatija['id'] ?>"><button class="btn btn-sm btn-outline-danger">Poista</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$laatijat): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">Ei laatijoita.</td></tr><?php endif; ?></tbody></table></div></div>
<?php closeLayoutShell(); ?>
