<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();

if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$csrfToken = getApplicationCsrfToken();
$asiakkaat = [];
$projektit = [];
$message = '';
$messageType = 'danger';
$form = [
    'asiakas_id' => '', 'projekti_id' => '', 'otsikko' => '',
            'raporttipvm' => date('Y-m-d'), 'laatimispaikka' => '',
    'tiivistelma' => '', 'kansikuva' => '',
];

try {
    $db = getDatabaseConnection();
    $query = $db->prepare(
        'SELECT a.id, a.nimi, a.yritys FROM asiakkaat a WHERE a.laatijat_id = ? OR EXISTS (
            SELECT 1 FROM asiakasjaot j WHERE j.asiakas_id = a.id AND j.laatijat_id = ?
        ) OR EXISTS (
            SELECT 1 FROM raportit rs INNER JOIN raporttijaot rj ON rj.raportti_id = rs.id
            WHERE rs.asiakas_id = a.id AND rj.laatijat_id = ?
        ) ORDER BY a.nimi, a.yritys'
    );
    $query->bind_param('iii', $laatijatId, $laatijatId, $laatijatId);
    $query->execute();
    $result = $query->get_result();
    while ($asiakas = $result->fetch_assoc()) {
        $asiakkaat[] = $asiakas;
    }
    $query->close();

    $query = $db->prepare(
        'SELECT p.id, p.asiakas_id, p.nimi FROM projektit p WHERE p.laatijat_id = ? OR EXISTS (
            SELECT 1 FROM projektijaot j WHERE j.projekti_id = p.id AND j.laatijat_id = ?
        ) ORDER BY p.nimi'
    );
    $query->bind_param('ii', $laatijatId, $laatijatId);
    $query->execute();
    $result = $query->get_result();
    while ($projekti = $result->fetch_assoc()) {
        $projektit[] = $projekti;
    }
    $query->close();

} catch (Throwable $error) {
    $message = 'Asiakkaiden ja projektien haku epäonnistui.';
    error_log('Raportin luontisivun tietojen haku epäonnistui: ' . $error->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['asiakas_id'] = (int) ($_POST['asiakas_id'] ?? 0);
    $form['projekti_id'] = (int) ($_POST['projekti_id'] ?? 0);
    $form['otsikko'] = trim((string) ($_POST['otsikko'] ?? ''));
    $form['raporttipvm'] = trim((string) ($_POST['raporttipvm'] ?? ''));
    $form['laatimispaikka'] = trim((string) ($_POST['laatimispaikka'] ?? ''));
    $form['tiivistelma'] = trim((string) ($_POST['tiivistelma'] ?? ''));
    $form['kansikuva'] = trim((string) ($_POST['kansikuva'] ?? ''));
    $form['jaettava_sahkoposti'] = trim((string) ($_POST['jaettava_sahkoposti'] ?? ''));
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $date = DateTime::createFromFormat('!Y-m-d', $form['raporttipvm']);
    $validDate = $date && $date->format('Y-m-d') === $form['raporttipvm'];

    if (!hash_equals($csrfToken, $postedToken)) {
        $message = 'Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.';
    } elseif ($form['asiakas_id'] < 1 || $form['otsikko'] === '' || !$validDate) {
        $message = 'Täytä asiakas, otsikko ja voimassa oleva päivämäärä.';
    } elseif (mb_strlen($form['otsikko']) > 255 || mb_strlen($form['laatimispaikka']) > 200
        || mb_strlen($form['kansikuva']) > 512) {
        $message = 'Yksi tekstikentistä ylittää sallitun pituuden.';
    } else {
        $coverValue = null;
        $sourceMaterialValue = null;
        $reportSaved = false;
        try {
            $db = getDatabaseConnection();
            $projectId = $form['projekti_id'] > 0 ? $form['projekti_id'] : null;

            $query = $db->prepare(
                'SELECT a.id FROM asiakkaat a WHERE a.id = ? AND (a.laatijat_id = ? OR EXISTS (
                    SELECT 1 FROM asiakasjaot j WHERE j.asiakas_id = a.id AND j.laatijat_id = ?
                ) OR EXISTS (
                    SELECT 1 FROM raportit rs INNER JOIN raporttijaot rj ON rj.raportti_id = rs.id
                    WHERE rs.asiakas_id = a.id AND rj.laatijat_id = ?
                )) LIMIT 1'
            );
            $query->bind_param('iiii', $form['asiakas_id'], $laatijatId, $laatijatId, $laatijatId);
            $query->execute();
            $customerExists = (bool) $query->get_result()->fetch_assoc();
            $query->close();
            if (!$customerExists) {
                throw new RuntimeException('Valittu asiakas ei kuulu kirjautuneelle laatijalle.');
            }

            if ($projectId !== null) {
                $query = $db->prepare(
                    'SELECT p.id FROM projektit p WHERE p.id = ? AND p.asiakas_id = ? AND (p.laatijat_id = ? OR EXISTS (
                        SELECT 1 FROM projektijaot j WHERE j.projekti_id = p.id AND j.laatijat_id = ?
                    )) LIMIT 1'
                );
                $query->bind_param('iiii', $projectId, $form['asiakas_id'], $laatijatId, $laatijatId);
                $query->execute();
                $projectExists = (bool) $query->get_result()->fetch_assoc();
                $query->close();
                if (!$projectExists) {
                throw new RuntimeException('Valittu projekti ei ole käytettävissä.');
                }
            }

            $sharedEmails = parseReportShareEmails((string) ($form['jaettava_sahkoposti'] ?? ''));
            $sharedLaatijat = [];
            foreach ($sharedEmails as $sharedEmail) {
                $query = $db->prepare('SELECT id FROM laatijat WHERE sahkoposti = ? AND id <> ? LIMIT 1');
                $query->bind_param('si', $sharedEmail, $laatijatId);
                $query->execute();
                $sharedLaatija = $query->get_result()->fetch_assoc();
                $query->close();
                if (!$sharedLaatija) {
                    throw new RuntimeException('Käyttäjää ei löytynyt tällä sähköpostiosoitteella: ' . $sharedEmail . '. Lisää laatija ensin ylläpidossa.');
                }
                $sharedLaatijat[] = ['id' => (int) $sharedLaatija['id'], 'email' => $sharedEmail];
            }

            $locationValue = $form['laatimispaikka'] !== '' ? $form['laatimispaikka'] : null;
            $summaryValue = $form['tiivistelma'] !== '' ? $form['tiivistelma'] : null;
            if (isset($_FILES['kansikuva']) && ($_FILES['kansikuva']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $coverValue = storeUploadedFile($_FILES['kansikuva'], 'kansikuvat');
            }
            $sourceMaterialHeadings = [];
            $sourceMaterialName = '';
            $sourceMaterialSize = 0;
            if (isset($_FILES['aineisto_pdf']) && ($_FILES['aineisto_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $sourceMaterialValue = storeUploadedFile($_FILES['aineisto_pdf'], 'aineistot', ['application/pdf' => 'pdf']);
                $sourceMaterialHeadings = extractPdfHeadings(storedFilePath($sourceMaterialValue) ?? '');
                $sourceMaterialName = mb_substr(basename((string) ($_FILES['aineisto_pdf']['name'] ?? 'aineisto.pdf')), 0, 255);
                $sourceMaterialSize = (int) ($_FILES['aineisto_pdf']['size'] ?? 0);
            }
            $db->begin_transaction();
            if ($projectId === null) {
                $query = $db->prepare(
                    'INSERT INTO raportit
                     (laatijat_id, asiakas_id, projekti_id, otsikko, raporttipvm,
                      laatimispaikka, tiivistelma, kansikuva)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?)'
                );
                $query->bind_param(
                    'iisssss', $laatijatId, $form['asiakas_id'], $form['otsikko'],
                    $form['raporttipvm'], $locationValue, $summaryValue, $coverValue
                );
            } else {
                $query = $db->prepare(
                    'INSERT INTO raportit
                     (laatijat_id, asiakas_id, projekti_id, otsikko, raporttipvm,
                      laatimispaikka, tiivistelma, kansikuva)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $query->bind_param(
                    'iiisssss', $laatijatId, $form['asiakas_id'], $projectId,
                    $form['otsikko'], $form['raporttipvm'], $locationValue,
                    $summaryValue, $coverValue
                );
            }
            $query->execute();
            $query->close();
            $reportId = (int) $db->insert_id;
            if ($sourceMaterialValue !== null) {
                $headingsJson = json_encode($sourceMaterialHeadings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $query = $db->prepare(
                    'INSERT INTO raporttiaineistot
                     (laatijat_id, raportti_id, tiedosto, alkuperainen_nimi, tiedostokoko, otsikot_json)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $query->bind_param(
                    'iissis', $laatijatId, $reportId, $sourceMaterialValue,
                    $sourceMaterialName, $sourceMaterialSize, $headingsJson
                );
                $query->execute();
                $query->close();
            }
            foreach ($sharedLaatijat as $sharedLaatija) {
                $query = $db->prepare('INSERT INTO raporttijaot (raportti_id, laatijat_id) VALUES (?, ?)');
                $sharedLaatijaId = $sharedLaatija['id'];
                $query->bind_param('ii', $reportId, $sharedLaatijaId);
                $query->execute();
                $query->close();
            }
            $db->commit();
            $reportSaved = true;
            if ($sharedLaatijat) {
                $sharer = getCurrentLaatija() ?: [];
                foreach ($sharedLaatijat as $sharedLaatija) {
                    sendReportShareNotification(
                        $sharedLaatija['email'],
                        $form['otsikko'],
                        (string) ($sharer['nimi'] ?? ''),
                        (string) ($sharer['yritys'] ?? '')
                    );
                }
            }
            header('Location: muokkaa_raportti.php?id=' . $reportId);
            exit;
        } catch (Throwable $error) {
            if (!$reportSaved && isset($db) && $db instanceof mysqli) {
                try { $db->rollback(); } catch (Throwable $_rollbackError) {}
            }
            if (!$reportSaved && $coverValue !== null) {
                deleteStoredFile($coverValue);
            }
            if (!$reportSaved && $sourceMaterialValue !== null) {
                deleteStoredFile($sourceMaterialValue);
            }
            $message = 'Raportin tallennus epäonnistui.';
            error_log('Raportin tallennus epäonnistui: ' . $error->getMessage());
        }
    }
}

function formValue(array $form, string $key): string
{
    return htmlspecialchars((string) ($form[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

openLayoutShell('Luo raportti – TEKRAPORTTI');
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
    <div>
        <h1 class="h3 mb-1">Luo raportti</h1>
        <p class="text-body-secondary mb-0">Raportin perustiedot</p>
    </div>
    <a class="btn btn-outline-secondary" href="raportit.php">Takaisin raportteihin</a>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>" role="alert">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if (!$asiakkaat): ?>
    <div class="alert alert-warning" role="alert">
        Raportin luomista varten järjestelmässä täytyy olla vähintään yksi asiakas.
    </div>
<?php else: ?>
    <form method="post" action="luo_raportti.php" enctype="multipart/form-data" class="card border-0 shadow-sm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="asiakas_id" class="form-label">Asiakas <span class="text-danger">*</span></label>
                    <select class="form-select" id="asiakas_id" name="asiakas_id" required>
                        <option value="">Valitse asiakas</option>
                        <?php foreach ($asiakkaat as $asiakas): ?>
                            <?php $asiakasNimi = $asiakas['yritys'] ? $asiakas['nimi'] . ' – ' . $asiakas['yritys'] : $asiakas['nimi']; ?>
                            <option value="<?= (int) $asiakas['id'] ?>" <?= (string) $form['asiakas_id'] === (string) $asiakas['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($asiakasNimi, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6">
                    <label for="projekti_id" class="form-label">Projekti</label>
                    <select class="form-select" id="projekti_id" name="projekti_id">
                        <option value="">Ei projektia</option>
                        <?php foreach ($projektit as $projekti): ?>
                            <option value="<?= (int) $projekti['id'] ?>" data-asiakas-id="<?= (int) $projekti['asiakas_id'] ?>" <?= (string) $form['projekti_id'] === (string) $projekti['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($projekti['nimi'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label for="otsikko" class="form-label">Raportin otsikko <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="otsikko" name="otsikko" value="<?= formValue($form, 'otsikko') ?>" maxlength="255" required>
                </div>
                <div class="col-12 col-md-6">
                    <label for="raporttipvm" class="form-label">Raportin päivämäärä <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="raporttipvm" name="raporttipvm" value="<?= formValue($form, 'raporttipvm') ?>" required>
                </div>
                <div class="col-12 col-md-6">
                    <label for="laatimispaikka" class="form-label">Laatimispaikka</label>
                    <input type="text" class="form-control" id="laatimispaikka" name="laatimispaikka" value="<?= formValue($form, 'laatimispaikka') ?>" maxlength="200">
                </div>
                <div class="col-12">
                    <label for="tiivistelma" class="form-label">Tiivistelmä</label>
                    <textarea class="form-control" id="tiivistelma" name="tiivistelma" rows="5" placeholder="Raportin lyhyt kuvaus" data-markdown-editor><?= formValue($form, 'tiivistelma') ?></textarea>
                </div>
                <div class="col-12 col-md-6">
                    <label for="jaettava_sahkoposti" class="form-label">Jaa raportti laatijoille</label>
                    <textarea class="form-control" id="jaettava_sahkoposti" name="jaettava_sahkoposti" rows="3" maxlength="4000" placeholder="laatija1@example.fi&#10;laatija2@example.fi"><?= formValue($form, 'jaettava_sahkoposti') ?></textarea>
                    <div class="form-text">Yksi sähköpostiosoite riville. Jaetut laatijat voivat muokata raporttia.</div>
                </div>
                <div class="col-12">
                    <label for="kansikuva" class="form-label">Kansikuva</label>
                    <div class="file-drop-zone" data-file-drop>
                        <input type="file" class="form-control" id="kansikuva" name="kansikuva" accept="image/jpeg,image/png,image/webp">
                        <div class="file-drop-zone-hint">Voit myös raahata kuvan tähän.</div>
                    </div>
                    <div class="form-text">Valinnainen JPG-, PNG- tai WebP-kuva, enintään 10 MB.</div>
                </div>
                <div class="col-12">
                    <label for="aineisto_pdf" class="form-label">Valmis raporttiaineisto PDF-muodossa</label>
                    <div class="file-drop-zone" data-file-drop>
                        <input type="file" class="form-control" id="aineisto_pdf" name="aineisto_pdf" accept="application/pdf,.pdf">
                        <div class="file-drop-zone-hint">Voit myös raahata PDF-tiedoston tähän.</div>
                    </div>
                    <div class="form-text">Valinnainen PDF, enintään 10 MB. Otsikot ja alaotsikot tunnistetaan sisällysluetteloon. Aineiston sivuille lisätään vain raportin ylä- ja alatunnisteet.</div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white border-0 px-4 pb-4">
            <button type="submit" class="btn btn-primary">Tallenna raportti</button>
        </div>
    </form>
<?php endif; ?>

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
        droppedFiles.slice(0, 1).forEach(function (file) {
            transfer.items.add(file);
        });
        input.files = transfer.files;
    });
});
</script>

<script>
(function () {
    const customer = document.getElementById('asiakas_id');
    const project = document.getElementById('projekti_id');
    if (!customer || !project) return;
    function filterProjects() {
        const customerId = customer.value;
        Array.from(project.options).forEach(function (option) {
            if (!option.value) return;
            option.hidden = option.dataset.asiakasId !== customerId;
        });
        if (project.selectedOptions[0] && project.selectedOptions[0].hidden) project.value = '';
    }
    customer.addEventListener('change', filterProjects);
    filterProjects();
}());
</script>

<?php closeLayoutShell(); ?>
