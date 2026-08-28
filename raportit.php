<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();

if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$showAll = isset($_GET['kaikki']) && $_GET['kaikki'] === '1';
$currentPage = max(1, (int) ($_GET['sivu'] ?? 1));
$perPage = 25;
$reports = [];
$totalReports = 0;
$loadError = false;
$csrfToken = getApplicationCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'delete-report') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $reportId = (int) ($_POST['raportti_id'] ?? 0);
    if (!hash_equals($csrfToken, $postedToken)) {
        $loadError = true;
    } elseif ($reportId < 1) {
        $loadError = true;
    } else {
        $mediaFiles = [];
        $coverFile = '';
        $deleteSucceeded = false;
        try {
            $db = getDatabaseConnection();
            $db->begin_transaction();

            $query = $db->prepare('SELECT kansikuva FROM raportit WHERE id = ? AND laatijat_id = ? LIMIT 1');
            $query->bind_param('ii', $reportId, $laatijatId);
            $query->execute();
            $reportToDelete = $query->get_result()->fetch_assoc();
            $query->close();
            if (!$reportToDelete) {
                throw new RuntimeException('Raporttia ei löytynyt.');
            }
            $coverFile = (string) ($reportToDelete['kansikuva'] ?? '');

            $query = $db->prepare('SELECT url FROM raporttimedia WHERE raportti_id = ? AND laatijat_id = ?');
            $query->bind_param('ii', $reportId, $laatijatId);
            $query->execute();
            $mediaFiles = $query->get_result()->fetch_all(MYSQLI_ASSOC);
            $query->close();

            $query = $db->prepare('DELETE FROM raporttimedia WHERE raportti_id = ? AND laatijat_id = ?');
            $query->bind_param('ii', $reportId, $laatijatId);
            $query->execute();
            $query->close();
            $query = $db->prepare('DELETE FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ?');
            $query->bind_param('ii', $reportId, $laatijatId);
            $query->execute();
            $query->close();
            $query = $db->prepare('DELETE FROM raportit WHERE id = ? AND laatijat_id = ?');
            $query->bind_param('ii', $reportId, $laatijatId);
            $query->execute();
            $query->close();
            $db->commit();
            $deleteSucceeded = true;
        } catch (Throwable $error) {
            if (isset($db) && $db instanceof mysqli) {
                $db->rollback();
            }
            $loadError = true;
            error_log('Raportin poisto epäonnistui: ' . $error->getMessage());
        }

        if ($deleteSucceeded) {
            deleteStoredFile($coverFile);
            foreach ($mediaFiles as $mediaFile) {
                deleteStoredFile((string) $mediaFile['url']);
            }
            $redirect = 'raportit.php';
            if ($showAll) {
                $redirect .= '?kaikki=1&sivu=' . $currentPage;
            }
            header('Location: ' . $redirect);
            exit;
        }
    }
}

try {
    $db = getDatabaseConnection();

    if ($showAll) {
        $countQuery = $db->prepare(
            'SELECT COUNT(*) AS maara FROM raportit WHERE laatijat_id = ?'
        );
        $countQuery->bind_param('i', $laatijatId);
        $countQuery->execute();
        $totalReports = (int) $countQuery->get_result()->fetch_assoc()['maara'];
        $countQuery->close();

        $totalPages = max(1, (int) ceil($totalReports / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;

        $reportQuery = $db->prepare(
            'SELECT r.id, r.otsikko, r.raporttipvm, a.nimi AS asiakas_nimi
             FROM raportit r
             INNER JOIN asiakkaat a ON a.id = r.asiakas_id AND a.laatijat_id = r.laatijat_id
             WHERE r.laatijat_id = ?
             ORDER BY r.raporttipvm DESC, r.id DESC
             LIMIT ? OFFSET ?'
        );
        $reportQuery->bind_param('iii', $laatijatId, $perPage, $offset);
    } else {
        $totalPages = 1;
        $reportQuery = $db->prepare(
            'SELECT r.id, r.otsikko, r.raporttipvm, a.nimi AS asiakas_nimi
             FROM raportit r
             INNER JOIN asiakkaat a ON a.id = r.asiakas_id AND a.laatijat_id = r.laatijat_id
             WHERE r.laatijat_id = ?
             ORDER BY r.raporttipvm DESC, r.id DESC
             LIMIT 5'
        );
        $reportQuery->bind_param('i', $laatijatId);
    }

    $reportQuery->execute();
    $result = $reportQuery->get_result();
    while ($report = $result->fetch_assoc()) {
        $reports[] = $report;
    }
    $reportQuery->close();
} catch (Throwable $error) {
    $loadError = true;
    error_log('Raporttien haku epäonnistui: ' . $error->getMessage());
}

function formatReportDate(string $date): string
{
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObject ? $dateObject->format('d.m.Y') : $date;
}

function reportPageUrl(int $page): string
{
    return 'raportit.php?kaikki=1&sivu=' . max(1, $page);
}

openLayoutShell('Raportit – TEKRAPORTTI');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Raportit</h1>
        <p class="text-body-secondary mb-0">
            <?= $showAll ? 'Kaikki tallennetut raportit' : 'Viimeisimmät raportit' ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="luo_raportti.php">Luo uusi raportti</a>
        <?php if (!$showAll): ?>
            <a class="btn btn-outline-primary" href="raportit.php?kaikki=1">Hae kaikki raportit</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($loadError): ?>
    <div class="alert alert-danger" role="alert">
        Raporttien haku epäonnistui. Yritä hetken kuluttua uudelleen.
    </div>
<?php elseif (!$reports): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body py-5 text-center text-body-secondary">
            Ei tallennettuja raportteja.
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Raportti</th>
                        <th scope="col">Asiakas</th>
                        <th scope="col">Päivämäärä</th>
                        <th scope="col" class="text-end">Toiminnot</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?= htmlspecialchars($report['otsikko'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($report['asiakas_nimi'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(formatReportDate($report['raporttipvm']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <div class="d-flex justify-content-end gap-1">
                                    <a class="btn btn-outline-secondary btn-sm" href="esikatsele_raportti.php?id=<?= (int) $report['id'] ?>" title="Avaa PDF-esikatselu" aria-label="Avaa PDF-esikatselu">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 0h5.5L14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2m5 1.5V5h3.5zM4.5 9.5a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1zM4 12a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1z"/></svg>
                                    </a>
                                    <a class="btn btn-outline-primary btn-sm" href="muokkaa_raportti.php?id=<?= (int) $report['id'] ?>" title="Muokkaa raporttia" aria-label="Muokkaa raporttia">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M12.854.146a.5.5 0 0 1 .707 0l2.293 2.293a.5.5 0 0 1 0 .707l-9.5 9.5L3 13l.354-3.354zM2.5 13.5l-.5 2 2-.5z"/></svg>
                                    </a>
                                    <form method="post" action="raportit.php<?= $showAll ? '?kaikki=1&sivu=' . $currentPage : '' ?>" class="d-inline" onsubmit="return confirm('Haluatko todella poistaa raportin ja kaikki siihen liittyvät tiedot?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete-report">
                                        <input type="hidden" name="raportti_id" value="<?= (int) $report['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit" title="Poista raportti" aria-label="Poista raportti">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0A.5.5 0 0 1 8.5 6v6a.5.5 0 0 1-1 0V6A.5.5 0 0 1 8 5.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2h3.086A1.5 1.5 0 0 1 7 1h2a1.5 1.5 0 0 1 1.414 1H13.5a1 1 0 0 1 1 1M4 4v9a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($showAll && $totalPages > 1): ?>
        <nav class="d-flex justify-content-between align-items-center mt-3" aria-label="Raporttisivut">
            <?php if ($currentPage > 1): ?>
                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(reportPageUrl($currentPage - 1), ENT_QUOTES, 'UTF-8') ?>">
                    ← Edelliset
                </a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            <span class="text-body-secondary small">Sivu <?= $currentPage ?> / <?= $totalPages ?></span>
            <?php if ($currentPage < $totalPages): ?>
                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(reportPageUrl($currentPage + 1), ENT_QUOTES, 'UTF-8') ?>">
                    Seuraavat →
                </a>
            <?php else: ?>
                <span></span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php closeLayoutShell(); ?>
