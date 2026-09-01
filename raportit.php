<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();

if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$projectId = max(0, (int) ($_GET['projekti_id'] ?? 0));
$search = trim((string) ($_GET['haku'] ?? ''));
$showAll = (isset($_GET['kaikki']) && $_GET['kaikki'] === '1') || $search !== '';
$searchTerms = $search === '' ? [] : (preg_split('/\s+/', $search) ?: []);
$currentPage = max(1, (int) ($_GET['sivu'] ?? 1));
$perPage = 25;
$reports = [];
$tablesByReport = [];
$totalReports = 0;
$loadError = false;
$csrfToken = getApplicationCsrfToken();
$projects = [];

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
    $projectQuery = $db->prepare('SELECT p.id, p.nimi FROM projektit p WHERE p.laatijat_id = ? OR EXISTS (
        SELECT 1 FROM projektijaot j WHERE j.projekti_id = p.id AND j.laatijat_id = ?
    ) ORDER BY p.nimi, p.id');
    $projectQuery->bind_param('ii', $laatijatId, $laatijatId);
    $projectQuery->execute();
    $projects = $projectQuery->get_result()->fetch_all(MYSQLI_ASSOC);
    $projectQuery->close();

    $reportWhere = 'WHERE (r.laatijat_id = ? OR EXISTS (
        SELECT 1 FROM raporttijaot j WHERE j.raportti_id = r.id AND j.laatijat_id = ?
    ) OR EXISTS (
        SELECT 1 FROM projektijaot pj WHERE pj.projekti_id = r.projekti_id AND pj.laatijat_id = ?
    ))';
    if ($projectId > 0) {
        $reportWhere .= ' AND r.projekti_id = ?';
    }
    if ($search !== '') {
        $reportWhere .= ' AND ' . implode(' AND ', array_fill(0, count($searchTerms), 'CONCAT_WS(\' \', r.otsikko, a.nimi, a.yritys) LIKE ?'));
    }

    $searchLikes = array_map(static fn (string $term): string => '%' . $term . '%', $searchTerms);

    if ($showAll) {
        $countQuery = $db->prepare(
            'SELECT COUNT(*) AS maara FROM raportit r
             INNER JOIN asiakkaat a ON a.id = r.asiakas_id ' . $reportWhere
        );
        $countTypes = 'iii';
        $countParams = [$laatijatId, $laatijatId, $laatijatId];
        if ($projectId > 0) { $countTypes .= 'i'; $countParams[] = $projectId; }
        foreach ($searchLikes as $searchLike) { $countTypes .= 's'; $countParams[] = $searchLike; }
        bindReportParams($countQuery, $countTypes, $countParams);
        $countQuery->execute();
        $totalReports = (int) $countQuery->get_result()->fetch_assoc()['maara'];
        $countQuery->close();

        $totalPages = max(1, (int) ceil($totalReports / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;

        $reportQuery = $db->prepare(
            'SELECT r.id, r.laatijat_id, r.otsikko, r.raporttipvm, a.nimi AS asiakas_nimi, a.yritys AS asiakas_yritys
             FROM raportit r
             INNER JOIN asiakkaat a ON a.id = r.asiakas_id
             ' . $reportWhere . '
             ORDER BY r.raporttipvm DESC, r.id DESC
             LIMIT ? OFFSET ?'
        );
        $reportTypes = 'iii';
        $reportParams = [$laatijatId, $laatijatId, $laatijatId];
        if ($projectId > 0) { $reportTypes .= 'i'; $reportParams[] = $projectId; }
        foreach ($searchLikes as $searchLike) { $reportTypes .= 's'; $reportParams[] = $searchLike; }
        $reportTypes .= 'ii'; $reportParams[] = $perPage; $reportParams[] = $offset;
        bindReportParams($reportQuery, $reportTypes, $reportParams);
    } else {
        $totalPages = 1;
        $reportQuery = $db->prepare(
            'SELECT r.id, r.laatijat_id, r.otsikko, r.raporttipvm, a.nimi AS asiakas_nimi, a.yritys AS asiakas_yritys
             FROM raportit r
             INNER JOIN asiakkaat a ON a.id = r.asiakas_id
             ' . $reportWhere . '
             ORDER BY r.raporttipvm DESC, r.id DESC
             LIMIT 5'
        );
        $reportTypes = 'iii';
        $reportParams = [$laatijatId, $laatijatId, $laatijatId];
        if ($projectId > 0) { $reportTypes .= 'i'; $reportParams[] = $projectId; }
        foreach ($searchLikes as $searchLike) { $reportTypes .= 's'; $reportParams[] = $searchLike; }
        bindReportParams($reportQuery, $reportTypes, $reportParams);
    }

    $reportQuery->execute();
    $result = $reportQuery->get_result();
    while ($report = $result->fetch_assoc()) {
        $reports[] = $report;
    }
    $reportQuery->close();

    $tableQuery = $db->prepare(
        'SELECT t.id, t.raportti_id, t.otsikko
         FROM raporttitaulukot t
         INNER JOIN raportit r ON r.id = t.raportti_id AND r.laatijat_id = t.laatijat_id
         WHERE r.laatijat_id = ? OR EXISTS (
             SELECT 1 FROM raporttijaot j WHERE j.raportti_id = r.id AND j.laatijat_id = ?
         ) OR EXISTS (
             SELECT 1 FROM projektijaot pj WHERE pj.projekti_id = r.projekti_id AND pj.laatijat_id = ?
         ) ORDER BY t.raportti_id, t.jarjestys, t.id'
    );
    $tableQuery->bind_param('iii', $laatijatId, $laatijatId, $laatijatId);
    $tableQuery->execute();
    $tableResult = $tableQuery->get_result();
    while ($table = $tableResult->fetch_assoc()) {
        $tablesByReport[(int) $table['raportti_id']][] = $table;
    }
    $tableQuery->close();
    foreach ($tablesByReport as &$reportTables) {
        foreach ($reportTables as &$table) {
            $table['sarakkeet'] = [];
            $columnQuery = $db->prepare('SELECT id, nimi FROM raporttitaulukko_sarakkeet WHERE taulukko_id = ? ORDER BY jarjestys, id');
            $tableId = (int) $table['id'];
            $columnQuery->bind_param('i', $tableId);
            $columnQuery->execute();
            $table['sarakkeet'] = $columnQuery->get_result()->fetch_all(MYSQLI_ASSOC);
            $columnQuery->close();
        }
        unset($table);
    }
    unset($reportTables);
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
    global $projectId, $search;
    $params = ['kaikki' => 1, 'sivu' => max(1, $page)];
    if ($projectId > 0) { $params['projekti_id'] = $projectId; }
    if ($search !== '') { $params['haku'] = $search; }
    return 'raportit.php?' . http_build_query($params);
}

function bindReportParams(mysqli_stmt $query, string $types, array $params): void
{
    $references = [];
    foreach ($params as $index => &$param) {
        $references[$index] =& $param;
    }
    $query->bind_param($types, ...$references);
}

openLayoutShell('Raportit – TEKRAPORTTI');
?>
<style>
.report-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: nowrap;
    gap: .25rem;
    white-space: nowrap;
}
.report-preview-form {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    gap: .25rem;
}
.report-preview-form .form-select {
    width: 110px;
    min-width: 110px;
}
.report-chart-options {
    position: relative;
}
.report-chart-options > summary {
    cursor: pointer;
    max-width: 180px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.report-chart-options .report-chart-columns {
    margin-top: .35rem;
}
.report-chart-menu > summary {
    cursor: pointer;
    white-space: nowrap;
}
.report-chart-menu .report-chart-options {
    margin-top: .35rem;
}
@media (max-width: 900px) {
    .report-actions {
        flex-wrap: wrap;
    }
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Raportit</h1>
        <p class="text-body-secondary mb-0">
            <?= $search !== '' ? 'Hakutulokset' : ($showAll ? 'Kaikki tallennetut raportit' : 'Viimeisimmät raportit') ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="luo_raportti.php">Luo uusi raportti</a>
        <?php if (!$showAll): ?>
            <a class="btn btn-outline-primary" href="raportit.php?kaikki=1">Hae kaikki raportit</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" action="raportit.php" class="card border-0 shadow-sm mb-4 p-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-6"><label class="form-label mb-1" for="projekti_id">Suodata projektin mukaan</label><select class="form-select" id="projekti_id" name="projekti_id"><option value="0">Kaikki projektit</option><?php foreach ($projects as $project): ?><option value="<?= (int) $project['id'] ?>" <?= $projectId === (int) $project['id'] ? 'selected' : '' ?>><?= htmlspecialchars($project['nimi'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <?php if ($search !== ''): ?><input type="hidden" name="haku" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <?php if ($showAll): ?><input type="hidden" name="kaikki" value="1"><?php endif; ?>
        <div class="col-md-auto"><button class="btn btn-outline-primary">Suodata</button></div>
    </div>
</form>

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
                        <th scope="col">Yritys</th>
                        <th scope="col">Päivämäärä</th>
                        <th scope="col" class="text-end">Toiminnot</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?= htmlspecialchars($report['otsikko'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($report['asiakas_nimi'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($report['asiakas_yritys'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars(formatReportDate($report['raporttipvm']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <div class="report-actions">
                                    <?php if (!empty($tablesByReport[(int) $report['id']])): ?><form method="get" action="esikatsele_raportti.php" target="_blank" class="report-preview-form"><input type="hidden" name="id" value="<?= (int) $report['id'] ?>"><details class="report-chart-menu"><summary>Diagrammit</summary><?php foreach ($tablesByReport[(int) $report['id']] as $table): ?><details class="report-chart-options"><summary><?= htmlspecialchars((string) ($table['otsikko'] ?: 'Taulukko ' . $table['id']), ENT_QUOTES, 'UTF-8') ?></summary><label class="visually-hidden" for="raportti-<?= (int) $report['id'] ?>-kaavio-<?= (int) $table['id'] ?>">Kaavio taulukolle <?= htmlspecialchars((string) ($table['otsikko'] ?: $table['id']), ENT_QUOTES, 'UTF-8') ?></label><select class="form-select form-select-sm" id="raportti-<?= (int) $report['id'] ?>-kaavio-<?= (int) $table['id'] ?>" name="kaavio[<?= (int) $table['id'] ?>]" title="Valitse taulukon kaavio"><option value="">Ei kaaviota</option><option value="pie">Piirakka</option><option value="bar">Pylväs</option><option value="line">Viiva</option></select><details class="small report-chart-columns"><summary>Sarakkeet</summary><?php foreach (array_slice($table['sarakkeet'], 1) as $column): ?><label class="d-block"><input type="checkbox" name="kaavio_sarakkeet[<?= (int) $table['id'] ?>][]" value="<?= (int) $column['id'] ?>" checked> <?= htmlspecialchars((string) $column['nimi'], ENT_QUOTES, 'UTF-8') ?></label><?php endforeach; ?></details></details><?php endforeach; ?></details><button class="btn btn-outline-secondary btn-sm" type="submit" title="Avaa PDF-esikatselu" aria-label="Avaa PDF-esikatselu"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 0h5.5L14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2m5 1.5V5h3.5zM4.5 9.5a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1zM4 12a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1z"/></svg><span class="d-none d-md-inline ms-1">Näytä</span></button></form><?php else: ?><a class="btn btn-outline-secondary btn-sm" href="esikatsele_raportti.php?id=<?= (int) $report['id'] ?>" target="_blank" title="Avaa PDF-esikatselu" aria-label="Avaa PDF-esikatselu">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 0h5.5L14 4.5V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2m5 1.5V5h3.5zM4.5 9.5a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1zM4 12a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1z"/></svg><span class="d-none d-md-inline ms-1">Näytä</span>
                                    </a><?php endif; ?>
                                    <a class="btn btn-outline-primary btn-sm" href="muokkaa_raportti.php?id=<?= (int) $report['id'] ?>" title="Muokkaa raporttia" aria-label="Muokkaa raporttia">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M12.854.146a.5.5 0 0 1 .707 0l2.293 2.293a.5.5 0 0 1 0 .707l-9.5 9.5L3 13l.354-3.354zM2.5 13.5l-.5 2 2-.5z"/></svg><span class="d-none d-md-inline ms-1">Muokkaa</span>
                                    </a>
                                    <?php if ((int) $report['laatijat_id'] === $laatijatId): ?><form method="post" action="raportit.php<?= $showAll ? '?kaikki=1&sivu=' . $currentPage : '' ?>" class="d-inline" onsubmit="return confirm('Haluatko todella poistaa raportin ja kaikki siihen liittyvät tiedot?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete-report">
                                        <input type="hidden" name="raportti_id" value="<?= (int) $report['id'] ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit" title="Poista raportti" aria-label="Poista raportti">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0A.5.5 0 0 1 8.5 6v6a.5.5 0 0 1-1 0V6A.5.5 0 0 1 8 5.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2h3.086A1.5 1.5 0 0 1 7 1h2a1.5 1.5 0 0 1 1.414 1H13.5a1 1 0 0 1 1 1M4 4v9a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg><span class="d-none d-md-inline ms-1">Poista</span>
                                        </button>
                                    </form><?php endif; ?>
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
