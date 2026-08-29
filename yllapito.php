<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();
if ((int) ($_SESSION['laatijat_id'] ?? 0) !== 1) {
    http_response_code(403);
    openLayoutShell('Ylläpito – TEKRAPORTTI');
    echo '<div class="alert alert-danger">Tälle sivulle ei ole käyttöoikeutta.</div>';
    closeLayoutShell();
    exit;
}

$csrfToken = getApplicationCsrfToken();
$editId = max(0, (int) ($_GET['muokkaa'] ?? $_POST['id'] ?? 0));
$message = (string) ($_GET['saved'] ?? '') === '1' ? 'Muutokset tallennettiin.' : '';
$messageType = 'success';
$laatijat = [];
$form = [
    'nimi' => '', 'titteli' => '', 'sahkoposti' => '', 'puhelin' => '',
    'yritys' => '', 'ytunnus' => '', 'osoite' => '', 'postinro' => '', 'postitmp' => '',
];

try {
    $db = getDatabaseConnection();
    if ($editId > 0) {
        $query = $db->prepare('SELECT nimi, titteli, sahkoposti, puhelin, yritys, ytunnus, osoite, postinro, postitmp FROM laatijat WHERE id = ? LIMIT 1');
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
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.');
        }
        if ($action === 'delete') {
            $deleteId = (int) ($_POST['id'] ?? 0);
            if ($deleteId === 1) {
                throw new RuntimeException('Ylläpitokäyttäjää ei voi poistaa.');
            }
            $query = $db->prepare('DELETE FROM laatijat WHERE id = ?');
            $query->bind_param('i', $deleteId);
            $query->execute();
            $deleted = $query->affected_rows > 0;
            $query->close();
            if (!$deleted) {
                throw new RuntimeException('Laatijaa ei löytynyt.');
            }
            header('Location: yllapito.php?saved=1');
            exit;
        }
        if ($action !== 'save') {
            throw new RuntimeException('Tuntematon toiminto.');
        }

        foreach (array_keys($form) as $field) {
            $form[$field] = trim((string) ($_POST[$field] ?? ''));
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
        $values = array_map(static fn (string $value): ?string => $value !== '' ? $value : null, $form);
        if ($editId > 0) {
            $query = $db->prepare('UPDATE laatijat SET nimi = ?, titteli = ?, sahkoposti = ?, puhelin = ?, yritys = ?, ytunnus = ?, osoite = ?, postinro = ?, postitmp = ? WHERE id = ?');
            $query->bind_param('sssssssssi', $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], $values[6], $values[7], $values[8], $editId);
        } else {
            $query = $db->prepare('INSERT INTO laatijat (nimi, titteli, sahkoposti, puhelin, yritys, ytunnus, osoite, postinro, postitmp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $query->bind_param('sssssssss', $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], $values[6], $values[7], $values[8]);
        }
        $query->execute();
        $query->close();
        header('Location: yllapito.php?saved=1');
        exit;
    }

    $query = $db->prepare('SELECT id, nimi, titteli, sahkoposti, puhelin, yritys, ytunnus, osoite, postinro, postitmp FROM laatijat ORDER BY nimi, id');
    $query->execute();
    $laatijat = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $query->close();
} catch (Throwable $error) {
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
    <div class="card-header bg-white"><h2 class="h5 mb-0"><?= $editId > 0 ? 'Muokkaa laatijaa' : 'Lisää laatija' ?></h2></div>
    <form method="post" action="yllapito.php<?= $editId > 0 ? '?muokkaa=' . $editId : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= adminValue(['value' => $csrfToken], 'value') ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
        <div class="card-body p-4"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="nimi">Nimi *</label><input class="form-control" id="nimi" name="nimi" value="<?= adminValue($form, 'nimi') ?>" maxlength="200" required></div>
            <div class="col-md-6"><label class="form-label" for="titteli">Titteli</label><input class="form-control" id="titteli" name="titteli" value="<?= adminValue($form, 'titteli') ?>" maxlength="200"></div>
            <div class="col-md-6"><label class="form-label" for="sahkoposti">Sähköposti *</label><input type="email" class="form-control" id="sahkoposti" name="sahkoposti" value="<?= adminValue($form, 'sahkoposti') ?>" maxlength="254" required></div>
            <div class="col-md-6"><label class="form-label" for="puhelin">Puhelin *</label><input class="form-control" id="puhelin" name="puhelin" value="<?= adminValue($form, 'puhelin') ?>" maxlength="50" required></div>
            <div class="col-md-6"><label class="form-label" for="yritys">Yritys</label><input class="form-control" id="yritys" name="yritys" value="<?= adminValue($form, 'yritys') ?>" maxlength="200"></div>
            <div class="col-md-6"><label class="form-label" for="ytunnus">Y-tunnus</label><input class="form-control" id="ytunnus" name="ytunnus" value="<?= adminValue($form, 'ytunnus') ?>" maxlength="20"></div>
            <div class="col-12"><label class="form-label" for="osoite">Osoite</label><input class="form-control" id="osoite" name="osoite" value="<?= adminValue($form, 'osoite') ?>" maxlength="255"></div>
            <div class="col-md-4"><label class="form-label" for="postinro">Postinumero</label><input class="form-control" id="postinro" name="postinro" value="<?= adminValue($form, 'postinro') ?>" maxlength="20"></div>
            <div class="col-md-8"><label class="form-label" for="postitmp">Postitoimipaikka</label><input class="form-control" id="postitmp" name="postitmp" value="<?= adminValue($form, 'postitmp') ?>" maxlength="100"></div>
        </div></div><div class="card-footer bg-white border-0 px-4 pb-4"><button class="btn btn-primary">Tallenna</button><?php if ($editId > 0): ?> <a class="btn btn-outline-secondary" href="yllapito.php">Peruuta</a><?php endif; ?></div>
    </form>
</div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Nimi</th><th>Titteli</th><th>Sähköposti</th><th>Yritys</th><th class="text-end">Toiminnot</th></tr></thead><tbody>
<?php foreach ($laatijat as $laatija): ?><tr><td><?= adminValue($laatija, 'nimi') ?></td><td><?= adminValue($laatija, 'titteli') ?></td><td><?= adminValue($laatija, 'sahkoposti') ?></td><td><?= adminValue($laatija, 'yritys') ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="yllapito.php?muokkaa=<?= (int) $laatija['id'] ?>#laatijalomake">Muokkaa</a> <?php if ((int) $laatija['id'] !== 1): ?><form method="post" action="yllapito.php" class="d-inline" onsubmit="return confirm('Poistetaanko laatija ja kaikki hänen tietonsa?');"><input type="hidden" name="csrf_token" value="<?= adminValue(['value' => $csrfToken], 'value') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $laatija['id'] ?>"><button class="btn btn-sm btn-outline-danger">Poista</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$laatijat): ?><tr><td colspan="5" class="text-center text-body-secondary py-4">Ei laatijoita.</td></tr><?php endif; ?></tbody></table></div></div>
<?php closeLayoutShell(); ?>
