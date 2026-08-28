<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();

if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$csrfToken = getApplicationCsrfToken();
$editId = max(0, (int) ($_GET['muokkaa'] ?? $_POST['id'] ?? 0));
$currentPage = max(1, (int) ($_GET['sivu'] ?? 1));
$perPage = 25;
$asiakkaat = [];
$totalCustomers = 0;
$message = '';
$messageType = 'danger';
$form = [
    'nimi' => '',
    'yritys' => '',
    'ytunnus' => '',
    'sahkoposti' => '',
    'puhelin' => '',
    'osoite' => '',
    'postinro' => '',
    'postitmp' => '',
];

try {
    $db = getDatabaseConnection();

    if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $query = $db->prepare(
            'SELECT nimi, yritys, ytunnus, sahkoposti, puhelin, osoite, postinro, postitmp
             FROM asiakkaat WHERE id = ? AND laatijat_id = ? LIMIT 1'
        );
        $query->bind_param('ii', $editId, $laatijatId);
        $query->execute();
        $existing = $query->get_result()->fetch_assoc();
        $query->close();
        if ($existing) {
            $form = array_merge($form, $existing);
        } else {
            $editId = 0;
            $message = 'Asiakasta ei löytynyt.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        $postedToken = (string) ($_POST['csrf_token'] ?? '');

        if (!hash_equals($csrfToken, $postedToken)) {
            $message = 'Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.';
        } elseif ($action === 'delete') {
            $deleteId = (int) ($_POST['id'] ?? 0);
            if ($deleteId < 1) {
                $message = 'Poistettava asiakas puuttuu.';
            } else {
                $query = $db->prepare(
                    'DELETE FROM asiakkaat WHERE id = ? AND laatijat_id = ?'
                );
                $query->bind_param('ii', $deleteId, $laatijatId);
                $query->execute();
                $deleted = $query->affected_rows > 0;
                $query->close();
                $message = $deleted ? 'Asiakas poistettiin.' : 'Asiakasta ei löytynyt.';
                $messageType = $deleted ? 'success' : 'danger';
            }
        } elseif ($action === 'save') {
            foreach (array_keys($form) as $field) {
                $form[$field] = trim((string) ($_POST[$field] ?? ''));
            }

            $emailValid = $form['sahkoposti'] === ''
                || filter_var($form['sahkoposti'], FILTER_VALIDATE_EMAIL);
            if ($form['nimi'] === '' || !$emailValid) {
                $message = 'Anna asiakkaalle nimi ja tarvittaessa voimassa oleva sähköpostiosoite.';
            } elseif (mb_strlen($form['nimi']) > 200 || mb_strlen($form['yritys']) > 200
                || mb_strlen($form['ytunnus']) > 20 || mb_strlen($form['sahkoposti']) > 254
                || mb_strlen($form['puhelin']) > 50 || mb_strlen($form['osoite']) > 255
                || mb_strlen($form['postinro']) > 20 || mb_strlen($form['postitmp']) > 100) {
                $message = 'Yksi kentistä ylittää sallitun pituuden.';
            } else {
                $values = [];
                foreach ($form as $value) {
                    $values[] = $value !== '' ? $value : null;
                }

                try {
                    if ($editId > 0) {
                        $query = $db->prepare(
                            'UPDATE asiakkaat SET nimi = ?, yritys = ?, ytunnus = ?, sahkoposti = ?,
                             puhelin = ?, osoite = ?, postinro = ?, postitmp = ?
                             WHERE id = ? AND laatijat_id = ?'
                        );
                        $query->bind_param(
                            'ssssssssii', $values[0], $values[1], $values[2], $values[3],
                            $values[4], $values[5], $values[6], $values[7], $editId, $laatijatId
                        );
                    } else {
                        $query = $db->prepare(
                            'INSERT INTO asiakkaat
                             (laatijat_id, nimi, yritys, ytunnus, sahkoposti, puhelin, osoite, postinro, postitmp)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $query->bind_param(
                            'issssssss', $laatijatId, $values[0], $values[1], $values[2], $values[3],
                            $values[4], $values[5], $values[6], $values[7]
                        );
                    }
                    $query->execute();
                    $query->close();
                    $message = $editId > 0 ? 'Asiakkaan tiedot päivitettiin.' : 'Asiakas lisättiin.';
                    $messageType = 'success';
                    $editId = 0;
                    $form = array_fill_keys(array_keys($form), '');
                } catch (Throwable $error) {
                    $message = 'Asiakkaan tallennus epäonnistui. Tarkista, ettei sähköpostiosoite ole jo käytössä.';
                    error_log('Asiakkaan tallennus epäonnistui: ' . $error->getMessage());
                }
            }
        }
    }

    $countQuery = $db->prepare(
        'SELECT COUNT(*) AS maara FROM asiakkaat WHERE laatijat_id = ?'
    );
    $countQuery->bind_param('i', $laatijatId);
    $countQuery->execute();
    $totalCustomers = (int) $countQuery->get_result()->fetch_assoc()['maara'];
    $countQuery->close();

    $totalPages = max(1, (int) ceil($totalCustomers / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;
    $query = $db->prepare(
        'SELECT id, nimi, yritys, sahkoposti, puhelin, postitmp
         FROM asiakkaat WHERE laatijat_id = ?
         ORDER BY nimi ASC, id ASC LIMIT ? OFFSET ?'
    );
    $query->bind_param('iii', $laatijatId, $perPage, $offset);
    $query->execute();
    $result = $query->get_result();
    while ($asiakas = $result->fetch_assoc()) {
        $asiakkaat[] = $asiakas;
    }
    $query->close();
} catch (Throwable $error) {
    $message = 'Asiakkaiden käsittely epäonnistui.';
    error_log('Asiakassivun käsittely epäonnistui: ' . $error->getMessage());
}

function customerValue(array $form, string $key): string
{
    return htmlspecialchars((string) ($form[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

function customerPageUrl(int $page): string
{
    return 'asiakkaat.php?sivu=' . max(1, $page);
}

openLayoutShell('Asiakkaat – TEKRAPORTTI');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Asiakkaat</h1>
        <p class="text-body-secondary mb-0">Asiakasrekisteri</p>
    </div>
    <a class="btn btn-primary" href="asiakkaat.php#asiakaslomake">Lisää asiakas</a>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>" role="alert">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4" id="asiakaslomake">
    <div class="card-header bg-white">
        <h2 class="h5 mb-0"><?= $editId > 0 ? 'Muokkaa asiakasta' : 'Lisää asiakas' ?></h2>
    </div>
    <form method="post" action="asiakkaat.php<?= $editId > 0 ? '?muokkaa=' . $editId : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editId ?>">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="nimi" class="form-label">Nimi <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="nimi" name="nimi" value="<?= customerValue($form, 'nimi') ?>" maxlength="200" required>
                </div>
                <div class="col-12 col-md-6">
                    <label for="yritys" class="form-label">Yritys</label>
                    <input type="text" class="form-control" id="yritys" name="yritys" value="<?= customerValue($form, 'yritys') ?>" maxlength="200">
                </div>
                <div class="col-12 col-md-6">
                    <label for="sahkoposti" class="form-label">Sähköposti</label>
                    <input type="email" class="form-control" id="sahkoposti" name="sahkoposti" value="<?= customerValue($form, 'sahkoposti') ?>" maxlength="254">
                </div>
                <div class="col-12 col-md-6">
                    <label for="puhelin" class="form-label">Puhelin</label>
                    <input type="text" class="form-control" id="puhelin" name="puhelin" value="<?= customerValue($form, 'puhelin') ?>" maxlength="50">
                </div>
                <div class="col-12 col-md-6">
                    <label for="ytunnus" class="form-label">Y-tunnus</label>
                    <input type="text" class="form-control" id="ytunnus" name="ytunnus" value="<?= customerValue($form, 'ytunnus') ?>" maxlength="20">
                </div>
                <div class="col-12 col-md-6">
                    <label for="osoite" class="form-label">Osoite</label>
                    <input type="text" class="form-control" id="osoite" name="osoite" value="<?= customerValue($form, 'osoite') ?>" maxlength="255">
                </div>
                <div class="col-12 col-md-4">
                    <label for="postinro" class="form-label">Postinumero</label>
                    <input type="text" class="form-control" id="postinro" name="postinro" value="<?= customerValue($form, 'postinro') ?>" maxlength="20">
                </div>
                <div class="col-12 col-md-8">
                    <label for="postitmp" class="form-label">Postitoimipaikka</label>
                    <input type="text" class="form-control" id="postitmp" name="postitmp" value="<?= customerValue($form, 'postitmp') ?>" maxlength="100">
                </div>
            </div>
        </div>
        <div class="card-footer bg-white border-0 px-4 pb-4">
            <button type="submit" class="btn btn-primary"><?= $editId > 0 ? 'Tallenna muutokset' : 'Lisää asiakas' ?></button>
            <?php if ($editId > 0): ?>
                <a class="btn btn-outline-secondary" href="asiakkaat.php">Peruuta</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th scope="col">Nimi</th>
                    <th scope="col">Yritys</th>
                    <th scope="col">Sähköposti</th>
                    <th scope="col">Puhelin</th>
                    <th scope="col" class="text-end">Toiminnot</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$asiakkaat): ?>
                    <tr><td colspan="5" class="text-center text-body-secondary py-4">Ei tallennettuja asiakkaita.</td></tr>
                <?php else: ?>
                    <?php foreach ($asiakkaat as $asiakas): ?>
                        <tr>
                            <td><?= htmlspecialchars($asiakas['nimi'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($asiakas['yritys'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($asiakas['sahkoposti'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($asiakas['puhelin'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="asiakkaat.php?muokkaa=<?= (int) $asiakas['id'] ?>#asiakaslomake">Muokkaa</a>
                                <form method="post" action="asiakkaat.php" class="d-inline" onsubmit="return confirm('Poistetaanko asiakas?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $asiakas['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Poista</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="Asiakassivut">
        <?php if ($currentPage > 1): ?>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(customerPageUrl($currentPage - 1), ENT_QUOTES, 'UTF-8') ?>">← Edelliset</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
        <span class="text-body-secondary small">Sivu <?= $currentPage ?> / <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(customerPageUrl($currentPage + 1), ENT_QUOTES, 'UTF-8') ?>">Seuraavat →</a>
        <?php else: ?>
            <span></span>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<?php closeLayoutShell(); ?>
