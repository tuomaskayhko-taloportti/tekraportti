<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();
if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$raporttiId = (int) ($_GET['id'] ?? $_POST['raportti_id'] ?? 0);
$csrfToken = getApplicationCsrfToken();
$message = '';
$messageType = 'danger';
$report = null;
$customers = [];
$projects = [];
$rows = [];
$media = [];
if ((string) ($_GET['saved'] ?? '') === '1') {
    $message = 'Muutokset tallennettiin.';
    $messageType = 'success';
}

try {
    $db = getDatabaseConnection();
    $query = $db->prepare(
        'SELECT r.*, a.nimi AS asiakas_nimi, p.nimi AS projekti_nimi
         FROM raportit r
         INNER JOIN asiakkaat a ON a.id = r.asiakas_id AND a.laatijat_id = r.laatijat_id
         LEFT JOIN projektit p ON p.id = r.projekti_id AND p.laatijat_id = r.laatijat_id
         WHERE r.id = ? AND r.laatijat_id = ? LIMIT 1'
    );
    $query->bind_param('ii', $raporttiId, $laatijatId);
    $query->execute();
    $report = $query->get_result()->fetch_assoc();
    $query->close();
} catch (Throwable $error) {
    $message = 'Raportin haku epäonnistui.';
    error_log('Muokattavan raportin haku epäonnistui: ' . $error->getMessage());
}

if (!$report) {
    http_response_code(404);
    openLayoutShell('Raporttia ei löytynyt – TEKRAPORTTI');
    echo '<div class="alert alert-danger">Raporttia ei löytynyt.</div>';
    closeLayoutShell();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $message = 'Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.';
    } else {
        try {
            $db = getDatabaseConnection();
            if ($action === 'update-report') {
                $customerId = (int) ($_POST['asiakas_id'] ?? 0);
                $projectId = (int) ($_POST['projekti_id'] ?? 0);
                $title = trim((string) ($_POST['otsikko'] ?? ''));
                $date = trim((string) ($_POST['raporttipvm'] ?? ''));
                $place = trim((string) ($_POST['laatimispaikka'] ?? ''));
                $summary = trim((string) ($_POST['tiivistelma'] ?? ''));
                $cover = trim((string) ($_POST['kansikuva'] ?? ''));
                $oldCover = trim((string) ($report['kansikuva'] ?? ''));
                $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
                if ($customerId < 1 || $title === '' || !$dateObject || $dateObject->format('Y-m-d') !== $date) {
                    throw new RuntimeException('Täytä asiakas, otsikko ja voimassa oleva päivämäärä.');
                }
                $projectValue = $projectId > 0 ? $projectId : null;
                $placeValue = $place !== '' ? $place : null;
                $summaryValue = $summary !== '' ? $summary : null;
                $coverValue = $cover !== '' ? $cover : ($oldCover !== '' ? $oldCover : null);
                if (isset($_FILES['kansikuva']) && ($_FILES['kansikuva']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $coverValue = storeUploadedFile($_FILES['kansikuva'], 'kansikuvat');
                }
                if ($projectValue === null) {
                    $query = $db->prepare(
                        'UPDATE raportit SET asiakas_id = ?, projekti_id = NULL, otsikko = ?, raporttipvm = ?,
                         laatimispaikka = ?, tiivistelma = ?, kansikuva = ? WHERE id = ? AND laatijat_id = ?'
                    );
                    $query->bind_param('isssssii', $customerId, $title, $date, $placeValue, $summaryValue, $coverValue, $raporttiId, $laatijatId);
                } else {
                    $query = $db->prepare(
                        'UPDATE raportit SET asiakas_id = ?, projekti_id = ?, otsikko = ?, raporttipvm = ?,
                         laatimispaikka = ?, tiivistelma = ?, kansikuva = ? WHERE id = ? AND laatijat_id = ?'
                    );
                    $query->bind_param('iisssssii', $customerId, $projectValue, $title, $date, $placeValue, $summaryValue, $coverValue, $raporttiId, $laatijatId);
                }
                $query->execute();
                $query->close();
                if ($oldCover !== '' && $oldCover !== $coverValue) {
                    deleteStoredFile($oldCover);
                }
                $message = 'Raportin perustiedot tallennettiin.';
                $messageType = 'success';
            } elseif ($action === 'add-row') {
                $rowTitle = trim((string) ($_POST['rivi_otsikko'] ?? ''));
                $rowContent = trim((string) ($_POST['rivi_sisalto'] ?? ''));
                if ($rowTitle === '') {
                    throw new RuntimeException('Anna osiolle otsikko.');
                }
                $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $raporttiId, $laatijatId);
                $query->execute();
                $order = (int) $query->get_result()->fetch_assoc()['seuraava'];
                $query->close();
                $query = $db->prepare('INSERT INTO raporttirivit (laatijat_id, raportti_id, otsikko, sisalto, jarjestys) VALUES (?, ?, ?, ?, ?)');
                $query->bind_param('iissi', $laatijatId, $raporttiId, $rowTitle, $rowContent, $order);
                $query->execute();
                $query->close();
                $message = 'Osio lisättiin.';
                $messageType = 'success';
            } elseif ($action === 'update-row') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $rowTitle = trim((string) ($_POST['rivi_otsikko'] ?? ''));
                $rowContent = trim((string) ($_POST['rivi_sisalto'] ?? ''));
                if ($rowId < 1 || $rowTitle === '') {
                    throw new RuntimeException('Osion otsikko puuttuu.');
                }
                $query = $db->prepare('UPDATE raporttirivit SET otsikko = ?, sisalto = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('ssiii', $rowTitle, $rowContent, $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                $message = 'Osio tallennettiin.';
                $messageType = 'success';
            } elseif ($action === 'delete-row') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $query = $db->prepare('SELECT url FROM raporttimedia WHERE raporttirivi_id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $mediaFiles = $query->get_result()->fetch_all(MYSQLI_ASSOC);
                $query->close();
                $query = $db->prepare('DELETE FROM raporttirivit WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                foreach ($mediaFiles as $mediaFile) {
                    deleteStoredFile((string) $mediaFile['url']);
                }
                $message = 'Osio poistettiin.';
                $messageType = 'success';
            } elseif ($action === 'add-media') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $type = (string) ($_POST['media_tyyppi'] ?? '');
                $url = trim((string) ($_POST['media_url'] ?? ''));
                $caption = trim((string) ($_POST['kuvateksti'] ?? ''));
                if (!in_array($type, ['kuva', 'video', 'videolinkki'], true) || $rowId < 1) {
                    throw new RuntimeException('Valitse mediatyyppi ja osio.');
                }
                if ($type === 'videolinkki') {
                    if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
                        throw new RuntimeException('Videolinkin pitää olla HTTPS-osoite.');
                    }
                } else {
                    if (!isset($_FILES['media_files']['name']) || !is_array($_FILES['media_files']['name'])) {
                        throw new RuntimeException('Valitse ladattava kuva tai video.');
                    }
                }
                $query = $db->prepare('SELECT id FROM raporttirivit WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $rowExists = (bool) $query->get_result()->fetch_assoc();
                $query->close();
                if (!$rowExists) {
                    throw new RuntimeException('Valittu osio ei kuulu raporttiin.');
                }
                $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttimedia WHERE raporttirivi_id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $rowId, $laatijatId);
                $query->execute();
                $order = (int) $query->get_result()->fetch_assoc()['seuraava'];
                $query->close();
                $files = $type === 'videolinkki' ? [null] : $_FILES['media_files']['name'];
                foreach ($files as $fileIndex => $_fileName) {
                    $mediaUrl = $url;
                    if ($type !== 'videolinkki') {
                        $uploadedFile = [
                            'name' => $_FILES['media_files']['name'][$fileIndex],
                            'type' => $_FILES['media_files']['type'][$fileIndex],
                            'tmp_name' => $_FILES['media_files']['tmp_name'][$fileIndex],
                            'error' => $_FILES['media_files']['error'][$fileIndex],
                            'size' => $_FILES['media_files']['size'][$fileIndex],
                        ];
                        $mediaUrl = storeUploadedFile($uploadedFile, 'media');
                    }
                    $query = $db->prepare('INSERT INTO raporttimedia (laatijat_id, raportti_id, raporttirivi_id, tyyppi, url, kuvateksti, jarjestys) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $query->bind_param('iiisssi', $laatijatId, $raporttiId, $rowId, $type, $mediaUrl, $caption, $order);
                    $query->execute();
                    $query->close();
                    $order++;
                }
                $message = 'Media lisättiin.';
                $messageType = 'success';
            } elseif ($action === 'delete-media') {
                $mediaId = (int) ($_POST['media_id'] ?? 0);
                $query = $db->prepare('SELECT url FROM raporttimedia WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $mediaId, $raporttiId, $laatijatId);
                $query->execute();
                $mediaFile = $query->get_result()->fetch_assoc();
                $query->close();
                $query = $db->prepare('DELETE FROM raporttimedia WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $mediaId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                if ($mediaFile) {
                    deleteStoredFile((string) $mediaFile['url']);
                }
                $message = 'Media poistettiin.';
                $messageType = 'success';
            } elseif ($action === 'update-media') {
                $mediaId = (int) ($_POST['media_id'] ?? 0);
                $caption = trim((string) ($_POST['kuvateksti'] ?? ''));
                $query = $db->prepare('UPDATE raporttimedia SET kuvateksti = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('siii', $caption, $mediaId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                $message = 'Kuvateksti tallennettiin.';
                $messageType = 'success';
            }
        } catch (Throwable $error) {
            $message = $error->getMessage();
            error_log('Raportin muokkaus epäonnistui: ' . $error->getMessage());
        }
    }
}

// Post/Redirect/Get estää saman lomakkeen lähettämisen uudelleen sivun päivityksessä.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'success') {
    header('Location: muokkaa_raportti.php?id=' . $raporttiId . '&saved=1');
    exit;
}

try {
    $db = getDatabaseConnection();
    $query = $db->prepare('SELECT id, nimi, yritys FROM asiakkaat WHERE laatijat_id = ? ORDER BY nimi, yritys');
    $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $customers[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, nimi FROM projektit WHERE laatijat_id = ? ORDER BY nimi');
    $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $projects[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, otsikko, sisalto, jarjestys FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $rows[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, raporttirivi_id, tyyppi, url, kuvateksti FROM raporttimedia WHERE raportti_id = ? AND laatijat_id = ? ORDER BY raporttirivi_id, jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $media[] = $item; } $query->close();
} catch (Throwable $error) {
    $message = 'Raportin tietojen haku epäonnistui.';
    error_log('Raportin muokkaussivun tietojen haku epäonnistui: ' . $error->getMessage());
}

function editValue($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

openLayoutShell('Muokkaa raporttia – TEKRAPORTTI');
?>
<style>
.file-drop-zone {
    border: 2px dashed #adb5bd;
    border-radius: .375rem;
    padding: .75rem;
    transition: border-color .15s ease, background-color .15s ease;
}
.file-drop-zone.is-dragover {
    border-color: #0d6efd;
    background-color: #e7f1ff;
}
.file-drop-zone-hint {
    color: #6c757d;
    font-size: .875rem;
    margin-top: .35rem;
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Muokkaa raporttia</h1><p class="text-body-secondary mb-0"><?= editValue($report['otsikko']) ?></p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" target="_blank" href="esikatsele_raportti.php?id=<?= $raporttiId ?>">Esikatsele PDF:nä</a>
        <a class="btn btn-outline-secondary" href="raportit.php">Takaisin raportteihin</a>
    </div>
</div>
<?php if ($message !== ''): ?><div class="alert alert-<?= editValue($messageType) ?>" role="alert"><?= editValue($message) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="card border-0 shadow-sm mb-4">
    <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-report">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Raportin perustiedot</h2></div><div class="card-body p-4"><div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="asiakas_id">Asiakas</label><select class="form-select" id="asiakas_id" name="asiakas_id" required><?php foreach ($customers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $report['asiakas_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= editValue($item['yritys'] ? $item['nimi'] . ' – ' . $item['yritys'] : $item['nimi']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="projekti_id">Projekti</label><select class="form-select" id="projekti_id" name="projekti_id"><option value="">Ei projektia</option><?php foreach ($projects as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $report['projekti_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= editValue($item['nimi']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="otsikko">Otsikko</label><input class="form-control" id="otsikko" name="otsikko" value="<?= editValue($report['otsikko']) ?>" maxlength="255" required></div>
        <div class="col-md-6"><label class="form-label" for="raporttipvm">Raporttipäivämäärä</label><input type="date" class="form-control" id="raporttipvm" name="raporttipvm" value="<?= editValue($report['raporttipvm']) ?>" required></div>
        <div class="col-md-6"><label class="form-label" for="laatimispaikka">Laatimispaikka</label><input class="form-control" id="laatimispaikka" name="laatimispaikka" value="<?= editValue($report['laatimispaikka']) ?>" maxlength="200"></div>
        <div class="col-12"><label class="form-label" for="tiivistelma">Tiivistelmä</label><textarea class="form-control" id="tiivistelma" name="tiivistelma" rows="5"><?= editValue($report['tiivistelma']) ?></textarea></div>
        <div class="col-12"><label class="form-label" for="kansikuva">Kansikuva</label><div class="file-drop-zone" data-file-drop><input type="file" class="form-control" id="kansikuva" name="kansikuva" accept="image/jpeg,image/png,image/webp"><div class="file-drop-zone-hint">Voit myös raahata kuvan tähän.</div></div><div class="form-text">Uusi kuva korvaa nykyisen kansikuvan. JPG, PNG tai WebP, enintään 10 MB.</div></div>
    </div></div><div class="card-footer bg-white border-0 px-4 pb-4"><button class="btn btn-primary">Tallenna perustiedot</button></div>
</form>

<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Tiivistelmä</h2></div><div class="card-body"><p class="mb-0"><?= nl2br(editValue($report['tiivistelma'] ?: 'Tiivistelmää ei ole vielä lisätty.')) ?></p></div></div>

<div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h4 mb-0">Raporttiosiot</h2></div>
<?php foreach ($rows as $row): ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-4">
        <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>">
            <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>">
            <label class="form-label">Osion otsikko</label><input class="form-control mb-3" name="rivi_otsikko" value="<?= editValue($row['otsikko']) ?>" maxlength="255" required>
            <label class="form-label">Tekstisisältö</label><textarea class="form-control mb-3" name="rivi_sisalto" rows="6"><?= editValue($row['sisalto']) ?></textarea>
            <button class="btn btn-primary btn-sm">Tallenna osio</button>
        </form>
        <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="mt-2" onsubmit="return confirm('Poistetaanko osio?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><button class="btn btn-outline-danger btn-sm">Poista osio</button></form>
        <?php foreach ($media as $item): ?><?php if ((int) $item['raporttirivi_id'] === (int) $row['id']): ?><div class="border-top mt-3 pt-2 small"><strong><?= editValue($item['tyyppi']) ?></strong>: <?= editValue($item['url']) ?>
            <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="row g-2 mt-1 align-items-center">
                <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-media"><input type="hidden" name="media_id" value="<?= (int) $item['id'] ?>">
                <div class="col-md-8"><input class="form-control form-control-sm" name="kuvateksti" value="<?= editValue($item['kuvateksti']) ?>" placeholder="Kuvateksti"></div>
                <div class="col-md-auto"><button class="btn btn-outline-primary btn-sm">Tallenna kuvateksti</button></div>
            </form>
            <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="d-inline" onsubmit="return confirm('Poistetaanko media?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-media"><input type="hidden" name="media_id" value="<?= (int) $item['id'] ?>"><button class="btn btn-link btn-sm text-danger">Poista</button></form>
        </div><?php endif; ?><?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="row g-2 mt-2"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-media"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><div class="col-md-2"><select class="form-select form-select-sm" name="media_tyyppi" required><option value="kuva">Kuva</option><option value="video">Video</option><option value="videolinkki">Videolinkki</option></select></div><div class="col-md-4"><div class="file-drop-zone" data-file-drop><input type="file" class="form-control form-control-sm" name="media_files[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/ogg"><div class="file-drop-zone-hint">Raahaa kuvat tai videot tähän tai valitse tiedostot.</div></div><div class="form-text">Voit valita useita tiedostoja.</div></div><div class="col-md-3"><input class="form-control form-control-sm" name="media_url" placeholder="Videolinkin HTTPS-osoite"></div><div class="col-md-2"><input class="form-control form-control-sm" name="kuvateksti" placeholder="Kuvateksti"></div><div class="col-md-1"><button class="btn btn-outline-primary btn-sm w-100">+</button></div></form>
    </div></div>
<?php endforeach; ?>

<div class="card border-0 shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Lisää uusi osio</h2></div><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-row"><div class="card-body p-4"><input class="form-control mb-3" name="rivi_otsikko" placeholder="Osion otsikko" maxlength="255" required><textarea class="form-control" name="rivi_sisalto" rows="6" placeholder="Tekstisisältö"></textarea></div><div class="card-footer bg-white border-0 px-4 pb-4"><button class="btn btn-primary">+ Lisää osio</button></div></form></div>

<script>
document.querySelectorAll('[data-file-drop]').forEach(function (dropZone) {
    const input = dropZone.querySelector('input[type="file"]');
    if (!input) return;

    ['dragenter', 'dragover'].forEach(function (eventName) {
        dropZone.addEventListener(eventName, function (event) {
            event.preventDefault();
            dropZone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (eventName) {
        dropZone.addEventListener(eventName, function (event) {
            event.preventDefault();
            dropZone.classList.remove('is-dragover');
        });
    });
    dropZone.addEventListener('drop', function (event) {
        const droppedFiles = Array.from(event.dataTransfer.files || []);
        if (!droppedFiles.length) return;

        const transfer = new DataTransfer();
        (input.multiple ? droppedFiles : droppedFiles.slice(0, 1)).forEach(function (file) {
            transfer.items.add(file);
        });
        input.files = transfer.files;
    });
});
</script>
<?php closeLayoutShell(); ?>
