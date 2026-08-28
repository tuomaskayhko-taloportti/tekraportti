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
$projects = [];
$message = '';
$messageType = 'danger';
$form = ['nimi' => '', 'kuvaus' => '', 'alkupvm' => '', 'loppupvm' => ''];

try {
    $db = getDatabaseConnection();

    if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $query = $db->prepare('SELECT nimi, kuvaus, alkupvm, loppupvm FROM projektit WHERE id = ? AND laatijat_id = ? LIMIT 1');
        $query->bind_param('ii', $editId, $laatijatId);
        $query->execute();
        $existing = $query->get_result()->fetch_assoc();
        $query->close();
        if ($existing) {
            $form = array_merge($form, $existing);
        } else {
            $editId = 0;
            $message = 'Projektia ei löytynyt.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        $postedToken = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrfToken, $postedToken)) {
            $message = 'Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.';
        } elseif ($action === 'delete') {
            $deleteId = (int) ($_POST['id'] ?? 0);
            $query = $db->prepare('SELECT COUNT(*) AS maara FROM raportit WHERE projekti_id = ? AND laatijat_id = ?');
            $query->bind_param('ii', $deleteId, $laatijatId);
            $query->execute();
            $reportCount = (int) $query->get_result()->fetch_assoc()['maara'];
            $query->close();
            if ($reportCount > 0) {
                $message = 'Projektia ei voi poistaa, koska siihen liittyy raportteja.';
            } else {
                $query = $db->prepare('DELETE FROM projektit WHERE id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $deleteId, $laatijatId);
                $query->execute();
                $deleted = $query->affected_rows > 0;
                $query->close();
                $message = $deleted ? 'Projekti poistettiin.' : 'Projektia ei löytynyt.';
                $messageType = $deleted ? 'success' : 'danger';
            }
        } elseif ($action === 'save') {
            foreach (array_keys($form) as $field) {
                $form[$field] = trim((string) ($_POST[$field] ?? ''));
            }
            $startDate = $form['alkupvm'] !== '' ? DateTime::createFromFormat('!Y-m-d', $form['alkupvm']) : null;
            $endDate = $form['loppupvm'] !== '' ? DateTime::createFromFormat('!Y-m-d', $form['loppupvm']) : null;
            $datesValid = ($form['alkupvm'] === '' || ($startDate && $startDate->format('Y-m-d') === $form['alkupvm']))
                && ($form['loppupvm'] === '' || ($endDate && $endDate->format('Y-m-d') === $form['loppupvm']))
                && (!$startDate || !$endDate || $form['alkupvm'] <= $form['loppupvm']);
            if ($form['nimi'] === '' || !$datesValid) {
                $message = 'Anna projektin nimi ja voimassa olevat päivämäärät.';
            } elseif (mb_strlen($form['nimi']) > 200) {
                $message = 'Projektin nimi on liian pitkä.';
            } else {
                $values = array_map(static fn (string $value): ?string => $value !== '' ? $value : null, $form);
                if ($editId > 0) {
                    $query = $db->prepare('UPDATE projektit SET nimi = ?, kuvaus = ?, alkupvm = ?, loppupvm = ? WHERE id = ? AND laatijat_id = ?');
                    $query->bind_param('ssssii', $values[0], $values[1], $values[2], $values[3], $editId, $laatijatId);
                } else {
                    $query = $db->prepare('INSERT INTO projektit (laatijat_id, nimi, kuvaus, alkupvm, loppupvm) VALUES (?, ?, ?, ?, ?)');
                    $query->bind_param('issss', $laatijatId, $values[0], $values[1], $values[2], $values[3]);
                }
                $query->execute();
                $query->close();
                $message = $editId > 0 ? 'Projektin tiedot päivitettiin.' : 'Projekti lisättiin.';
                $messageType = 'success';
                $editId = 0;
                $form = ['nimi' => '', 'kuvaus' => '', 'alkupvm' => '', 'loppupvm' => ''];
            }
        }
    }

    $query = $db->prepare(
        'SELECT p.id, p.nimi, p.kuvaus, p.alkupvm, p.loppupvm, COUNT(r.id) AS raporttien_maara
         FROM projektit p LEFT JOIN raportit r ON r.projekti_id = p.id AND r.laatijat_id = p.laatijat_id
         WHERE p.laatijat_id = ? GROUP BY p.id, p.nimi, p.kuvaus, p.alkupvm, p.loppupvm ORDER BY p.nimi, p.id'
    );
    $query->bind_param('i', $laatijatId);
    $query->execute();
    $projects = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $query->close();
} catch (Throwable $error) {
    $message = 'Projektien käsittely epäonnistui.';
    error_log('Projekti-sivun käsittely epäonnistui: ' . $error->getMessage());
}

function projectValue(array $form, string $key): string
{
    return htmlspecialchars((string) ($form[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

function projectDate(?string $date): string
{
    if (!$date) {
        return '';
    }
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObject ? $dateObject->format('d.m.Y') : $date;
}

openLayoutShell('Projektit – TEKRAPORTTI');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Projektit</h1><p class="text-body-secondary mb-0">Raporttien ryhmittely</p></div>
    <a class="btn btn-primary" href="projektit.php#projektlomake">Lisää projekti</a>
</div>
<?php if ($message !== ''): ?><div class="alert alert-<?= projectValue(['value' => $messageType], 'value') ?>" role="alert"><?= projectValue(['value' => $message], 'value') ?></div><?php endif; ?>
<div class="card border-0 shadow-sm mb-4" id="projektlomake">
    <div class="card-header bg-white"><h2 class="h5 mb-0"><?= $editId > 0 ? 'Muokkaa projektia' : 'Lisää projekti' ?></h2></div>
    <form method="post" action="projektit.php<?= $editId > 0 ? '?muokkaa=' . $editId : '' ?>">
        <input type="hidden" name="csrf_token" value="<?= projectValue(['value' => $csrfToken], 'value') ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $editId ?>">
        <div class="card-body p-4"><div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="nimi">Projektin nimi <span class="text-danger">*</span></label><input class="form-control" id="nimi" name="nimi" value="<?= projectValue($form, 'nimi') ?>" maxlength="200" required></div>
            <div class="col-md-3"><label class="form-label" for="alkupvm">Alkupäivä</label><input type="date" class="form-control" id="alkupvm" name="alkupvm" value="<?= projectValue($form, 'alkupvm') ?>"></div>
            <div class="col-md-3"><label class="form-label" for="loppupvm">Loppupäivä</label><input type="date" class="form-control" id="loppupvm" name="loppupvm" value="<?= projectValue($form, 'loppupvm') ?>"></div>
            <div class="col-12"><label class="form-label" for="kuvaus">Kuvaus</label><textarea class="form-control" id="kuvaus" name="kuvaus" rows="4"><?= projectValue($form, 'kuvaus') ?></textarea></div>
        </div></div><div class="card-footer bg-white border-0 px-4 pb-4"><button class="btn btn-primary"><?= $editId > 0 ? 'Tallenna muutokset' : 'Lisää projekti' ?></button><?php if ($editId > 0): ?> <a class="btn btn-outline-secondary" href="projektit.php">Peruuta</a><?php endif; ?></div>
    </form>
</div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Projekti</th><th>Kuvaus</th><th>Ajanjakso</th><th>Raportteja</th><th class="text-end">Toiminnot</th></tr></thead><tbody>
<?php if (!$projects): ?><tr><td colspan="5" class="text-center text-body-secondary py-4">Ei tallennettuja projekteja.</td></tr><?php else: foreach ($projects as $project): ?><tr>
    <td><?= projectValue($project, 'nimi') ?></td><td><?= nl2br(projectValue($project, 'kuvaus')) ?></td>
    <td><?= projectDate($project['alkupvm']) ?><?= $project['alkupvm'] && $project['loppupvm'] ? ' – ' : '' ?><?= projectDate($project['loppupvm']) ?></td>
    <td><?= (int) $project['raporttien_maara'] ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="projektit.php?muokkaa=<?= (int) $project['id'] ?>#projektlomake">Muokkaa</a> <form method="post" action="projektit.php" class="d-inline" onsubmit="return confirm('Poistetaanko projekti?');"><input type="hidden" name="csrf_token" value="<?= projectValue(['value' => $csrfToken], 'value') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><button class="btn btn-sm btn-outline-danger">Poista</button></form></td>
</tr><?php endforeach; endif; ?></tbody></table></div></div>
<?php closeLayoutShell(); ?>
