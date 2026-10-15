<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();

if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$currentLaatijaId = $laatijatId;
$csrfToken = getApplicationCsrfToken();
$requestedEditId = max(0, (int) ($_GET['muokkaa'] ?? $_POST['id'] ?? 0));
$editId = $requestedEditId;
$editableCustomerFound = false;
$currentPage = max(1, (int) ($_GET['sivu'] ?? 1));
$perPage = 25;
$asiakkaat = [];
$sharedEmails = [];
$totalCustomers = 0;
$message = '';
$messageType = 'danger';
if (($_GET['tallennettu'] ?? '') === 'muokattu') {
    $message = 'Asiakkaan tiedot päivitettiin.';
    $messageType = 'success';
} elseif (($_GET['tallennettu'] ?? '') === 'lisatty') {
    $message = 'Asiakas lisättiin.';
    $messageType = 'success';
}
$form = [
    'nimi' => '',
    'yritys' => '',
    'ytunnus' => '',
    'sahkoposti' => '',
    'puhelin' => '',
    'osoite' => '',
    'postinro' => '',
    'postitmp' => '',
    'jaettava_sahkoposti' => '',
    'lisatiedot' => [],
];

try {
    $db = getDatabaseConnection();

    if ($editId > 0) {
        $query = $db->prepare(
            'SELECT nimi, yritys, ytunnus, sahkoposti, puhelin, osoite, postinro, postitmp, laatijat_id
             FROM asiakkaat c WHERE c.id = ? AND (c.laatijat_id = ? OR EXISTS (
                 SELECT 1 FROM asiakasjaot j WHERE j.asiakas_id = c.id AND j.laatijat_id = ?
             )) LIMIT 1'
        );
        $query->bind_param('iii', $editId, $currentLaatijaId, $currentLaatijaId);
        $query->execute();
        $existing = $query->get_result()->fetch_assoc();
        $query->close();
        if ($existing) {
            $editableCustomerFound = true;
            $laatijatId = (int) $existing['laatijat_id'];
            unset($existing['laatijat_id']);
            $form = array_merge($form, $existing);
            $shareQuery = $db->prepare('SELECT l.sahkoposti FROM asiakasjaot j INNER JOIN laatijat l ON l.id = j.laatijat_id WHERE j.asiakas_id = ? ORDER BY j.id');
            $shareQuery->bind_param('i', $editId);
            $shareQuery->execute();
            $sharedEmails = [];
            $shareResult = $shareQuery->get_result();
            while ($share = $shareResult->fetch_assoc()) { $sharedEmails[] = (string) $share['sahkoposti']; }
            $shareQuery->close();
            $form['jaettava_sahkoposti'] = implode("\n", $sharedEmails);
            $query = $db->prepare('SELECT nimi, arvo FROM asiakas_lisatiedot WHERE asiakas_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
            $query->bind_param('ii', $editId, $laatijatId);
            $query->execute();
            $form['lisatiedot'] = $query->get_result()->fetch_all(MYSQLI_ASSOC);
            $query->close();
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
                // Poistaminen on sallittu vain kirjautuneelle omistajalle.
                // Jaetun asiakkaan omistajatunnusta ei saa käyttää valtuutuksena.
                $query->bind_param('ii', $deleteId, $currentLaatijaId);
                $query->execute();
                $deleted = $query->affected_rows > 0;
                $query->close();
                $message = $deleted ? 'Asiakas poistettiin.' : 'Asiakasta ei löytynyt.';
                $messageType = $deleted ? 'success' : 'danger';
            }
        } elseif ($action === 'save' && $requestedEditId > 0 && !$editableCustomerFound) {
            $message = 'Asiakasta ei löytynyt tai sinulla ei enää ole oikeutta muokata sitä.';
        } elseif ($action === 'save') {
            $oldSharedEmails = array_map('strtolower', $sharedEmails);
            foreach (array_keys($form) as $field) {
                if ($field === 'lisatiedot') {
                    continue;
                }
                $form[$field] = trim((string) ($_POST[$field] ?? ''));
            }
            $postedNames = (array) ($_POST['lisatieto_nimi'] ?? []);
            $postedValues = (array) ($_POST['lisatieto_arvo'] ?? []);
            $form['lisatiedot'] = [];
            foreach ($postedNames as $index => $name) {
                $name = trim((string) $name);
                $value = trim((string) ($postedValues[$index] ?? ''));
                if ($name !== '' && $value !== '') {
                    $form['lisatiedot'][] = ['nimi' => $name, 'arvo' => $value];
                }
            }

            $emailValid = $form['sahkoposti'] === ''
                || filter_var($form['sahkoposti'], FILTER_VALIDATE_EMAIL);
            if ($form['nimi'] === '' || !$emailValid) {
                $message = 'Anna asiakkaalle nimi ja tarvittaessa voimassa oleva sähköpostiosoite.';
            } elseif (mb_strlen($form['nimi']) > 200 || mb_strlen($form['yritys']) > 200
                || mb_strlen($form['ytunnus']) > 20 || mb_strlen($form['sahkoposti']) > 254
                || mb_strlen($form['puhelin']) > 50 || mb_strlen($form['osoite']) > 255
                || mb_strlen($form['postinro']) > 20 || mb_strlen($form['postitmp']) > 100
                || mb_strlen($form['jaettava_sahkoposti']) > 4000
                || count($form['lisatiedot']) > 100) {
                $message = 'Yksi kentistä ylittää sallitun pituuden.';
            } elseif (array_filter($form['lisatiedot'], static fn (array $item): bool => mb_strlen($item['nimi']) > 200 || mb_strlen($item['arvo']) > 1000)) {
                $message = 'Lisätiedon nimi tai arvo ylittää sallitun pituuden.';
            } else {
                $sharedEmails = parseReportShareEmails($form['jaettava_sahkoposti']);
                $query = $db->prepare('SELECT sahkoposti FROM laatijat WHERE id = ? LIMIT 1');
                $query->bind_param('i', $laatijatId);
                $query->execute();
                $ownerEmail = strtolower(trim((string) ($query->get_result()->fetch_assoc()['sahkoposti'] ?? '')));
                $query->close();
                $sharedLaatijat = [];
                foreach ($sharedEmails as $sharedEmail) {
                    // Omistajaa ei lisätä jakotauluun. Jaetun asiakkaan muokkaajan
                    // oma osoite täytyy sen sijaan säilyttää, jotta jako ei katoa.
                    if ($sharedEmail === $ownerEmail) {
                        continue;
                    }
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
                $values = array_map(static fn (string $value): ?string => $value !== '' ? $value : null, [
                    $form['nimi'], $form['yritys'], $form['ytunnus'], $form['sahkoposti'],
                    $form['puhelin'], $form['osoite'], $form['postinro'], $form['postitmp'],
                ]);

                try {
                    $db->begin_transaction();
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
                        $customerId = $editId;
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
                        $customerId = 0;
                    }
                    $query->execute();
                    $query->close();
                    if ($editId < 1) {
                        $customerId = (int) $db->insert_id;
                    }
                    $query = $db->prepare('DELETE FROM asiakas_lisatiedot WHERE asiakas_id = ? AND laatijat_id = ?');
                    $query->bind_param('ii', $customerId, $laatijatId);
                    $query->execute();
                    $query->close();
                    if ($form['lisatiedot']) {
                        $query = $db->prepare('INSERT INTO asiakas_lisatiedot (laatijat_id, asiakas_id, nimi, arvo, jarjestys) VALUES (?, ?, ?, ?, ?)');
                        foreach ($form['lisatiedot'] as $index => $detail) {
                            $order = $index + 1;
                            $query->bind_param('iissi', $laatijatId, $customerId, $detail['nimi'], $detail['arvo'], $order);
                            $query->execute();
                        }
                        $query->close();
                    }
                    $query = $db->prepare('DELETE FROM asiakasjaot WHERE asiakas_id = ?');
                    $query->bind_param('i', $customerId); $query->execute(); $query->close();
                    foreach ($sharedLaatijat as $sharedLaatija) {
                        $sharedLaatijaId = $sharedLaatija['id'];
                        $query = $db->prepare('INSERT INTO asiakasjaot (asiakas_id, laatijat_id) VALUES (?, ?)');
                        $query->bind_param('ii', $customerId, $sharedLaatijaId); $query->execute(); $query->close();
                    }
                    $db->commit();
                    $wasEditing = $editId > 0;
                    $newSharedEmails = array_diff($sharedEmails, $oldSharedEmails);
                    $sharer = getCurrentLaatija() ?: [];
                    foreach ($newSharedEmails as $sharedEmail) {
                        sendReportShareNotification($sharedEmail, $form['nimi'], (string) ($sharer['nimi'] ?? ''), (string) ($sharer['yritys'] ?? ''), 'Asiakas');
                    }
                    header('Location: asiakkaat.php?tallennettu=' . ($wasEditing ? 'muokattu' : 'lisatty'));
                    exit;
                } catch (Throwable $error) {
                    $db->rollback();
                    $message = 'Asiakkaan tallennus epäonnistui. Tarkista, ettei sähköpostiosoite ole jo käytössä.';
                    error_log('Asiakkaan tallennus epäonnistui: ' . $error->getMessage());
                }
            }
        }
    }

    $countQuery = $db->prepare(
        'SELECT COUNT(*) AS maara FROM asiakkaat c WHERE c.laatijat_id = ? OR EXISTS (
            SELECT 1 FROM asiakasjaot j WHERE j.asiakas_id = c.id AND j.laatijat_id = ?
        )'
    );
    $countQuery->bind_param('ii', $currentLaatijaId, $currentLaatijaId);
    $countQuery->execute();
    $totalCustomers = (int) $countQuery->get_result()->fetch_assoc()['maara'];
    $countQuery->close();

    $totalPages = max(1, (int) ceil($totalCustomers / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $perPage;
    $query = $db->prepare(
        'SELECT c.id, c.laatijat_id, c.nimi, c.yritys, c.sahkoposti, c.puhelin, c.postitmp
         FROM asiakkaat c WHERE c.laatijat_id = ? OR EXISTS (
             SELECT 1 FROM asiakasjaot j WHERE j.asiakas_id = c.id AND j.laatijat_id = ?
         )
         ORDER BY nimi ASC, id ASC LIMIT ? OFFSET ?'
    );
    $query->bind_param('iiii', $currentLaatijaId, $currentLaatijaId, $perPage, $offset);
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
                    <label for="nimi" class="form-label">Yhteyshenkilö <span class="text-danger">*</span></label>
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
                <div class="col-12">
                    <label class="form-label">Lisätiedot</label>
                    <div id="asiakas-lisatiedot">
                        <?php foreach ($form['lisatiedot'] as $detail): ?>
                            <div class="row g-2 mb-2 asiakas-lisatieto-rivi">
                                <div class="col-md-4"><input type="text" class="form-control" name="lisatieto_nimi[]" value="<?= customerValue($detail, 'nimi') ?>" maxlength="200" placeholder="Nimi, esim. Rakennuksia"></div>
                                <div class="col-md-7"><textarea class="form-control" name="lisatieto_arvo[]" rows="1" maxlength="1000" placeholder="Arvo, esim. 1" data-markdown-editor><?= customerValue($detail, 'arvo') ?></textarea></div>
                                <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100" data-poista-lisatieto>−</button></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="row g-2 mb-2 asiakas-lisatieto-rivi">
                            <div class="col-md-4"><input type="text" class="form-control" name="lisatieto_nimi[]" maxlength="200" placeholder="Nimi, esim. Rakennuksia"></div>
                            <div class="col-md-7"><textarea class="form-control" name="lisatieto_arvo[]" rows="1" maxlength="1000" placeholder="Arvo, esim. 1" data-markdown-editor></textarea></div>
                            <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100" data-poista-lisatieto>−</button></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="lisaa-lisatieto">+ Lisää lisätieto</button>
                    <div class="form-text">Lisätiedot tulostetaan raportille muodossa Nimi: Arvo.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="jaettava_sahkoposti">Jaa asiakas laatijoille</label>
                    <textarea class="form-control" id="jaettava_sahkoposti" name="jaettava_sahkoposti" rows="3" maxlength="4000" placeholder="laatija1@example.fi&#10;laatija2@example.fi"><?= customerValue($form, 'jaettava_sahkoposti') ?></textarea>
                    <div class="form-text">Yksi sähköpostiosoite riville. Osoitteiden täytyy löytyä laatijoista.</div>
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
                                <?php if ((int) $asiakas['laatijat_id'] === $currentLaatijaId): ?><form method="post" action="asiakkaat.php" class="d-inline" onsubmit="return confirm('Poistetaanko asiakas?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $asiakas['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Poista</button>
                                </form><?php endif; ?>
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

<script>
document.getElementById('lisaa-lisatieto')?.addEventListener('click', function () {
    const container = document.getElementById('asiakas-lisatiedot');
    const row = container.querySelector('.asiakas-lisatieto-rivi').cloneNode(true);
    row.querySelectorAll('input, textarea').forEach(input => input.value = '');
    container.appendChild(row);
});
document.getElementById('asiakas-lisatiedot')?.addEventListener('click', function (event) {
    if (!event.target.matches('[data-poista-lisatieto]')) return;
    const rows = this.querySelectorAll('.asiakas-lisatieto-rivi');
    if (rows.length > 1) event.target.closest('.asiakas-lisatieto-rivi').remove();
    else event.target.closest('.asiakas-lisatieto-rivi').querySelectorAll('input, textarea').forEach(input => input.value = '');
});
</script>

<?php closeLayoutShell(); ?>
