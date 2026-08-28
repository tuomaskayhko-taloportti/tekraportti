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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><a href="muokkaa_raportti.php?id=<?= (int) $report['id'] ?>">
                                <?= htmlspecialchars($report['otsikko'], ENT_QUOTES, 'UTF-8') ?>
                            </a></td>
                            <td><?= htmlspecialchars($report['asiakas_nimi'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(formatReportDate($report['raporttipvm']), ENT_QUOTES, 'UTF-8') ?></td>
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
