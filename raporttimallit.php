<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();
if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$csrfToken = getApplicationCsrfToken();
$message = '';
$messageType = 'danger';
$templates = [];
$sourceReports = [];

try {
    $db = getDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.');
        }

        if ($action === 'save-template') {
            $sourceReportId = (int) ($_POST['raportti_id'] ?? 0);
            $templateName = trim((string) ($_POST['mallin_nimi'] ?? ''));
            if ($sourceReportId < 1 || $templateName === '' || mb_strlen($templateName) > 255) {
                throw new RuntimeException('Anna mallille nimi ja valitse raportti.');
            }
            $query = $db->prepare(
                'SELECT r.id, r.asiakas_id
                 FROM raportit r
                 WHERE r.id = ? AND (r.laatijat_id = ? OR EXISTS (
                     SELECT 1 FROM raporttijaot rj WHERE rj.raportti_id = r.id AND rj.laatijat_id = ?
                 ) OR EXISTS (
                     SELECT 1 FROM projektijaot pj WHERE pj.projekti_id = r.projekti_id AND pj.laatijat_id = ?
                 )) LIMIT 1'
            );
            $query->bind_param('iiii', $sourceReportId, $laatijatId, $laatijatId, $laatijatId);
            $query->execute();
            $source = $query->get_result()->fetch_assoc();
            $query->close();
            if (!$source) {
                throw new RuntimeException('Raporttia ei löytynyt tai sinulla ei ole siihen käyttöoikeutta.');
            }
            $query = $db->prepare('INSERT INTO raporttimallit (laatijat_id, asiakas_id, lahde_raportti_id, nimi) VALUES (?, ?, ?, ?)');
            $customerId = (int) $source['asiakas_id'];
            $query->bind_param('iiis', $laatijatId, $customerId, $sourceReportId, $templateName);
            $query->execute();
            $query->close();
            header('Location: raporttimallit.php?malli_lisatty=1');
            exit;
        }

        if ($action === 'delete-template') {
            $templateId = (int) ($_POST['malli_id'] ?? 0);
            $query = $db->prepare('DELETE FROM raporttimallit WHERE id = ? AND laatijat_id = ?');
            $query->bind_param('ii', $templateId, $laatijatId);
            $query->execute();
            $deleted = $query->affected_rows > 0;
            $query->close();
            if (!$deleted) {
                throw new RuntimeException('Mallia ei löytynyt tai sen poistaminen ei ole sallittu.');
            }
            header('Location: raporttimallit.php');
            exit;
        }

        if ($action === 'create-report') {
            $templateId = (int) ($_POST['malli_id'] ?? 0);
            $query = $db->prepare(
                'SELECT m.id, m.nimi, m.asiakas_id, m.lahde_raportti_id
                 FROM raporttimallit m
                 WHERE m.id = ? AND (m.laatijat_id = ? OR EXISTS (
                     SELECT 1 FROM asiakasjaot aj WHERE aj.asiakas_id = m.asiakas_id AND aj.laatijat_id = ?
                 )) LIMIT 1'
            );
            $query->bind_param('iii', $templateId, $laatijatId, $laatijatId);
            $query->execute();
            $template = $query->get_result()->fetch_assoc();
            $query->close();
            if (!$template || !(int) $template['lahde_raportti_id']) {
                throw new RuntimeException('Mallia ei löytynyt tai sen lähderaportti on poistettu.');
            }

            $sourceReportId = (int) $template['lahde_raportti_id'];
            $query = $db->prepare('SELECT otsikko, laatimispaikka, tiivistelma, kansikuva FROM raportit WHERE id = ? LIMIT 1');
            $query->bind_param('i', $sourceReportId);
            $query->execute();
            $sourceReport = $query->get_result()->fetch_assoc();
            $query->close();
            if (!$sourceReport) {
                throw new RuntimeException('Mallin lähderaporttia ei löytynyt.');
            }

            $db->begin_transaction();
            $title = (string) $template['nimi'];
            $date = date('Y-m-d');
            $location = $sourceReport['laatimispaikka'] !== null ? (string) $sourceReport['laatimispaikka'] : null;
            $summary = $sourceReport['tiivistelma'] !== null ? (string) $sourceReport['tiivistelma'] : null;
            // Tiedostopolkuja ei kopioida, koska ne kuuluvat lähderaportin omistajalle.
            $cover = null;
            $query = $db->prepare('INSERT INTO raportit (laatijat_id, asiakas_id, otsikko, raporttipvm, laatimispaikka, tiivistelma, kansikuva) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $customerId = (int) $template['asiakas_id'];
            $query->bind_param('iisssss', $laatijatId, $customerId, $title, $date, $location, $summary, $cover);
            $query->execute();
            $newReportId = (int) $db->insert_id;
            $query->close();

            $query = $db->prepare('SELECT id, otsikko, sisalto, sisaltaa_asiakastiedot, jarjestys FROM raporttirivit WHERE raportti_id = ? ORDER BY jarjestys, id');
            $query->bind_param('i', $sourceReportId);
            $query->execute();
            $rows = $query->get_result()->fetch_all(MYSQLI_ASSOC);
            $query->close();
            foreach ($rows as $row) {
                $query = $db->prepare('INSERT INTO raporttirivit (laatijat_id, raportti_id, otsikko, sisalto, sisaltaa_asiakastiedot, jarjestys) VALUES (?, ?, ?, ?, ?, ?)');
                $rowTitle = (string) $row['otsikko'];
                $rowContent = (string) ($row['sisalto'] ?? '');
                $includeCustomerInfo = (int) $row['sisaltaa_asiakastiedot'];
                $rowOrder = (int) $row['jarjestys'];
                $query->bind_param('iissii', $laatijatId, $newReportId, $rowTitle, $rowContent, $includeCustomerInfo, $rowOrder);
                $query->execute();
                $newRowId = (int) $db->insert_id;
                $query->close();

                $textQuery = $db->prepare('SELECT sisalto, jarjestys FROM raporttitekstit WHERE raporttirivi_id = ? ORDER BY jarjestys, id');
                $sourceRowId = (int) $row['id'];
                $textQuery->bind_param('i', $sourceRowId);
                $textQuery->execute();
                $texts = $textQuery->get_result()->fetch_all(MYSQLI_ASSOC);
                $textQuery->close();
                foreach ($texts as $text) {
                    $insertText = $db->prepare('INSERT INTO raporttitekstit (laatijat_id, raportti_id, raporttirivi_id, sisalto, jarjestys) VALUES (?, ?, ?, ?, ?)');
                    $textContent = (string) ($text['sisalto'] ?? '');
                    $textOrder = (int) $text['jarjestys'];
                    $insertText->bind_param('iiisi', $laatijatId, $newReportId, $newRowId, $textContent, $textOrder);
                    $insertText->execute();
                    $insertText->close();
                }
            }
            $db->commit();
            header('Location: muokkaa_raportti.php?id=' . $newReportId);
            exit;
        }
    }

    $query = $db->prepare(
        'SELECT m.id, m.nimi, m.created_at, m.laatijat_id, a.nimi AS asiakas_nimi, a.yritys AS asiakas_yritys,
                l.nimi AS omistaja_nimi
         FROM raporttimallit m
         INNER JOIN asiakkaat a ON a.id = m.asiakas_id
         INNER JOIN laatijat l ON l.id = m.laatijat_id
         WHERE m.laatijat_id = ? OR EXISTS (
             SELECT 1 FROM asiakasjaot aj WHERE aj.asiakas_id = m.asiakas_id AND aj.laatijat_id = ?
         ) ORDER BY a.nimi, m.nimi, m.id'
    );
    $query->bind_param('ii', $laatijatId, $laatijatId);
    $query->execute();
    $templates = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $query->close();

    $query = $db->prepare(
        'SELECT r.id, r.otsikko, r.laatijat_id, a.nimi AS asiakas_nimi, a.yritys AS asiakas_yritys,
                l.nimi AS omistaja_nimi
         FROM raportit r
         INNER JOIN asiakkaat a ON a.id = r.asiakas_id
         INNER JOIN laatijat l ON l.id = r.laatijat_id
         WHERE r.laatijat_id = ? OR EXISTS (
             SELECT 1 FROM raporttijaot rj WHERE rj.raportti_id = r.id AND rj.laatijat_id = ?
         ) OR EXISTS (
             SELECT 1 FROM projektijaot pj WHERE pj.projekti_id = r.projekti_id AND pj.laatijat_id = ?
         )
         ORDER BY r.updated_at DESC, r.id DESC'
    );
    $query->bind_param('iii', $laatijatId, $laatijatId, $laatijatId);
    $query->execute();
    $sourceReports = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $query->close();
} catch (Throwable $error) {
    if (isset($db) && $db instanceof mysqli) {
        try { $db->rollback(); } catch (Throwable $ignored) {}
    }
    $message = $error->getMessage();
    error_log('Raporttimallien käsittely epäonnistui: ' . $error->getMessage());
}

function templateValue($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

openLayoutShell('Raporttimallit – TEKRAPORTTI');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Raporttimallit</h1><p class="text-body-secondary mb-0">Luo uusia raportteja valmiista sisällöistä ja osioista.</p></div>
    <a class="btn btn-outline-secondary" href="raportit.php">Takaisin raportteihin</a>
</div>
<?php if ($message !== ''): ?><div class="alert alert-<?= templateValue($messageType) ?>"><?= templateValue($message) ?></div><?php endif; ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Tallenna raportti malliksi</h2></div>
    <form method="post" class="card-body p-4">
        <input type="hidden" name="csrf_token" value="<?= templateValue($csrfToken) ?>"><input type="hidden" name="action" value="save-template">
        <div class="row g-3 align-items-end"><div class="col-md-5"><label class="form-label" for="mallin_nimi">Mallin nimi</label><input class="form-control" id="mallin_nimi" name="mallin_nimi" maxlength="255" required></div><div class="col-md-5"><label class="form-label" for="raportti_id">Lähderaportti</label><select class="form-select" id="raportti_id" name="raportti_id" required><option value="">Valitse raportti</option><?php foreach ($sourceReports as $report): ?><option value="<?= (int) $report['id'] ?>"><?= templateValue($report['otsikko'] . ' – ' . ($report['asiakas_yritys'] ?: $report['asiakas_nimi']) . ((int) $report['laatijat_id'] === $laatijatId ? '' : ' – Jaettu: ' . $report['omistaja_nimi'])) ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary w-100">Tallenna malliksi</button></div></div>
        <div class="form-text mt-3">Mallin voi tehdä omasta tai sinulle jaetusta raportista. Malli tallentaa raportin osiot ja tekstisisällöt ja näkyy tämän raportin asiakkaan jakamille käyttäjille.</div>
    </form>
</div>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Malli</th><th>Asiakas</th><th>Omistaja</th><th class="text-end">Toiminnot</th></tr></thead><tbody>
<?php if (!$templates): ?><tr><td colspan="4" class="text-body-secondary">Raporttimalleja ei ole vielä luotu.</td></tr><?php endif; ?>
<?php foreach ($templates as $template): ?><tr><td><strong><?= templateValue($template['nimi']) ?></strong></td><td><?= templateValue($template['asiakas_yritys'] ? $template['asiakas_nimi'] . ' – ' . $template['asiakas_yritys'] : $template['asiakas_nimi']) ?></td><td><?= templateValue($template['omistaja_nimi']) ?></td><td class="text-end text-nowrap"><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= templateValue($csrfToken) ?>"><input type="hidden" name="action" value="create-report"><input type="hidden" name="malli_id" value="<?= (int) $template['id'] ?>"><button class="btn btn-sm btn-primary">Luo raportti</button></form> <?php if ((int) $template['laatijat_id'] === $laatijatId): ?><form method="post" class="d-inline" onsubmit="return confirm('Poistetaanko raporttimalli?');"><input type="hidden" name="csrf_token" value="<?= templateValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-template"><input type="hidden" name="malli_id" value="<?= (int) $template['id'] ?>"><button class="btn btn-sm btn-outline-danger">Poista</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php closeLayoutShell(); ?>
