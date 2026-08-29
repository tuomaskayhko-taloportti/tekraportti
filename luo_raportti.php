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
        'SELECT id, nimi, yritys FROM asiakkaat WHERE laatijat_id = ? ORDER BY nimi, yritys'
    );
    $query->bind_param('i', $laatijatId);
    $query->execute();
    $result = $query->get_result();
    while ($asiakas = $result->fetch_assoc()) {
        $asiakkaat[] = $asiakas;
    }
    $query->close();

    $query = $db->prepare(
        'SELECT id, nimi FROM projektit WHERE laatijat_id = ? ORDER BY nimi'
    );
    $query->bind_param('i', $laatijatId);
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
        try {
            $db = getDatabaseConnection();
            $projectId = $form['projekti_id'] > 0 ? $form['projekti_id'] : null;

            $query = $db->prepare(
                'SELECT id FROM asiakkaat WHERE id = ? AND laatijat_id = ? LIMIT 1'
            );
            $query->bind_param('ii', $form['asiakas_id'], $laatijatId);
            $query->execute();
            $customerExists = (bool) $query->get_result()->fetch_assoc();
            $query->close();
            if (!$customerExists) {
                throw new RuntimeException('Valittu asiakas ei kuulu kirjautuneelle laatijalle.');
            }

            if ($projectId !== null) {
                $query = $db->prepare(
                    'SELECT id FROM projektit WHERE id = ? AND laatijat_id = ? LIMIT 1'
                );
                $query->bind_param('ii', $projectId, $laatijatId);
                $query->execute();
                $projectExists = (bool) $query->get_result()->fetch_assoc();
                $query->close();
                if (!$projectExists) {
                    throw new RuntimeException('Valittu projekti ei kuulu kirjautuneelle laatijalle.');
                }
            }

            $sharedEmail = (string) ($form['jaettava_sahkoposti'] ?? '');
            $sharedLaatijaId = 0;
            if ($sharedEmail !== '') {
                $query = $db->prepare('SELECT id FROM laatijat WHERE sahkoposti = ? AND id <> ? LIMIT 1');
                $query->bind_param('si', $sharedEmail, $laatijatId);
                $query->execute();
                $sharedLaatija = $query->get_result()->fetch_assoc();
                $query->close();
                if (!$sharedLaatija) {
                    throw new RuntimeException('Sähköpostiosoitetta ei löytynyt laatijoista.');
                }
                $sharedLaatijaId = (int) $sharedLaatija['id'];
            }

            $locationValue = $form['laatimispaikka'] !== '' ? $form['laatimispaikka'] : null;
            $summaryValue = $form['tiivistelma'] !== '' ? $form['tiivistelma'] : null;
            $coverValue = null;
            if (isset($_FILES['kansikuva']) && ($_FILES['kansikuva']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $coverValue = storeUploadedFile($_FILES['kansikuva'], 'kansikuvat');
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
            if ($sharedLaatijaId > 0) {
                $query = $db->prepare('INSERT INTO raporttijaot (raportti_id, laatijat_id) VALUES (?, ?)');
                $query->bind_param('ii', $reportId, $sharedLaatijaId);
                $query->execute();
                $query->close();
            }
            $db->commit();
            header('Location: muokkaa_raportti.php?id=' . $reportId);
            exit;
        } catch (Throwable $error) {
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
                            <option value="<?= (int) $projekti['id'] ?>" <?= (string) $form['projekti_id'] === (string) $projekti['id'] ? 'selected' : '' ?>>
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
                    <textarea class="form-control" id="tiivistelma" name="tiivistelma" rows="5" placeholder="Raportin lyhyt kuvaus"><?= formValue($form, 'tiivistelma') ?></textarea>
                </div>
                <div class="col-12 col-md-6">
                    <label for="jaettava_sahkoposti" class="form-label">Jaa raportti laatijalle</label>
                    <input type="email" class="form-control" id="jaettava_sahkoposti" name="jaettava_sahkoposti" value="<?= formValue($form, 'jaettava_sahkoposti') ?>" maxlength="254" placeholder="laatija@example.fi">
                    <div class="form-text">Jaettu laatija voi muokata raporttia.</div>
                </div>
                <div class="col-12">
                    <label for="kansikuva" class="form-label">Kansikuva</label>
                    <input type="file" class="form-control" id="kansikuva" name="kansikuva" accept="image/jpeg,image/png,image/webp">
                    <div class="form-text">Valinnainen JPG-, PNG- tai WebP-kuva, enintään 10 MB.</div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white border-0 px-4 pb-4">
            <button type="submit" class="btn btn-primary">Tallenna raportti</button>
        </div>
    </form>
<?php endif; ?>

<?php closeLayoutShell(); ?>
