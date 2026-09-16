<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();
if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

// Muokkauslomakkeissa on istuntokohtainen CSRF-tunniste ja tiedostokenttiä.
// Estä selainta käyttämästä poiston tai tallennuksen jälkeen vanhaa lomakesivua.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$laatijatId = (int) $_SESSION['laatijat_id'];
$currentLaatijaId = $laatijatId;
$raporttiId = (int) ($_GET['id'] ?? $_POST['raportti_id'] ?? 0);
$editMediaId = (int) ($_GET['edit_media'] ?? 0);
$csrfToken = getApplicationCsrfToken();
$message = '';
$messageType = 'danger';
$newMediaId = 0;
$report = null;
$customers = [];
$projects = [];
$sharedEmails = [];
$rows = [];
$media = [];
$attachments = [];
$tables = [];
$texts = [];
$summaryRows = [];
if ((string) ($_GET['saved'] ?? '') === '1') {
    $message = 'Muutokset tallennettiin.';
    $messageType = 'success';
}

try {
    $db = getDatabaseConnection();
    $query = $db->prepare(
        'SELECT r.*, a.nimi AS asiakas_nimi, p.nimi AS projekti_nimi
         FROM raportit r
         INNER JOIN asiakkaat a ON a.id = r.asiakas_id
         LEFT JOIN projektit p ON p.id = r.projekti_id
         WHERE r.id = ? AND (r.laatijat_id = ? OR EXISTS (
             SELECT 1 FROM raporttijaot j WHERE j.raportti_id = r.id AND j.laatijat_id = ?
         ) OR EXISTS (
             SELECT 1 FROM projektijaot pj WHERE pj.projekti_id = r.projekti_id AND pj.laatijat_id = ?
         )) LIMIT 1'
    );
    $query->bind_param('iiii', $raporttiId, $currentLaatijaId, $currentLaatijaId, $currentLaatijaId);
    $query->execute();
    $report = $query->get_result()->fetch_assoc();
    $query->close();
    if ($report) {
        $laatijatId = (int) $report['laatijat_id'];
        $shareQuery = $db->prepare(
            'SELECT l.sahkoposti FROM raporttijaot j
             INNER JOIN laatijat l ON l.id = j.laatijat_id
             WHERE j.raportti_id = ? ORDER BY j.id'
        );
        $shareQuery->bind_param('i', $raporttiId);
        $shareQuery->execute();
        $shareResult = $shareQuery->get_result();
        while ($share = $shareResult->fetch_assoc()) {
            $sharedEmails[] = (string) $share['sahkoposti'];
        }
        $shareQuery->close();
    }
} catch (Throwable $error) {
    $message = 'Raportin haku epäonnistui.';
    error_log('Muokattavan raportin haku epäonnistui: ' . $error->getMessage());
}

if (!$report) {
    http_response_code(404);
    openLayoutShell('Raporttia ei löytynyt – TEKRAPORTTI');
    echo '<div class="alert alert-danger">Raporttia ei löytynyt.</div>';
    closeLayoutShell();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    // PHP hylkää koko multipart-lomakkeen, jos post_max_size ylittyy.
    // Tällöin myös CSRF-kenttä puuttuu ja virhe näkyisi muuten harhaanjohtavasti
    // vanhentuneena pyyntönä.
    $postMaxSize = (string) ini_get('post_max_size');
    $postMaxBytes = static function (string $value): int {
        $value = trim($value);
        if ($value === '') { return 0; }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        return (int) round($number * match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        });
    };
    if (($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && $postMaxBytes($postMaxSize) > 0
        && (int) $_SERVER['CONTENT_LENGTH'] > $postMaxBytes($postMaxSize)) {
        $message = 'Lähetettävä tiedosto on liian suuri palvelimen asetuksiin nähden. Valitse pienempi kuva tai suurenna PHP:n post_max_size-asetusta.';
    } elseif (!hash_equals($csrfToken, $postedToken)) {
        $message = 'Pyyntö vanheni. Lataa sivu uudelleen ja yritä uudestaan.';
    } else {
        try {
            $db = getDatabaseConnection();
            if ($action === 'update-report') {
                $customerId = (int) ($_POST['asiakas_id'] ?? 0);
                $projectId = (int) ($_POST['projekti_id'] ?? 0);
                $title = trim((string) ($_POST['otsikko'] ?? ''));
                $date = trim((string) ($_POST['raporttipvm'] ?? ''));
                $place = trim((string) ($_POST['laatimispaikka'] ?? ''));
                $summary = trim((string) ($_POST['tiivistelma'] ?? ''));
                $cover = trim((string) ($_POST['kansikuva'] ?? ''));
                $oldSharedEmails = array_map('strtolower', $sharedEmails);
                $sharedEmails = parseReportShareEmails((string) ($_POST['jaettava_sahkoposti'] ?? ''));
                $oldCover = trim((string) ($report['kansikuva'] ?? ''));
                $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
                if ($customerId < 1 || $title === '' || !$dateObject || $dateObject->format('Y-m-d') !== $date) {
                    throw new RuntimeException('Täytä asiakas, otsikko ja voimassa oleva päivämäärä.');
                }
                $query = $db->prepare('SELECT a.id FROM asiakkaat a WHERE a.id = ? AND (a.laatijat_id = ? OR EXISTS (
                    SELECT 1 FROM asiakasjaot j WHERE j.asiakas_id = a.id AND j.laatijat_id = ?
                ) OR EXISTS (
                    SELECT 1 FROM raportit rs INNER JOIN raporttijaot rj ON rj.raportti_id = rs.id
                    WHERE rs.asiakas_id = a.id AND rj.laatijat_id = ?
                )) LIMIT 1');
                $query->bind_param('iiii', $customerId, $currentLaatijaId, $currentLaatijaId, $currentLaatijaId);
                $query->execute();
                $customerExists = (bool) $query->get_result()->fetch_assoc();
                $query->close();
                if (!$customerExists) {
                    throw new RuntimeException('Valittu asiakas ei ole käytettävissä.');
                }
                $projectValue = $projectId > 0 ? $projectId : null;
                $placeValue = $place !== '' ? $place : null;
                $summaryValue = $summary !== '' ? $summary : null;
                $coverValue = $cover !== '' ? $cover : ($oldCover !== '' ? $oldCover : null);
                if (isset($_FILES['kansikuva']) && ($_FILES['kansikuva']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $coverValue = storeUploadedFile($_FILES['kansikuva'], 'kansikuvat');
                }
                if ($projectValue !== null) {
                    $query = $db->prepare('SELECT p.id FROM projektit p WHERE p.id = ? AND p.asiakas_id = ? AND (p.laatijat_id = ? OR EXISTS (
                        SELECT 1 FROM projektijaot j WHERE j.projekti_id = p.id AND j.laatijat_id = ?
                    )) LIMIT 1');
                    $query->bind_param('iiii', $projectValue, $customerId, $laatijatId, $currentLaatijaId);
                    $query->execute();
                    $projectExists = (bool) $query->get_result()->fetch_assoc();
                    $query->close();
                    if (!$projectExists) {
                        throw new RuntimeException('Valittu projekti ei ole käytettävissä.');
                    }
                }
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
                if ($projectValue === null) {
                    $query = $db->prepare(
                        'UPDATE raportit SET asiakas_id = ?, projekti_id = NULL, otsikko = ?, raporttipvm = ?,
                         laatimispaikka = ?, tiivistelma = ?, kansikuva = ? WHERE id = ? AND laatijat_id = ?'
                    );
                    $query->bind_param('isssssii', $customerId, $title, $date, $placeValue, $summaryValue, $coverValue, $raporttiId, $laatijatId);
                } else {
                    $query = $db->prepare(
                        'UPDATE raportit SET asiakas_id = ?, projekti_id = ?, otsikko = ?, raporttipvm = ?,
                         laatimispaikka = ?, tiivistelma = ?, kansikuva = ? WHERE id = ? AND laatijat_id = ?'
                    );
                    $query->bind_param('iisssssii', $customerId, $projectValue, $title, $date, $placeValue, $summaryValue, $coverValue, $raporttiId, $laatijatId);
                }
                $query->execute();
                $query->close();
                if ($oldCover !== '' && $oldCover !== $coverValue) {
                    deleteStoredFile($oldCover);
                }
                $query = $db->prepare('DELETE FROM raporttijaot WHERE raportti_id = ?');
                $query->bind_param('i', $raporttiId);
                $query->execute();
                $query->close();
                foreach ($sharedLaatijat as $sharedLaatija) {
                    $sharedLaatijaId = $sharedLaatija['id'];
                    $query = $db->prepare('INSERT INTO raporttijaot (raportti_id, laatijat_id) VALUES (?, ?)');
                    $query->bind_param('ii', $raporttiId, $sharedLaatijaId);
                    $query->execute();
                    $query->close();
                }
                $newSharedEmails = array_diff($sharedEmails, $oldSharedEmails);
                if ($newSharedEmails) {
                    $sharer = getCurrentLaatija() ?: [];
                    foreach ($newSharedEmails as $sharedEmail) {
                        sendReportShareNotification(
                            $sharedEmail,
                            $title,
                            (string) ($sharer['nimi'] ?? ''),
                            (string) ($sharer['yritys'] ?? '')
                        );
                    }
                }
                $message = 'Raportin perustiedot tallennettiin.';
                $messageType = 'success';
            } elseif ($action === 'add-row') {
                $rowTitle = trim((string) ($_POST['rivi_otsikko'] ?? ''));
                $rowContent = trim((string) ($_POST['rivi_sisalto'] ?? ''));
                $includeCustomerInfo = isset($_POST['sisaltaa_asiakastiedot']) ? 1 : 0;
                if ($rowTitle === '') {
                    throw new RuntimeException('Anna osiolle otsikko.');
                }
                $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $raporttiId, $laatijatId);
                $query->execute();
                $order = (int) $query->get_result()->fetch_assoc()['seuraava'];
                $query->close();
                $query = $db->prepare('INSERT INTO raporttirivit (laatijat_id, raportti_id, otsikko, sisalto, sisaltaa_asiakastiedot, jarjestys) VALUES (?, ?, ?, ?, ?, ?)');
                $query->bind_param('iissii', $laatijatId, $raporttiId, $rowTitle, $rowContent, $includeCustomerInfo, $order);
                $query->execute();
                $query->close();
                $message = 'Osio lisättiin.';
                $messageType = 'success';
            } elseif ($action === 'update-row') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $rowTitle = trim((string) ($_POST['rivi_otsikko'] ?? ''));
                $rowContent = trim((string) ($_POST['rivi_sisalto'] ?? ''));
                $includeCustomerInfo = isset($_POST['sisaltaa_asiakastiedot']) ? 1 : 0;
                if ($rowId < 1 || $rowTitle === '') {
                    throw new RuntimeException('Osion otsikko puuttuu.');
                }
                $query = $db->prepare('UPDATE raporttirivit SET otsikko = ?, sisalto = ?, sisaltaa_asiakastiedot = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('ssiiii', $rowTitle, $rowContent, $includeCustomerInfo, $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                $message = 'Osio tallennettiin.';
                $messageType = 'success';
            } elseif ($action === 'copy-row') {
                $sourceRowId = (int) ($_POST['rivi_id'] ?? 0);
                $query = $db->prepare('SELECT otsikko, sisalto, sisaltaa_asiakastiedot FROM raporttirivit WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $sourceRowId, $raporttiId, $laatijatId); $query->execute();
                $sourceRow = $query->get_result()->fetch_assoc(); $query->close();
                if (!$sourceRow) { throw new RuntimeException('Kopioitavaa osiota ei löytynyt.'); }

                $copiedFiles = [];
                $db->begin_transaction();
                try {
                    $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ?');
                    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute();
                    $newOrder = (int) $query->get_result()->fetch_assoc()['seuraava']; $query->close();
                    $newTitle = mb_substr((string) $sourceRow['otsikko'] . ' (kopio)', 0, 255);
                    $query = $db->prepare('INSERT INTO raporttirivit (laatijat_id, raportti_id, otsikko, sisalto, sisaltaa_asiakastiedot, jarjestys) VALUES (?, ?, ?, ?, ?, ?)');
                    $includeCustomerInfo = (int) $sourceRow['sisaltaa_asiakastiedot'];
                    $sourceContent = (string) $sourceRow['sisalto'];
                    $query->bind_param('iissii', $laatijatId, $raporttiId, $newTitle, $sourceContent, $includeCustomerInfo, $newOrder); $query->execute();
                    $newRowId = (int) $db->insert_id; $query->close();

                    $query = $db->prepare('SELECT tyyppi, url, kuvateksti, merkinnat_json, jarjestys FROM raporttimedia WHERE raporttirivi_id = ? AND raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
                    $query->bind_param('iii', $sourceRowId, $raporttiId, $laatijatId); $query->execute(); $mediaResult = $query->get_result();
                    $mediaItems = $mediaResult->fetch_all(MYSQLI_ASSOC); $query->close();
                    foreach ($mediaItems as $mediaItem) {
                        $mediaUrl = copyStoredFile((string) ($mediaItem['url'] ?? ''));
                        if ($mediaUrl !== (string) ($mediaItem['url'] ?? '')) { $copiedFiles[] = $mediaUrl; }
                        $query = $db->prepare('INSERT INTO raporttimedia (laatijat_id, raportti_id, raporttirivi_id, tyyppi, url, kuvateksti, merkinnat_json, jarjestys) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $mediaType = (string) $mediaItem['tyyppi']; $mediaCaption = (string) $mediaItem['kuvateksti']; $mediaAnnotations = (string) $mediaItem['merkinnat_json']; $mediaOrder = (int) $mediaItem['jarjestys'];
                        $query->bind_param('iiissssi', $laatijatId, $raporttiId, $newRowId, $mediaType, $mediaUrl, $mediaCaption, $mediaAnnotations, $mediaOrder); $query->execute(); $query->close();
                    }

                    $query = $db->prepare('SELECT id, otsikko, reunat, leveys_tila, tasaus_pysty, tasaus_vaaka, jarjestys FROM raporttitaulukot WHERE raporttirivi_id = ? AND raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
                    $query->bind_param('iii', $sourceRowId, $raporttiId, $laatijatId); $query->execute(); $tableItems = $query->get_result()->fetch_all(MYSQLI_ASSOC); $query->close();
                    foreach ($tableItems as $tableItem) {
                        $sourceTableId = (int) $tableItem['id'];
                        $query = $db->prepare('INSERT INTO raporttitaulukot (laatijat_id, raportti_id, raporttirivi_id, otsikko, reunat, leveys_tila, tasaus_pysty, tasaus_vaaka, jarjestys) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $tableTitle = (string) $tableItem['otsikko']; $tableBorders = (int) $tableItem['reunat']; $tableWidth = (string) $tableItem['leveys_tila']; $tableVertical = (string) $tableItem['tasaus_pysty']; $tableHorizontal = (string) $tableItem['tasaus_vaaka']; $tableOrder = (int) $tableItem['jarjestys'];
                        $query->bind_param('iiisisssi', $laatijatId, $raporttiId, $newRowId, $tableTitle, $tableBorders, $tableWidth, $tableVertical, $tableHorizontal, $tableOrder);
                        $query->execute(); $newTableId = (int) $db->insert_id; $query->close();
                        $columnMap = []; $tableRowMap = [];
                        $query = $db->prepare('SELECT id, nimi, taustavari, fonttivari, leveys, jarjestys FROM raporttitaulukko_sarakkeet WHERE taulukko_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
                        $query->bind_param('ii', $sourceTableId, $laatijatId); $query->execute(); $columns = $query->get_result()->fetch_all(MYSQLI_ASSOC); $query->close();
                        foreach ($columns as $column) {
                            $query = $db->prepare('INSERT INTO raporttitaulukko_sarakkeet (laatijat_id, taulukko_id, nimi, taustavari, fonttivari, leveys, jarjestys) VALUES (?, ?, ?, ?, ?, ?, ?)');
                            $columnName = (string) $column['nimi']; $columnColor = (string) $column['taustavari']; $columnFontColor = (string) $column['fonttivari']; $columnWidth = $column['leveys'] !== null ? (float) $column['leveys'] : null; $columnOrder = (int) $column['jarjestys'];
                            $query->bind_param('iisssdi', $laatijatId, $newTableId, $columnName, $columnColor, $columnFontColor, $columnWidth, $columnOrder); $query->execute();
                            $columnMap[(int) $column['id']] = (int) $db->insert_id; $query->close();
                        }
                        $query = $db->prepare('SELECT id, jarjestys FROM raporttitaulukko_rivit WHERE taulukko_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
                        $query->bind_param('ii', $sourceTableId, $laatijatId); $query->execute(); $tableRows = $query->get_result()->fetch_all(MYSQLI_ASSOC); $query->close();
                        foreach ($tableRows as $tableRow) {
                            $query = $db->prepare('INSERT INTO raporttitaulukko_rivit (laatijat_id, taulukko_id, jarjestys) VALUES (?, ?, ?)');
                            $tableRowOrder = (int) $tableRow['jarjestys'];
                            $query->bind_param('iii', $laatijatId, $newTableId, $tableRowOrder); $query->execute(); $tableRowMap[(int) $tableRow['id']] = (int) $db->insert_id; $query->close();
                        }
                        $query = $db->prepare('SELECT rivi_id, sarake_id, sisalto FROM raporttitaulukko_solut WHERE taulukko_id = ? AND laatijat_id = ?');
                        $query->bind_param('ii', $sourceTableId, $laatijatId); $query->execute(); $cells = $query->get_result()->fetch_all(MYSQLI_ASSOC); $query->close();
                        foreach ($cells as $cell) {
                            if (!isset($tableRowMap[(int) $cell['rivi_id']], $columnMap[(int) $cell['sarake_id']])) { continue; }
                            $newTableRowId = $tableRowMap[(int) $cell['rivi_id']]; $newColumnId = $columnMap[(int) $cell['sarake_id']];
                            $query = $db->prepare('INSERT INTO raporttitaulukko_solut (laatijat_id, taulukko_id, rivi_id, sarake_id, sisalto) VALUES (?, ?, ?, ?, ?)');
                            $cellContent = (string) $cell['sisalto'];
                            $query->bind_param('iiiis', $laatijatId, $newTableId, $newTableRowId, $newColumnId, $cellContent); $query->execute(); $query->close();
                        }
                        $summaryMap = [];
                        $query = $db->prepare('SELECT id, nimittaja, tyyppi, jalkeen_rivi_id, jarjestys FROM raporttitaulukko_yhteenvetorivit WHERE taulukko_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
                        $query->bind_param('ii', $sourceTableId, $laatijatId); $query->execute(); $summaries = $query->get_result()->fetch_all(MYSQLI_ASSOC); $query->close();
                        foreach ($summaries as $summary) {
                            $afterRowId = isset($tableRowMap[(int) $summary['jalkeen_rivi_id']]) ? $tableRowMap[(int) $summary['jalkeen_rivi_id']] : null;
                            $afterRowIdValue = $afterRowId ?? 0;
                            $summaryName = trim((string) ($summary['nimittaja'] ?? '')) ?: 'Yhteensä'; $summaryType = (string) $summary['tyyppi']; $summaryOrder = (int) $summary['jarjestys'];
                            $query = $db->prepare('INSERT INTO raporttitaulukko_yhteenvetorivit (laatijat_id, taulukko_id, nimittaja, tyyppi, jalkeen_rivi_id, jarjestys) VALUES (?, ?, ?, ?, NULLIF(?, 0), ?)');
                            $query->bind_param('iissii', $laatijatId, $newTableId, $summaryName, $summaryType, $afterRowIdValue, $summaryOrder); $query->execute(); $newSummaryId = (int) $db->insert_id; $query->close(); $summaryMap[(int) $summary['id']] = $newSummaryId;
                            $query = $db->prepare('SELECT sarake_id FROM raporttitaulukko_yhteenvetorivi_sarakkeet WHERE yhteenvetorivi_id = ? AND laatijat_id = ?');
                            $query->bind_param('ii', $summary['id'], $laatijatId); $query->execute(); $summaryColumns = $query->get_result()->fetch_all(MYSQLI_ASSOC); $query->close();
                            foreach ($summaryColumns as $summaryColumn) {
                                if (!isset($columnMap[(int) $summaryColumn['sarake_id']])) { continue; }
                                $newColumnId = $columnMap[(int) $summaryColumn['sarake_id']]; $query = $db->prepare('INSERT INTO raporttitaulukko_yhteenvetorivi_sarakkeet (laatijat_id, yhteenvetorivi_id, sarake_id) VALUES (?, ?, ?)');
                                $query->bind_param('iii', $laatijatId, $newSummaryId, $newColumnId); $query->execute(); $query->close();
                            }
                        }
                        $query = $db->prepare('SELECT yhteenvetorivi_id, nimittaja, yksikot, toiminto, lahde_sarake_id, jarjestys FROM raporttitaulukko_laskentarivit WHERE taulukko_id = ? AND laatijat_id = ?');
                        $query->bind_param('ii', $sourceTableId, $laatijatId); $query->execute(); $calculations = $query->get_result()->fetch_all(MYSQLI_ASSOC); $query->close();
                        foreach ($calculations as $calculation) {
                            if (!isset($summaryMap[(int) $calculation['yhteenvetorivi_id']], $columnMap[(int) $calculation['lahde_sarake_id']])) { continue; }
                            $newSummaryId = $summaryMap[(int) $calculation['yhteenvetorivi_id']]; $newColumnId = $columnMap[(int) $calculation['lahde_sarake_id']];
                            $query = $db->prepare('INSERT INTO raporttitaulukko_laskentarivit (laatijat_id, taulukko_id, yhteenvetorivi_id, nimittaja, yksikot, toiminto, lahde_sarake_id, jarjestys) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                            $calculationName = (string) $calculation['nimittaja']; $calculationUnits = (float) $calculation['yksikot']; $calculationOperation = (string) $calculation['toiminto']; $calculationOrder = (int) $calculation['jarjestys'];
                            $query->bind_param('iiisdsii', $laatijatId, $newTableId, $newSummaryId, $calculationName, $calculationUnits, $calculationOperation, $newColumnId, $calculationOrder); $query->execute(); $query->close();
                        }
                    }

                    $query = $db->prepare('SELECT sisalto, jarjestys FROM raporttitekstit WHERE raporttirivi_id = ? AND raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
                    $query->bind_param('iii', $sourceRowId, $raporttiId, $laatijatId);
                    $query->execute();
                    $textItems = $query->get_result()->fetch_all(MYSQLI_ASSOC);
                    $query->close();
                    foreach ($textItems as $textItem) {
                        $textContent = (string) $textItem['sisalto'];
                        $textOrder = (int) $textItem['jarjestys'];
                        $query = $db->prepare('INSERT INTO raporttitekstit (laatijat_id, raportti_id, raporttirivi_id, sisalto, jarjestys) VALUES (?, ?, ?, ?, ?)');
                        $query->bind_param('iiisi', $laatijatId, $raporttiId, $newRowId, $textContent, $textOrder);
                        $query->execute();
                        $query->close();
                    }
                    $db->commit();
                } catch (Throwable $error) {
                    $db->rollback();
                    foreach ($copiedFiles as $copiedFile) { deleteStoredFile($copiedFile); }
                    throw $error;
                }
                $_POST['return_anchor'] = 'osio-' . $newRowId;
                $message = 'Osio kopioitiin.'; $messageType = 'success';
            } elseif ($action === 'move-row') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $direction = (string) ($_POST['suunta'] ?? '');
                if ($rowId < 1 || !in_array($direction, ['up', 'down'], true)) {
                    throw new RuntimeException('Osion siirto epäonnistui.');
                }
                $query = $db->prepare('SELECT id, jarjestys FROM raporttirivit WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $currentRow = $query->get_result()->fetch_assoc();
                $query->close();
                if (!$currentRow) {
                    throw new RuntimeException('Osio ei kuulu raporttiin.');
                }
                $currentOrder = (int) $currentRow['jarjestys'];
                $comparison = $direction === 'up' ? '<' : '>';
                $sort = $direction === 'up' ? 'DESC' : 'ASC';
                $query = $db->prepare("SELECT id, jarjestys FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ? AND jarjestys {$comparison} ? ORDER BY jarjestys {$sort}, id {$sort} LIMIT 1");
                $query->bind_param('iii', $raporttiId, $laatijatId, $currentOrder);
                $query->execute();
                $neighbor = $query->get_result()->fetch_assoc();
                $query->close();
                if ($neighbor) {
                    $neighborId = (int) $neighbor['id'];
                    $neighborOrder = (int) $neighbor['jarjestys'];
                    $db->begin_transaction();
                    try {
                        $query = $db->prepare('UPDATE raporttirivit SET jarjestys = 0 WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                        $query->bind_param('iii', $rowId, $raporttiId, $laatijatId); $query->execute(); $query->close();
                        $query = $db->prepare('UPDATE raporttirivit SET jarjestys = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                        $query->bind_param('iiii', $currentOrder, $neighborId, $raporttiId, $laatijatId); $query->execute(); $query->close();
                        $query = $db->prepare('UPDATE raporttirivit SET jarjestys = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                        $query->bind_param('iiii', $neighborOrder, $rowId, $raporttiId, $laatijatId); $query->execute(); $query->close();
                        $db->commit();
                    } catch (Throwable $error) {
                        $db->rollback();
                        throw $error;
                    }
                }
                $message = 'Osion järjestys päivitettiin.';
                $messageType = 'success';
            } elseif ($action === 'add-table') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $tableTitle = trim((string) ($_POST['taulukko_otsikko'] ?? ''));
                if ($rowId < 1) {
                    throw new RuntimeException('Valittu osio puuttuu.');
                }
                $query = $db->prepare('SELECT id FROM raporttirivit WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $rowExists = (bool) $query->get_result()->fetch_assoc();
                $query->close();
                if (!$rowExists) {
                    throw new RuntimeException('Valittu osio ei kuulu raporttiin.');
                }
                $query = $db->prepare(
                    'SELECT GREATEST(
                        COALESCE((SELECT MAX(jarjestys) FROM raporttitaulukot WHERE raporttirivi_id = ? AND laatijat_id = ?), 0),
                        COALESCE((SELECT MAX(jarjestys) FROM raporttitekstit WHERE raporttirivi_id = ? AND laatijat_id = ?), 0)
                     ) + 1 AS seuraava'
                );
                $query->bind_param('iiii', $rowId, $laatijatId, $rowId, $laatijatId);
                $query->execute();
                $order = (int) $query->get_result()->fetch_assoc()['seuraava'];
                $query->close();
                $query = $db->prepare('INSERT INTO raporttitaulukot (laatijat_id, raportti_id, raporttirivi_id, otsikko, jarjestys) VALUES (?, ?, ?, ?, ?)');
                $query->bind_param('iiisi', $laatijatId, $raporttiId, $rowId, $tableTitle, $order);
                $query->execute();
                $tableId = $db->insert_id;
                $query->close();
                $query = $db->prepare('INSERT INTO raporttitaulukko_sarakkeet (laatijat_id, taulukko_id, nimi, jarjestys) VALUES (?, ?, ?, ?)');
                $columnName = 'Sarake 1'; $columnOrder = 1;
                $query->bind_param('iisi', $laatijatId, $tableId, $columnName, $columnOrder); $query->execute();
                $columnName = 'Sarake 2'; $columnOrder = 2;
                $query->bind_param('iisi', $laatijatId, $tableId, $columnName, $columnOrder); $query->execute();
                $query->close();
                $query = $db->prepare('INSERT INTO raporttitaulukko_rivit (laatijat_id, taulukko_id, jarjestys) VALUES (?, ?, ?)');
                $rowOrder = 1;
                $query->bind_param('iii', $laatijatId, $tableId, $rowOrder); $query->execute(); $query->close();
                $message = 'Taulukko lisättiin.';
                $messageType = 'success';
            } elseif ($action === 'add-text') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $textContent = trim((string) ($_POST['tekstisisalto'] ?? ''));
                if ($rowId < 1 || $textContent === '') {
                    throw new RuntimeException('Kirjoita lisättävä tekstisisältö.');
                }
                $query = $db->prepare('SELECT id FROM raporttirivit WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $rowExists = (bool) $query->get_result()->fetch_assoc();
                $query->close();
                if (!$rowExists) {
                    throw new RuntimeException('Valittu osio ei kuulu raporttiin.');
                }
                $query = $db->prepare(
                    'SELECT GREATEST(
                        COALESCE((SELECT MAX(jarjestys) FROM raporttitaulukot WHERE raporttirivi_id = ? AND laatijat_id = ?), 0),
                        COALESCE((SELECT MAX(jarjestys) FROM raporttitekstit WHERE raporttirivi_id = ? AND laatijat_id = ?), 0)
                     ) + 1 AS seuraava'
                );
                $query->bind_param('iiii', $rowId, $laatijatId, $rowId, $laatijatId);
                $query->execute();
                $order = (int) $query->get_result()->fetch_assoc()['seuraava'];
                $query->close();
                $query = $db->prepare('INSERT INTO raporttitekstit (laatijat_id, raportti_id, raporttirivi_id, sisalto, jarjestys) VALUES (?, ?, ?, ?, ?)');
                $query->bind_param('iiisi', $laatijatId, $raporttiId, $rowId, $textContent, $order);
                $query->execute();
                $query->close();
                $message = 'Teksti lisättiin.';
                $messageType = 'success';
            } elseif ($action === 'update-text') {
                $textId = (int) ($_POST['teksti_id'] ?? 0);
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $textContent = trim((string) ($_POST['tekstisisalto'] ?? ''));
                if ($textId < 1 || $rowId < 1) {
                    throw new RuntimeException('Tekstilohkon tiedot puuttuvat.');
                }
                $query = $db->prepare('UPDATE raporttitekstit SET sisalto = ? WHERE id = ? AND raporttirivi_id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('siiii', $textContent, $textId, $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                $message = 'Teksti tallennettiin.';
                $messageType = 'success';
            } elseif ($action === 'delete-text') {
                $textId = (int) ($_POST['teksti_id'] ?? 0);
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $query = $db->prepare('DELETE FROM raporttitekstit WHERE id = ? AND raporttirivi_id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iiii', $textId, $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                $message = 'Teksti poistettiin.';
                $messageType = 'success';
            } elseif (in_array($action, ['update-table', 'add-table-column', 'add-table-row', 'delete-table-column', 'delete-table-row', 'add-table-summary', 'delete-table-summary', 'add-table-calculation', 'delete-table-calculation'], true)) {
                $tableId = (int) ($_POST['taulukko_id'] ?? 0);
                $tableTitle = trim((string) ($_POST['taulukko_otsikko'] ?? ''));
                $tableBorders = isset($_POST['taulukko_reunat']) ? 1 : 0;
                $tableWidthMode = (string) ($_POST['taulukko_leveys'] ?? 'keskitetty');
                $tableVerticalAlignment = (string) ($_POST['taulukko_tasaus_pysty'] ?? 'alas');
                $tableHorizontalAlignment = (string) ($_POST['taulukko_tasaus_vaaka'] ?? 'vasen');
                $columnNames = $_POST['sarake_nimi'] ?? [];
                $columnColors = $_POST['sarake_taustavari'] ?? [];
                $columnFontColors = $_POST['sarake_fonttivari'] ?? [];
                $cellValues = $_POST['solu'] ?? [];
                $newColumnName = trim((string) ($_POST['uusi_sarake_nimi'] ?? ''));
                $summaryType = (string) ($_POST['yhteenveto_tyyppi'] ?? '');
                $summaryName = trim((string) ($_POST['yhteenveto_nimittaja'] ?? '')) ?: 'Yhteensä';
                $summaryAfterRow = (int) ($_POST['yhteenveto_jalkeen_rivi_id'] ?? 0);
                $calculationId = (int) ($_POST['laskentarivi_id'] ?? 0);
                if ($action === 'add-table-column' && $newColumnName === '') {
                    throw new RuntimeException('Anna sarakkeelle nimi.');
                }
                if (!in_array($tableWidthMode, ['keskitetty', 'kokosivu'], true)) {
                    throw new RuntimeException('Valitse taulukon leveys.');
                }
                if (!in_array($tableVerticalAlignment, ['ylos', 'keskelle', 'alas'], true)
                    || !in_array($tableHorizontalAlignment, ['vasen', 'keski', 'oikea'], true)) {
                    throw new RuntimeException('Valitse taulukon tasaus.');
                }
                $query = $db->prepare('SELECT id FROM raporttitaulukot WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $tableId, $raporttiId, $laatijatId); $query->execute();
                $tableExists = (bool) $query->get_result()->fetch_assoc(); $query->close();
                if (!$tableExists) {
                    throw new RuntimeException('Taulukkoa ei löytynyt.');
                }
                $query = $db->prepare('UPDATE raporttitaulukot SET otsikko = ?, reunat = ?, leveys_tila = ?, tasaus_pysty = ?, tasaus_vaaka = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('sisssiii', $tableTitle, $tableBorders, $tableWidthMode, $tableVerticalAlignment, $tableHorizontalAlignment, $tableId, $raporttiId, $laatijatId); $query->execute(); $query->close();
                $query = $db->prepare('UPDATE raporttitaulukko_sarakkeet SET nimi = ?, taustavari = ?, fonttivari = ? WHERE id = ? AND taulukko_id = ? AND laatijat_id = ?');
                foreach ((array) ($columnNames[$tableId] ?? []) as $columnId => $columnName) {
                    $columnId = (int) $columnId;
                    $columnName = trim((string) $columnName);
                    if ($columnId > 0 && $columnName !== '') {
                        $columnColor = strtolower(trim((string) (($columnColors[$tableId][$columnId] ?? '#ffffff'))));
                        if (!preg_match('/^#[0-9a-f]{6}$/', $columnColor)) { $columnColor = '#ffffff'; }
                        $fontColor = strtolower(trim((string) (($columnFontColors[$tableId][$columnId] ?? '#232d3c'))));
                        if (!preg_match('/^#[0-9a-f]{6}$/', $fontColor)) { $fontColor = '#232d3c'; }
                        $query->bind_param('sssiii', $columnName, $columnColor, $fontColor, $columnId, $tableId, $laatijatId); $query->execute();
                    }
                }
                $query->close();
                $query = $db->prepare('INSERT INTO raporttitaulukko_solut (laatijat_id, taulukko_id, rivi_id, sarake_id, sisalto) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE sisalto = VALUES(sisalto)');
                foreach ((array) ($cellValues[$tableId] ?? []) as $tableRowId => $columns) {
                    foreach ((array) $columns as $columnId => $cellValue) {
                        $tableRowId = (int) $tableRowId; $columnId = (int) $columnId; $cellValue = (string) $cellValue;
                        if ($tableRowId > 0 && $columnId > 0) {
                            $query->bind_param('iiiis', $laatijatId, $tableId, $tableRowId, $columnId, $cellValue); $query->execute();
                        }
                    }
                }
                $query->close();
                if (in_array($action, ['update-table', 'add-table-summary'], true)) {
                    foreach ((array) ($_POST['yhteenveto'] ?? []) as $summaryId => $summary) {
                        $summaryId = (int) $summaryId;
                        $type = (string) ($summary['tyyppi'] ?? '');
                        $name = trim((string) ($summary['nimittaja'] ?? '')) ?: 'Yhteensä';
                        $afterRow = (int) ($summary['jalkeen_rivi_id'] ?? 0);
                        if ($summaryId < 1 || !in_array($type, ['summa', 'valisummaa'], true)) {
                            continue;
                        }
                        $query = $db->prepare('UPDATE raporttitaulukko_yhteenvetorivit SET nimittaja = ?, tyyppi = ?, jalkeen_rivi_id = NULLIF(?, 0) WHERE id = ? AND taulukko_id = ? AND laatijat_id = ?');
                        $query->bind_param('ssiiii', $name, $type, $afterRow, $summaryId, $tableId, $laatijatId); $query->execute(); $query->close();
                        $query = $db->prepare('DELETE FROM raporttitaulukko_yhteenvetorivi_sarakkeet WHERE yhteenvetorivi_id = ? AND laatijat_id = ?');
                        $query->bind_param('ii', $summaryId, $laatijatId); $query->execute(); $query->close();
                        foreach ((array) ($summary['sarakkeet'] ?? []) as $columnId) {
                            $columnId = (int) $columnId;
                            if ($columnId > 0) {
                                $query = $db->prepare('INSERT INTO raporttitaulukko_yhteenvetorivi_sarakkeet (laatijat_id, yhteenvetorivi_id, sarake_id) VALUES (?, ?, ?)');
                                $query->bind_param('iii', $laatijatId, $summaryId, $columnId); $query->execute(); $query->close();
                            }
                        }
                    }
                }
                if ($action === 'add-table-summary') {
                    if (!in_array($summaryType, ['summa', 'valisummaa'], true)) {
                        throw new RuntimeException('Valitse yhteenvedon tyyppi.');
                    }
                    $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttitaulukko_yhteenvetorivit WHERE taulukko_id = ? AND laatijat_id = ?');
                    $query->bind_param('ii', $tableId, $laatijatId); $query->execute(); $summaryOrder = (int) $query->get_result()->fetch_assoc()['seuraava']; $query->close();
                    $query = $db->prepare('INSERT INTO raporttitaulukko_yhteenvetorivit (laatijat_id, taulukko_id, nimittaja, tyyppi, jalkeen_rivi_id, jarjestys) VALUES (?, ?, ?, ?, NULLIF(?, 0), ?)');
                    $query->bind_param('iissii', $laatijatId, $tableId, $summaryName, $summaryType, $summaryAfterRow, $summaryOrder);
                    $query->execute(); $summaryId = $db->insert_id; $query->close();
                    foreach ((array) ($_POST['yhteenveto_sarakkeet'] ?? []) as $columnId) {
                        $columnId = (int) $columnId;
                        if ($columnId > 0) {
                            $query = $db->prepare('INSERT INTO raporttitaulukko_yhteenvetorivi_sarakkeet (laatijat_id, yhteenvetorivi_id, sarake_id) VALUES (?, ?, ?)');
                            $query->bind_param('iii', $laatijatId, $summaryId, $columnId); $query->execute(); $query->close();
                        }
                    }
                    $message = 'Yhteenvetorivi lisättiin.';
                } elseif ($action === 'add-table-calculation') {
                    $calculation = $_POST['laskenta'] ?? [];
                    $summaryId = (int) ($calculation['yhteenvetorivi_id'] ?? 0);
                    $denominator = trim((string) ($calculation['nimittaja'] ?? ''));
                    $units = (float) str_replace(',', '.', (string) ($calculation['yksikot'] ?? '0'));
                    $operation = (string) ($calculation['toiminto'] ?? '');
                    $sourceColumnId = (int) ($calculation['lahde_sarake_id'] ?? 0);
                    $query = $db->prepare('SELECT y.id FROM raporttitaulukko_yhteenvetorivit y WHERE y.id = ? AND y.taulukko_id = ? AND y.laatijat_id = ? LIMIT 1');
                    $query->bind_param('iii', $summaryId, $tableId, $laatijatId); $query->execute();
                    $summaryExists = (bool) $query->get_result()->fetch_assoc(); $query->close();
                    if (!$summaryExists || $denominator === '' || $units <= 0 || !in_array($operation, ['jaa', 'kerro', 'prosentti'], true) || $sourceColumnId < 1) {
                        throw new RuntimeException('Täytä laskentarivin tiedot ja valitse summarivin solu.');
                    }
                    $query = $db->prepare('SELECT s.id FROM raporttitaulukko_yhteenvetorivi_sarakkeet ys INNER JOIN raporttitaulukko_sarakkeet s ON s.id = ys.sarake_id AND s.laatijat_id = ys.laatijat_id WHERE ys.yhteenvetorivi_id = ? AND ys.sarake_id = ? AND ys.laatijat_id = ? LIMIT 1');
                    $query->bind_param('iii', $summaryId, $sourceColumnId, $laatijatId); $query->execute();
                    $sourceExists = (bool) $query->get_result()->fetch_assoc(); $query->close();
                    if (!$sourceExists) { throw new RuntimeException('Valittu sarake ei kuulu summariviin.'); }
                    $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttitaulukko_laskentarivit WHERE yhteenvetorivi_id = ? AND laatijat_id = ?');
                    $query->bind_param('ii', $summaryId, $laatijatId); $query->execute(); $calculationOrder = (int) $query->get_result()->fetch_assoc()['seuraava']; $query->close();
                    $query = $db->prepare('INSERT INTO raporttitaulukko_laskentarivit (laatijat_id, taulukko_id, yhteenvetorivi_id, nimittaja, yksikot, toiminto, lahde_sarake_id, jarjestys) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $query->bind_param('iiisdsii', $laatijatId, $tableId, $summaryId, $denominator, $units, $operation, $sourceColumnId, $calculationOrder);
                    $query->execute(); $query->close();
                    $message = 'Laskentarivi lisättiin.';
                } elseif ($action === 'delete-table-summary') {
                    $summaryId = (int) ($_POST['yhteenvetorivi_id'] ?? 0);
                    $query = $db->prepare('DELETE FROM raporttitaulukko_yhteenvetorivit WHERE id = ? AND taulukko_id = ? AND laatijat_id = ?');
                    $query->bind_param('iii', $summaryId, $tableId, $laatijatId); $query->execute(); $query->close();
                    $message = 'Yhteenvetorivi poistettiin.';
                } elseif ($action === 'delete-table-calculation') {
                    $query = $db->prepare('DELETE FROM raporttitaulukko_laskentarivit WHERE id = ? AND taulukko_id = ? AND laatijat_id = ?');
                    $query->bind_param('iii', $calculationId, $tableId, $laatijatId); $query->execute(); $query->close();
                    $message = 'Laskentarivi poistettiin.';
                }
                if ($action === 'add-table-column') {
                    $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttitaulukko_sarakkeet WHERE taulukko_id = ? AND laatijat_id = ?');
                    $query->bind_param('ii', $tableId, $laatijatId); $query->execute(); $order = (int) $query->get_result()->fetch_assoc()['seuraava']; $query->close();
                    $query = $db->prepare('INSERT INTO raporttitaulukko_sarakkeet (laatijat_id, taulukko_id, nimi, jarjestys) VALUES (?, ?, ?, ?)');
                    $query->bind_param('iisi', $laatijatId, $tableId, $newColumnName, $order); $query->execute(); $query->close();
                    $message = 'Taulukko tallennettiin ja sarake lisättiin.';
                } elseif ($action === 'add-table-row') {
                    $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttitaulukko_rivit WHERE taulukko_id = ? AND laatijat_id = ?');
                    $query->bind_param('ii', $tableId, $laatijatId); $query->execute(); $order = (int) $query->get_result()->fetch_assoc()['seuraava']; $query->close();
                    $query = $db->prepare('INSERT INTO raporttitaulukko_rivit (laatijat_id, taulukko_id, jarjestys) VALUES (?, ?, ?)');
                    $query->bind_param('iii', $laatijatId, $tableId, $order); $query->execute(); $query->close();
                    $message = 'Taulukko tallennettiin ja rivi lisättiin.';
                } elseif ($action === 'delete-table-column') {
                    $columnId = (int) ($_POST['sarake_id'] ?? 0);
                    $query = $db->prepare('DELETE FROM raporttitaulukko_sarakkeet WHERE id = ? AND taulukko_id = ? AND laatijat_id = ?');
                    $query->bind_param('iii', $columnId, $tableId, $laatijatId); $query->execute(); $query->close();
                    $message = 'Taulukko tallennettiin ja sarake poistettiin.';
                } elseif ($action === 'delete-table-row') {
                    $tableRowId = (int) ($_POST['taulukko_rivi_id'] ?? 0);
                    $query = $db->prepare('DELETE FROM raporttitaulukko_rivit WHERE id = ? AND taulukko_id = ? AND laatijat_id = ?');
                    $query->bind_param('iii', $tableRowId, $tableId, $laatijatId); $query->execute(); $query->close();
                    $message = 'Taulukko tallennettiin ja rivi poistettiin.';
                } else {
                    $message = 'Taulukko tallennettiin.';
                }
                $messageType = 'success';
            } elseif ($action === 'delete-table') {
                $tableId = (int) ($_POST['taulukko_id'] ?? 0);
                $query = $db->prepare('DELETE FROM raporttitaulukot WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $tableId, $raporttiId, $laatijatId); $query->execute(); $query->close();
                $message = 'Taulukko poistettiin.'; $messageType = 'success';
            } elseif ($action === 'delete-row') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $query = $db->prepare('SELECT url FROM raporttimedia WHERE raporttirivi_id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $mediaFiles = $query->get_result()->fetch_all(MYSQLI_ASSOC);
                $query->close();
                $query = $db->prepare('DELETE FROM raporttimedia WHERE raporttirivi_id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                $query = $db->prepare('DELETE FROM raporttirivit WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                foreach ($mediaFiles as $mediaFile) {
                    deleteStoredFile((string) $mediaFile['url']);
                }
                $message = 'Osio poistettiin.';
                $messageType = 'success';
            } elseif ($action === 'add-media') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $type = (string) ($_POST['media_tyyppi'] ?? '');
                $url = trim((string) ($_POST['media_url'] ?? ''));
                $caption = trim((string) ($_POST['kuvateksti'] ?? ''));
                if (!in_array($type, ['kuva', 'videolinkki'], true) || $rowId < 1) {
                    throw new RuntimeException('Valitse mediatyyppi ja osio.');
                }
                if ($type === 'videolinkki') {
                    if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
                        throw new RuntimeException('Videolinkin pitää olla HTTPS-osoite.');
                    }
                } else {
                    if (!isset($_FILES['media_files']['name']) || !is_array($_FILES['media_files']['name'])) {
                        throw new RuntimeException('Valitse ladattava kuva.');
                    }
                }
                $query = $db->prepare('SELECT id FROM raporttirivit WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $rowExists = (bool) $query->get_result()->fetch_assoc();
                $query->close();
                if (!$rowExists) {
                    throw new RuntimeException('Valittu osio ei kuulu raporttiin.');
                }
                $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttimedia WHERE raporttirivi_id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $rowId, $laatijatId);
                $query->execute();
                $order = (int) $query->get_result()->fetch_assoc()['seuraava'];
                $query->close();
                $files = $type === 'videolinkki' ? [null] : $_FILES['media_files']['name'];
                foreach ($files as $fileIndex => $_fileName) {
                    if ($type !== 'videolinkki' && (int) ($_FILES['media_files']['error'][$fileIndex] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $mediaUrl = $url;
                    if ($type !== 'videolinkki') {
                        $uploadedFile = [
                            'name' => $_FILES['media_files']['name'][$fileIndex],
                            'type' => $_FILES['media_files']['type'][$fileIndex],
                            'tmp_name' => $_FILES['media_files']['tmp_name'][$fileIndex],
                            'error' => $_FILES['media_files']['error'][$fileIndex],
                            'size' => $_FILES['media_files']['size'][$fileIndex],
                        ];
                        $mediaUrl = storeUploadedFile($uploadedFile, 'media');
                    }
                    $query = $db->prepare('INSERT INTO raporttimedia (laatijat_id, raportti_id, raporttirivi_id, tyyppi, url, kuvateksti, jarjestys) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $query->bind_param('iiisssi', $laatijatId, $raporttiId, $rowId, $type, $mediaUrl, $caption, $order);
                    $query->execute();
                    $newMediaId = $type === 'kuva' ? (int) $db->insert_id : 0;
                    $query->close();
                    $order++;
                }
                $message = 'Media lisättiin.';
                $messageType = 'success';
            } elseif ($action === 'delete-media') {
                $mediaId = (int) ($_POST['media_id'] ?? 0);
                $query = $db->prepare('SELECT url FROM raporttimedia WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $mediaId, $raporttiId, $laatijatId);
                $query->execute();
                $mediaFile = $query->get_result()->fetch_assoc();
                $query->close();
                $query = $db->prepare('DELETE FROM raporttimedia WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $mediaId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                if ($mediaFile) {
                    deleteStoredFile((string) $mediaFile['url']);
                }
                $message = 'Media poistettiin.';
                $messageType = 'success';
            } elseif ($action === 'update-media') {
                $mediaId = (int) ($_POST['media_id'] ?? 0);
                $caption = trim((string) ($_POST['kuvateksti'] ?? ''));
                $query = $db->prepare('UPDATE raporttimedia SET kuvateksti = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('siii', $caption, $mediaId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                $message = 'Kuvateksti tallennettiin.';
                $messageType = 'success';
            } elseif ($action === 'update-media-annotations') {
                $mediaId = (int) ($_POST['media_id'] ?? 0);
                $annotationsJson = normalizeImageAnnotations((string) ($_POST['merkinnat_json'] ?? '[]'));
                $query = $db->prepare('UPDATE raporttimedia SET merkinnat_json = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ? AND tyyppi = \'kuva\'');
                $query->bind_param('siii', $annotationsJson, $mediaId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                $message = 'Kuvan merkinnät tallennettiin.';
                $messageType = 'success';
            } elseif ($action === 'add-attachment') {
                if (!isset($_FILES['liite_tiedosto']['name']) || !is_array($_FILES['liite_tiedosto']['name'])) {
                    throw new RuntimeException('Valitse PDF-liite.');
                }
                $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttiliitteet WHERE raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute();
                $order = (int) $query->get_result()->fetch_assoc()['seuraava']; $query->close();
                foreach ($_FILES['liite_tiedosto']['name'] as $index => $_name) {
                    $file = ['name' => $_FILES['liite_tiedosto']['name'][$index], 'type' => $_FILES['liite_tiedosto']['type'][$index], 'tmp_name' => $_FILES['liite_tiedosto']['tmp_name'][$index], 'error' => $_FILES['liite_tiedosto']['error'][$index], 'size' => $_FILES['liite_tiedosto']['size'][$index]];
                    $stored = storeUploadedFile($file, 'liitteet', ['application/pdf' => 'pdf']);
                    $query = $db->prepare('INSERT INTO raporttiliitteet (laatijat_id, raportti_id, tiedosto, alkuperainen_nimi, tiedostokoko, jarjestys) VALUES (?, ?, ?, ?, ?, ?)');
                    $size = (int) $file['size']; $name = (string) $file['name'];
                    $query->bind_param('iissii', $laatijatId, $raporttiId, $stored, $name, $size, $order); $query->execute(); $query->close(); $order++;
                }
                $message = 'Liite lisättiin.'; $messageType = 'success';
            } elseif ($action === 'delete-attachment') {
                $attachmentId = (int) ($_POST['liite_id'] ?? 0);
                $query = $db->prepare('SELECT tiedosto FROM raporttiliitteet WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $attachmentId, $raporttiId, $laatijatId); $query->execute(); $attachment = $query->get_result()->fetch_assoc(); $query->close();
                $query = $db->prepare('DELETE FROM raporttiliitteet WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $attachmentId, $raporttiId, $laatijatId); $query->execute(); $query->close();
                if ($attachment) deleteStoredFile((string) $attachment['tiedosto']);
                $message = 'Liite poistettiin.'; $messageType = 'success';
            }
        } catch (Throwable $error) {
            $message = $error->getMessage();
            error_log('Raportin muokkaus epäonnistui: ' . $error->getMessage());
        }
    }
}

// Post/Redirect/Get estää saman lomakkeen lähettämisen uudelleen sivun päivityksessä.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'success') {
    $returnAnchor = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST['return_anchor'] ?? ''));
    if ($returnAnchor === '') {
        $returnAnchor = match ($action ?? '') {
            'update-report' => 'perustiedot',
            'add-row' => 'uusi-osio',
            'update-row', 'delete-row', 'add-media', 'add-table', 'add-text', 'update-text', 'delete-text', 'update-media-annotations' => 'osio-' . (int) ($_POST['rivi_id'] ?? 0),
            'update-table', 'add-table-column', 'add-table-row',
            'delete-table-column', 'delete-table-row', 'add-table-summary', 'delete-table-summary', 'delete-table' => 'taulukko-' . (int) ($_POST['taulukko_id'] ?? 0),
            'add-attachment', 'delete-attachment' => 'liitteet',
            default => '',
        };
    }
    $editMediaQuery = $action === 'add-media' && $newMediaId > 0 ? '&edit_media=' . $newMediaId : '';
    header('Location: muokkaa_raportti.php?id=' . $raporttiId . '&saved=1' . $editMediaQuery . ($returnAnchor !== '' ? '#' . $returnAnchor : ''));
    exit;
}

try {
    $db = getDatabaseConnection();
    $query = $db->prepare('SELECT a.id, a.nimi, a.yritys FROM asiakkaat a WHERE a.laatijat_id = ? OR EXISTS (
        SELECT 1 FROM asiakasjaot j WHERE j.asiakas_id = a.id AND j.laatijat_id = ?
    ) OR EXISTS (
        SELECT 1 FROM raportit rs INNER JOIN raporttijaot rj ON rj.raportti_id = rs.id
        WHERE rs.asiakas_id = a.id AND rj.laatijat_id = ?
    ) ORDER BY a.nimi, a.yritys');
    $query->bind_param('iii', $currentLaatijaId, $currentLaatijaId, $currentLaatijaId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $customers[] = $item; } $query->close();
    $query = $db->prepare('SELECT p.id, p.asiakas_id, p.nimi FROM projektit p WHERE p.laatijat_id = ? OR EXISTS (
        SELECT 1 FROM projektijaot j WHERE j.projekti_id = p.id AND j.laatijat_id = ?
    ) ORDER BY p.nimi');
    $query->bind_param('ii', $laatijatId, $currentLaatijaId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $projects[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, otsikko, sisalto, sisaltaa_asiakastiedot, jarjestys FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $rows[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, raporttirivi_id, tyyppi, url, kuvateksti, merkinnat_json FROM raporttimedia WHERE raportti_id = ? AND laatijat_id = ? ORDER BY raporttirivi_id, jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $media[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, raporttirivi_id, sisalto, jarjestys FROM raporttitekstit WHERE raportti_id = ? AND laatijat_id = ? ORDER BY raporttirivi_id, jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $texts[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, tiedosto, alkuperainen_nimi, tiedostokoko FROM raporttiliitteet WHERE raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute();
    $attachments = $query->get_result()->fetch_all(MYSQLI_ASSOC); $query->close();
    $query = $db->prepare('SELECT id, raporttirivi_id, otsikko, reunat, leveys_tila, tasaus_pysty, tasaus_vaaka, jarjestys FROM raporttitaulukot WHERE raportti_id = ? AND laatijat_id = ? ORDER BY raporttirivi_id, jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { $item['sarakkeet'] = []; $item['rivit'] = []; $item['solut'] = []; $item['yhteenvetorivit'] = []; $item['laskentarivit'] = []; $tables[(int) $item['id']] = $item; } $query->close();
    if ($tables) {
        $query = $db->prepare('SELECT id, taulukko_id, nimi, taustavari, fonttivari, jarjestys FROM raporttitaulukko_sarakkeet WHERE laatijat_id = ? ORDER BY taulukko_id, jarjestys, id');
        $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { if (isset($tables[(int) $item['taulukko_id']])) { $tables[(int) $item['taulukko_id']]['sarakkeet'][] = $item; } } $query->close();
        $query = $db->prepare('SELECT id, taulukko_id, jarjestys FROM raporttitaulukko_rivit WHERE laatijat_id = ? ORDER BY taulukko_id, jarjestys, id');
        $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { if (isset($tables[(int) $item['taulukko_id']])) { $tables[(int) $item['taulukko_id']]['rivit'][] = $item; } } $query->close();
        $query = $db->prepare('SELECT taulukko_id, rivi_id, sarake_id, sisalto FROM raporttitaulukko_solut WHERE laatijat_id = ? ORDER BY taulukko_id, rivi_id, sarake_id');
        $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { if (isset($tables[(int) $item['taulukko_id']])) { $tables[(int) $item['taulukko_id']]['solut'][(int) $item['rivi_id']][(int) $item['sarake_id']] = $item['sisalto']; } } $query->close();
        $query = $db->prepare('SELECT id, taulukko_id, nimittaja, tyyppi, jalkeen_rivi_id, jarjestys FROM raporttitaulukko_yhteenvetorivit WHERE laatijat_id = ? ORDER BY taulukko_id, jarjestys, id');
        $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { $item['sarakkeet'] = []; $summaryRows[(int) $item['id']] = $item; } $query->close();
        if ($summaryRows) {
            $query = $db->prepare('SELECT yhteenvetorivi_id, sarake_id FROM raporttitaulukko_yhteenvetorivi_sarakkeet WHERE laatijat_id = ?');
            $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
            while ($item = $result->fetch_assoc()) { if (isset($summaryRows[(int) $item['yhteenvetorivi_id']])) { $summaryRows[(int) $item['yhteenvetorivi_id']]['sarakkeet'][] = (int) $item['sarake_id']; } } $query->close();
        }
        foreach ($summaryRows as $summary) { if (isset($tables[(int) $summary['taulukko_id']])) { $tables[(int) $summary['taulukko_id']]['yhteenvetorivit'][] = $summary; } }
        $query = $db->prepare('SELECT id, taulukko_id, yhteenvetorivi_id, nimittaja, yksikot, toiminto, lahde_sarake_id, jarjestys FROM raporttitaulukko_laskentarivit WHERE laatijat_id = ? ORDER BY yhteenvetorivi_id, jarjestys, id');
        $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { if (isset($tables[(int) $item['taulukko_id']])) { $tables[(int) $item['taulukko_id']]['laskentarivit'][] = $item; } } $query->close();
    }
} catch (Throwable $error) {
    $message = 'Raportin tietojen haku epäonnistui.';
    error_log('Raportin muokkaussivun tietojen haku epäonnistui: ' . $error->getMessage());
}

$contentBlocks = [];
foreach ($tables as $table) {
    $contentBlocks[(int) $table['raporttirivi_id']][] = [
        'type' => 'table',
        'id' => (int) $table['id'],
        'jarjestys' => (int) ($table['jarjestys'] ?? 0),
        'data' => $table,
    ];
}
foreach ($texts as $text) {
    $contentBlocks[(int) $text['raporttirivi_id']][] = [
        'type' => 'text',
        'id' => (int) $text['id'],
        'jarjestys' => (int) ($text['jarjestys'] ?? 0),
        'data' => $text,
    ];
}
foreach ($contentBlocks as &$blocks) {
    usort($blocks, static function (array $left, array $right): int {
        return [$left['jarjestys'], $left['id']] <=> [$right['jarjestys'], $right['id']];
    });
}
unset($blocks);

$customerInfoPreviewColor = '#ffffff';
foreach ($tables as $previewTable) {
    $candidateColor = (string) ($previewTable['sarakkeet'][0]['taustavari'] ?? '');
    if (preg_match('/^#[0-9a-f]{6}$/i', $candidateColor)) {
        $customerInfoPreviewColor = strtolower($candidateColor);
        break;
    }
}

function editValue($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

openLayoutShell('Muokkaa raporttia – TEKRAPORTTI');
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
.image-annotation-preview {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
}
.image-annotation-preview img {
    width: 180px;
    max-height: 110px;
    object-fit: contain;
    border: 1px solid #dee2e6;
    border-radius: .25rem;
}
.image-annotation-dialog {
    width: min(96vw, 1100px);
    max-width: none;
    border: 0;
    border-radius: .5rem;
    padding: 0;
}
.image-annotation-dialog::backdrop {
    background: rgba(0, 0, 0, .65);
}
.image-annotation-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    align-items: center;
    padding: .75rem;
    border-bottom: 1px solid #dee2e6;
}
.image-annotation-canvas-wrap {
    max-height: 72vh;
    overflow: auto;
    padding: .75rem;
    background: #f1f3f5;
    text-align: center;
}
#image-annotation-canvas {
    display: block;
    max-width: 100%;
    max-height: 60vh;
    height: auto;
    margin: auto;
    background: #fff;
    cursor: crosshair;
    touch-action: none;
}
.image-annotation-dialog [data-annotation-tool].active {
    color: #fff;
    background: #0d6efd;
    border-color: #0d6efd;
}
.report-edit-header {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    gap: .5rem;
}
.report-edit-preview-form {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    gap: .5rem;
}
.report-edit-preview-form .form-select {
    width: 110px;
    min-width: 110px;
}
.report-chart-options {
    position: relative;
}
.table-no-borders > :not(caption) > * > * { border: 0 !important; }
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
.report-edit-header > a {
    white-space: nowrap;
}
.report-section-header {
    padding: .75rem 1.5rem;
    background-color: #000;
    color: #fff;
    border-bottom: 1px solid #dee2e6;
    cursor: pointer;
    list-style: none;
}
.report-section-header::-webkit-details-marker {
    display: none;
}
.report-section-header::after {
    content: '▾';
    margin-left: .75rem;
}
.report-section[open] > .report-section-header::after {
    content: '▴';
}
.report-section > .card-body {
    background-color: #fff;
    border: 1px solid #c5d1de;
    border-top: 0;
}
.report-section > .card-body .form-control,
.report-section > .card-body .form-select {
    background-color: #fff;
    border-color: #9eafc1;
}
.report-section > .card-body .border-top,
.report-section > .card-body .border-bottom {
    border-color: #6f7e8d !important;
}
.report-section > .card-body {
    border-color: #7b8997;
}
.report-section > .card-body .form-control,
.report-section > .card-body .form-select {
    border-color: #8291a0;
}
.report-section > .card-body .file-drop-zone {
    border-color: #748493;
}
.report-section > .card-body .table {
    --bs-table-border-color: #7b8997;
}
.report-table-form .table > :not(caption) > * > * {
    border-color: #7b8997 !important;
}
.report-table-form .table tbody > tr > td:last-child {
    border: 1px solid #7b8997 !important;
    background-color: #fff;
}
.report-table-form > .border.rounded.p-2.mt-2 {
    border-color: #7b8997 !important;
}
.report-table-form .table.table-bordered thead > tr > th {
    border: 1px solid #7b8997 !important;
}
.report-table-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(260px, 320px);
    gap: 1rem;
    align-items: start;
}
.report-table-form > .report-table-settings-row,
.report-table-form > .row.g-2.mb-2.align-items-end,
.report-table-form > .row.g-2.mb-2 {
    position: static;
    grid-column: 2;
    width: auto;
    margin: 0;
    padding: .75rem;
    background: #fff;
    border: 1px solid #000;
    border-radius: .5rem;
}
.report-table-form > .row.g-2.mb-2.align-items-end > [class*="col-"],
.report-table-form > .row.g-2.mb-2:not(.align-items-end) > [class*="col-"],
.report-table-settings > .row.g-2.mb-2.align-items-end > [class*="col-"],
.report-table-settings > .row.g-2.mb-2:not(.align-items-end) > [class*="col-"] {
    width: 100%;
    margin-bottom: .55rem;
}
.report-table-form > .row.g-2.mb-2.align-items-end > [class*="col-"]:last-child,
.report-table-form > .row.g-2.mb-2:not(.align-items-end) > [class*="col-"]:last-child,
.report-table-settings > .row.g-2.mb-2.align-items-end > [class*="col-"]:last-child,
.report-table-settings > .row.g-2.mb-2:not(.align-items-end) > [class*="col-"]:last-child {
    margin-bottom: 0;
}
.report-table-form > .table-responsive {
    grid-column: 1;
    grid-row: 1;
    min-width: 0;
}
.report-section > .card-body > [id^="taulukko-"].border-top {
    border-top: 3px solid #000 !important;
    padding-top: .75rem !important;
}
.report-section > .card-body > form.d-flex.gap-2.mt-3 {
    margin-top: 1rem !important;
    padding-top: 1rem;
    border-top: 1px solid #000;
}
.report-section > .card-body > form.row.g-2.mt-2 {
    margin-top: 1rem !important;
    padding-top: .75rem;
    border-top: 3px solid #000;
}
.report-table-settings {
    grid-column: 2;
    grid-row: 1;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    min-width: 0;
}
.report-table-settings > .row.g-2.mb-2.align-items-end,
.report-table-settings > .row.g-2.mb-2:not(.align-items-end) {
    position: static;
    width: auto;
    margin: 0;
    padding: .75rem;
    background: #fff;
    border: 1px solid #000;
    border-radius: .5rem;
}
.report-table-settings > .row.g-2.mb-2:not(.align-items-end) > .col-md-6 {
    display: flex;
    flex-direction: column;
    gap: .35rem;
}
.report-table-settings > .row.g-2.mb-2:not(.align-items-end) > .col-md-6 > span {
    display: block;
}
.report-table-settings > .row.g-2.mb-2:not(.align-items-end) > .col-md-6 > label {
    display: flex !important;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    margin-left: 0 !important;
}
.report-delete-row-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    padding: 0;
    color: #dc3545 !important;
    border: 1px solid #dc3545 !important;
    border-radius: .35rem;
}
.report-delete-row-icon:hover,
.report-delete-row-icon:focus {
    color: #fff !important;
    background-color: #dc3545;
}
.report-delete-row-icon svg {
    width: 1rem;
    height: 1rem;
}
.report-table-form > .small.fw-semibold.mt-3,
.report-table-form > .border.rounded.p-2.mt-2,
.report-table-form > .d-flex.flex-wrap.gap-2.align-items-center {
    grid-column: 1;
}
.report-text-block,
.report-text-add {
    border-top: 1px solid #7b8997;
    padding-top: .75rem;
}
.report-column-background-colors {
    display: flex;
    flex-basis: 100%;
    width: 100%;
    flex-wrap: wrap;
    gap: .35rem .65rem;
    align-items: center;
    margin-top: .65rem;
    padding-top: .65rem;
    border-top: 1px solid #000;
}
.report-column-background-colors > span {
    flex-basis: 100%;
}
.report-column-background-colors label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    gap: .25rem;
    margin: 0;
}
@media (max-width: 900px) {
    .report-table-form {
        display: block;
    }
    .report-table-form > .report-table-settings-row,
    .report-table-form > .row.g-2.mb-2.align-items-end,
    .report-table-form > .row.g-2.mb-2 {
        position: static;
        width: auto;
        margin-bottom: .75rem;
    }
    .report-table-settings {
        display: block;
    }
    .report-table-settings > .row.g-2.mb-2.align-items-end,
    .report-table-settings > .row.g-2.mb-2:not(.align-items-end) {
        margin-bottom: .75rem;
    }
}
.report-section > .card-body > .report-section-header {
    display: none !important;
}
.report-section > .card-body > .report-section-header > strong {
    display: none;
}
#uusi-osio + .card .card-header {
    background-color: #000 !important;
    color: #fff;
    border-bottom-color: #dee2e6 !important;
}
.report-section-header .btn-outline-secondary {
    color: #fff;
    border-color: rgba(255, 255, 255, .65);
}
.report-section-header .btn-outline-secondary:hover,
.report-section-header .btn-outline-secondary:focus {
    color: #000;
    background-color: #fff;
    border-color: #fff;
}
@media (max-width: 900px) {
    .report-edit-header {
        flex-wrap: wrap;
    }
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Muokkaa raporttia</h1><p class="text-body-secondary mb-0"><?= editValue($report['otsikko']) ?></p></div>
    <div class="report-edit-header">
        <?php if ($tables): ?><form method="get" action="esikatsele_raportti.php" target="_blank" class="report-edit-preview-form"><input type="hidden" name="id" value="<?= $raporttiId ?>"><label class="small d-inline-flex align-items-center gap-2 me-2"><input type="color" name="asiakastiedot_taustavari" value="<?= editValue($customerInfoPreviewColor) ?>" title="Perustietojen tausta"> Perustietojen tausta</label><details class="report-chart-menu"><summary>Diagrammit</summary><?php foreach ($tables as $table): ?><details class="report-chart-options"><summary><?= editValue($table['otsikko'] ?: 'Taulukko ' . $table['id']) ?></summary><label class="visually-hidden" for="kaavio-<?= (int) $table['id'] ?>">Kaavio taulukolle <?= (int) $table['id'] ?></label><select class="form-select form-select-sm" id="kaavio-<?= (int) $table['id'] ?>" name="kaavio[<?= (int) $table['id'] ?>]" title="Taulukon kaavio"><option value="">Ei kaaviota</option><option value="pie">Piirakka</option><option value="bar">Pylväs</option><option value="bar_horizontal">Vaakasuora pylväs</option><option value="line">Viiva</option><option value="combo">Yhdistetty pylväs</option></select><label class="small d-block mt-1"><input type="checkbox" name="piilota_taulukko[<?= (int) $table['id'] ?>]" value="1"> Piilota taulukko, näytä vain diagrammi</label><details class="small report-chart-columns"><summary>Sarakkeet</summary><input type="hidden" name="kaavio_sarakkeet[<?= (int) $table['id'] ?>][]" value="0"><?php foreach (array_slice($table['sarakkeet'], 1) as $column): ?><label class="d-block"><input type="checkbox" name="kaavio_sarakkeet[<?= (int) $table['id'] ?>][]" value="<?= (int) $column['id'] ?>" checked> <?= editValue($column['nimi']) ?></label><?php endforeach; ?></details></details><?php endforeach; ?></details><button class="btn btn-outline-primary" type="submit">Esikatsele PDF:nä</button></form><?php else: ?><a class="btn btn-outline-primary" target="_blank" href="esikatsele_raportti.php?id=<?= $raporttiId ?>">Esikatsele PDF:nä</a><?php endif; ?>
        <a class="btn btn-outline-secondary" href="raportit.php">Takaisin raportteihin</a>
    </div>
</div>
<?php if ($message !== ''): ?><div class="alert alert-<?= editValue($messageType) ?>" role="alert"><?= editValue($message) ?></div><?php endif; ?>

<details class="card border-0 shadow-sm mb-4" id="perustiedot">
    <summary class="card-header bg-white"><h2 class="h5 mb-0">Raportin perustiedot</h2></summary>
<form method="post" enctype="multipart/form-data" action="muokkaa_raportti.php?id=<?= $raporttiId ?>">
    <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-report"><input type="hidden" name="return_anchor" value="perustiedot">
    <div class="card-body p-4"><div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="asiakas_id">Asiakas</label><select class="form-select" id="asiakas_id" name="asiakas_id" required><?php foreach ($customers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $report['asiakas_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= editValue($item['yritys'] ? $item['nimi'] . ' – ' . $item['yritys'] : $item['nimi']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="projekti_id">Projekti</label><select class="form-select" id="projekti_id" name="projekti_id"><option value="">Ei projektia</option><?php foreach ($projects as $item): ?><option value="<?= (int) $item['id'] ?>" data-asiakas-id="<?= (int) $item['asiakas_id'] ?>" <?= (int) $report['projekti_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= editValue($item['nimi']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="otsikko">Otsikko</label><input class="form-control" id="otsikko" name="otsikko" value="<?= editValue($report['otsikko']) ?>" maxlength="255" required></div>
        <div class="col-md-6"><label class="form-label" for="raporttipvm">Raporttipäivämäärä</label><input type="date" class="form-control" id="raporttipvm" name="raporttipvm" value="<?= editValue($report['raporttipvm']) ?>" required></div>
        <div class="col-md-6"><label class="form-label" for="laatimispaikka">Laatimispaikka</label><input class="form-control" id="laatimispaikka" name="laatimispaikka" value="<?= editValue($report['laatimispaikka']) ?>" maxlength="200"></div>
        <div class="col-md-6"><label class="form-label" for="jaettava_sahkoposti">Jaa raportti laatijoille</label><textarea class="form-control" id="jaettava_sahkoposti" name="jaettava_sahkoposti" rows="3" maxlength="4000" placeholder="laatija1@example.fi&#10;laatija2@example.fi"><?= editValue(implode("\n", $sharedEmails)) ?></textarea><div class="form-text">Yksi sähköpostiosoite riville. Osoitteiden täytyy löytyä laatijoista.</div></div>
        <div class="col-12"><label class="form-label" for="tiivistelma">Tiivistelmä</label><textarea class="form-control" id="tiivistelma" name="tiivistelma" rows="5" data-markdown-editor><?= editValue($report['tiivistelma']) ?></textarea></div>
        <div class="col-12"><label class="form-label" for="kansikuva">Kansikuva</label><div class="file-drop-zone" data-file-drop><input type="file" class="form-control" id="kansikuva" name="kansikuva" accept="image/jpeg,image/png,image/webp"><div class="file-drop-zone-hint">Voit myös raahata kuvan tähän.</div></div><div class="form-text">Uusi kuva korvaa nykyisen kansikuvan. JPG, PNG tai WebP, enintään 10 MB.</div></div>
    </div></div><div class="card-footer bg-white border-0 px-4 pb-4"><button class="btn btn-primary">Tallenna perustiedot</button></div>
</form>
</details>

<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Tiivistelmä</h2></div><div class="card-body"><?= $report['tiivistelma'] ? markdownToHtml((string) $report['tiivistelma']) : '<p class="mb-0">Tiivistelmää ei ole vielä lisätty.</p>' ?></div></div>

<div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h4 mb-0">Raporttiosiot</h2></div>
<?php foreach ($rows as $rowIndex => $row): ?>
    <details class="card border-0 shadow-sm mb-3 report-section" id="osio-<?= (int) $row['id'] ?>"><summary class="report-section-header d-flex justify-content-between align-items-center"><strong>Osio <?= $rowIndex + 1 ?>: <?= editValue($row['otsikko']) ?></strong><div class="d-flex gap-2 ms-auto" onclick="event.stopPropagation()"><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="move-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="suunta" value="up"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><button class="btn btn-outline-secondary btn-sm" <?= $rowIndex === 0 ? 'disabled' : '' ?>>↑ Ylös</button></form><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="move-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="suunta" value="down"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><button class="btn btn-outline-secondary btn-sm" <?= $rowIndex === count($rows) - 1 ? 'disabled' : '' ?>>↓ Alas</button></form></div></summary><div class="card-body p-4">
        <div class="report-section-header d-flex justify-content-between align-items-center"><strong>Osio <?= $rowIndex + 1 ?></strong><div class="d-flex gap-2"><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="move-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="suunta" value="up"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><button class="btn btn-outline-secondary btn-sm" <?= $rowIndex === 0 ? 'disabled' : '' ?>>↑ Ylös</button></form><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="move-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="suunta" value="down"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><button class="btn btn-outline-secondary btn-sm" <?= $rowIndex === count($rows) - 1 ? 'disabled' : '' ?>>↓ Alas</button></form></div></div>
        <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>">
            <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>">
            <label class="form-label">Osion otsikko</label><input class="form-control mb-3" name="rivi_otsikko" value="<?= editValue($row['otsikko']) ?>" maxlength="255" required>
            <label class="form-label">Tekstisisältö</label><textarea class="form-control mb-3" name="rivi_sisalto" rows="6" data-markdown-editor><?= editValue($row['sisalto']) ?></textarea>
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="asiakastiedot-<?= (int) $row['id'] ?>" name="sisaltaa_asiakastiedot" <?= !empty($row['sisaltaa_asiakastiedot']) ? 'checked' : '' ?>><label class="form-check-label" for="asiakastiedot-<?= (int) $row['id'] ?>">Tulosta asiakkaan tiedot tämän osion alussa</label></div>
            <button class="btn btn-primary btn-sm">Tallenna osio</button>
        </form>
        <div class="d-flex gap-2 mt-2"><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" onsubmit="return confirm('Kopioidaanko osio ja sen sisältö?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="copy-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><button class="btn btn-outline-primary btn-sm">Kopioi osio</button></form><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" onsubmit="return confirm('Poistetaanko osio?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><button class="btn btn-outline-danger btn-sm">Poista osio</button></form></div>
        <?php foreach ($media as $item): ?><?php if ((int) $item['raporttirivi_id'] === (int) $row['id']): ?><div class="border-top mt-3 pt-2 small"><strong><?= editValue($item['tyyppi']) ?></strong>: <?= editValue($item['url']) ?>
            <?php if ($item['tyyppi'] === 'kuva'): ?><div class="image-annotation-preview mt-2"><img src="<?= editValue($item['url']) ?>" alt="" loading="lazy"><button type="button" class="btn btn-outline-secondary btn-sm" data-annotation-open data-media-id="<?= (int) $item['id'] ?>" data-image-url="<?= editValue($item['url']) ?>" data-annotations="<?= editValue(json_encode(decodeImageAnnotations($item['merkinnat_json'] ?? null), JSON_UNESCAPED_UNICODE)) ?>">Muokkaa merkintöjä</button></div><?php endif; ?>
            <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="row g-2 mt-1 align-items-center">
                <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-media"><input type="hidden" name="media_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>">
                <div class="col-md-8"><input class="form-control form-control-sm" name="kuvateksti" value="<?= editValue($item['kuvateksti']) ?>" placeholder="Kuvateksti"></div>
                <div class="col-md-auto"><button class="btn btn-outline-primary btn-sm">Tallenna kuvateksti</button></div>
            </form>
            <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="d-inline" onsubmit="return confirm('Poistetaanko media?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-media"><input type="hidden" name="media_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><button class="btn btn-link btn-sm text-danger">Poista</button></form>
        </div><?php endif; ?><?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="row g-2 mt-2"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-media"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><div class="col-md-2"><select class="form-select form-select-sm" name="media_tyyppi" required><option value="kuva">Kuva</option><option value="videolinkki">Videolinkki</option></select></div><div class="col-md-4"><div class="file-drop-zone" data-file-drop><input type="file" class="form-control form-control-sm" name="media_files[]" multiple accept="image/jpeg,image/png,image/webp"><div class="file-drop-zone-hint">Raahaa kuvat tähän tai valitse tiedostot.</div></div><div class="form-text">Voit valita useita kuvia.</div></div><div class="col-md-3"><input class="form-control form-control-sm" name="media_url" placeholder="Videolinkin HTTPS-osoite"></div><div class="col-md-2"><input class="form-control form-control-sm" name="kuvateksti" placeholder="Kuvateksti"></div><div class="col-md-1"><button class="btn btn-outline-primary btn-sm w-100">+</button></div></form>
        <?php foreach ($contentBlocks[(int) $row['id']] ?? [] as $contentBlock): ?><?php if ($contentBlock['type'] === 'text'): ?><?php $textBlock = $contentBlock['data']; ?>
            <div class="report-text-block mt-4" id="teksti-<?= (int) $textBlock['id'] ?>">
                <div class="d-flex justify-content-between align-items-center mb-2"><strong>Tekstisisältö</strong>
                    <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" onsubmit="return confirm('Poistetaanko tekstisisältö?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-text"><input type="hidden" name="teksti_id" value="<?= (int) $textBlock['id'] ?>"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><button class="btn btn-outline-danger btn-sm">Poista teksti</button></form>
                </div>
                <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>">
                    <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-text"><input type="hidden" name="teksti_id" value="<?= (int) $textBlock['id'] ?>"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><textarea class="form-control mb-2" name="tekstisisalto" rows="6" data-markdown-editor><?= editValue($textBlock['sisalto'] ?? '') ?></textarea><button class="btn btn-primary btn-sm">Tallenna teksti</button>
                </form>
            </div>
        <?php else: ?><?php $table = $contentBlock['data']; ?>
            <div class="border-top mt-4 pt-3" id="taulukko-<?= (int) $table['id'] ?>">
                <div class="d-flex justify-content-between align-items-center mb-2"><strong>Taulukko</strong>
                    <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" onsubmit="return confirm('Poistetaanko taulukko ja kaikki sen tiedot?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-table"><input type="hidden" name="taulukko_id" value="<?= (int) $table['id'] ?>"><button class="btn btn-outline-danger btn-sm">Poista taulukko</button></form>
                </div>
                <form class="report-table-form" method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>">
                    <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="taulukko_id" value="<?= (int) $table['id'] ?>">
                    <div class="row g-2 mb-2 align-items-end"><div class="col-md-5"><label class="form-label mb-1" for="taulukko-otsikko-<?= (int) $table['id'] ?>">Taulukon nimi</label><input class="form-control form-control-sm" id="taulukko-otsikko-<?= (int) $table['id'] ?>" name="taulukko_otsikko" value="<?= editValue($table['otsikko'] ?? '') ?>" maxlength="255"></div><div class="col-md-3"><label class="form-label mb-1" for="taulukko-leveys-<?= (int) $table['id'] ?>">Taulukon leveys</label><select class="form-select form-select-sm" id="taulukko-leveys-<?= (int) $table['id'] ?>" name="taulukko_leveys"><option value="keskitetty" <?= ($table['leveys_tila'] ?? 'keskitetty') === 'keskitetty' ? 'selected' : '' ?>>Keskitetty</option><option value="kokosivu" <?= ($table['leveys_tila'] ?? '') === 'kokosivu' ? 'selected' : '' ?>>Koko sivun levyinen</option></select></div><div class="col-md-4"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="taulukko-reunat-<?= (int) $table['id'] ?>" name="taulukko_reunat" <?= (int) ($table['reunat'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="taulukko-reunat-<?= (int) $table['id'] ?>">Reunukset</label></div></div></div>
                    <div class="row g-2 mb-2"><div class="col-md-3"><label class="form-label mb-1" for="taulukko-tasaus-pysty-<?= (int) $table['id'] ?>">Pystysuuntainen tasaus</label><select class="form-select form-select-sm" id="taulukko-tasaus-pysty-<?= (int) $table['id'] ?>" name="taulukko_tasaus_pysty"><option value="ylos" <?= ($table['tasaus_pysty'] ?? 'alas') === 'ylos' ? 'selected' : '' ?>>Ylös</option><option value="keskelle" <?= ($table['tasaus_pysty'] ?? '') === 'keskelle' ? 'selected' : '' ?>>Keskelle</option><option value="alas" <?= ($table['tasaus_pysty'] ?? 'alas') === 'alas' ? 'selected' : '' ?>>Alas</option></select></div><div class="col-md-3"><label class="form-label mb-1" for="taulukko-tasaus-vaaka-<?= (int) $table['id'] ?>">Vaakasuuntainen tasaus</label><select class="form-select form-select-sm" id="taulukko-tasaus-vaaka-<?= (int) $table['id'] ?>" name="taulukko_tasaus_vaaka"><option value="vasen" <?= ($table['tasaus_vaaka'] ?? 'vasen') === 'vasen' ? 'selected' : '' ?>>Vasen</option><option value="keski" <?= ($table['tasaus_vaaka'] ?? '') === 'keski' ? 'selected' : '' ?>>Keskitä</option><option value="oikea" <?= ($table['tasaus_vaaka'] ?? '') === 'oikea' ? 'selected' : '' ?>>Oikea</option></select></div><div class="col-md-6"><span class="small text-muted">Sarakkeiden fonttivärit:</span><?php foreach ($table['sarakkeet'] as $column): ?><label class="small text-muted ms-3"><?= editValue($column['nimi']) ?> <input type="color" name="sarake_fonttivari[<?= (int) $table['id'] ?>][<?= (int) $column['id'] ?>]" value="<?= editValue(preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($column['fonttivari'] ?? '')) ? $column['fonttivari'] : '#232d3c') ?>"></label><?php endforeach; ?></div></div>
                    <div class="table-responsive"><table class="table table-sm mb-2 <?= (int) ($table['reunat'] ?? 1) === 1 ? 'table-bordered' : 'table-no-borders' ?>"><thead><tr><?php foreach ($table['sarakkeet'] as $column): ?><th style="background-color:<?= editValue(preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($column['taustavari'] ?? '')) ? $column['taustavari'] : '#ffffff') ?>"><input class="form-control form-control-sm" name="sarake_nimi[<?= (int) $table['id'] ?>][<?= (int) $column['id'] ?>]" value="<?= editValue($column['nimi']) ?>"><label class="small text-muted d-flex align-items-center gap-1 mt-1">Taustaväri <input type="color" name="sarake_taustavari[<?= (int) $table['id'] ?>][<?= (int) $column['id'] ?>]" value="<?= editValue(preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($column['taustavari'] ?? '')) ? $column['taustavari'] : '#ffffff') ?>"></label><div class="text-end mt-1"><button class="btn btn-link btn-sm text-danger p-0" type="submit" name="action" value="delete-table-column" onclick="this.form.sarake_id.value='<?= (int) $column['id'] ?>';">Poista sarake</button></div></th><?php endforeach; ?><th aria-hidden="true"></th></tr></thead><tbody><?php foreach ($table['rivit'] as $tableRow): ?><tr><?php foreach ($table['sarakkeet'] as $column): ?><td style="background-color:<?= editValue(preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($column['taustavari'] ?? '')) ? $column['taustavari'] : '#ffffff') ?>"><textarea class="form-control form-control-sm" name="solu[<?= (int) $table['id'] ?>][<?= (int) $tableRow['id'] ?>][<?= (int) $column['id'] ?>]" rows="2" data-markdown-editor><?= editValue($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '') ?></textarea></td><?php endforeach; ?><td class="border-0 p-1"><button class="btn btn-link btn-sm text-danger" type="submit" name="action" value="delete-table-row" onclick="this.form.taulukko_rivi_id.value='<?= (int) $tableRow['id'] ?>';">Poista rivi</button></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php if ($table['yhteenvetorivit']): ?><div class="small fw-semibold mt-3">Summarivit</div><?php foreach ($table['yhteenvetorivit'] as $summary): ?><div class="border rounded p-2 mt-2"><div class="row g-2 align-items-center"><div class="col-md-2"><select class="form-select form-select-sm" name="yhteenveto[<?= (int) $summary['id'] ?>][tyyppi]"><option value="summa" <?= $summary['tyyppi'] === 'summa' ? 'selected' : '' ?>>Summarivi</option><option value="valisummaa" <?= $summary['tyyppi'] === 'valisummaa' ? 'selected' : '' ?>>Välisummaa</option></select></div><div class="col-md-3"><select class="form-select form-select-sm" name="yhteenveto[<?= (int) $summary['id'] ?>][jalkeen_rivi_id]"><option value="0">Taulukon loppuun</option><?php foreach ($table['rivit'] as $tableRow): ?><option value="<?= (int) $tableRow['id'] ?>" <?= (int) $summary['jalkeen_rivi_id'] === (int) $tableRow['id'] ? 'selected' : '' ?>>Rivin <?= editValue($table['solut'][(int) $tableRow['id']][(int) $table['sarakkeet'][0]['id']] ?? '') ?> jälkeen</option><?php endforeach; ?></select></div><div class="col-md-6"><?php foreach ($table['sarakkeet'] as $column): ?><label class="me-2"><input type="checkbox" name="yhteenveto[<?= (int) $summary['id'] ?>][sarakkeet][]" value="<?= (int) $column['id'] ?>" <?= in_array((int) $column['id'], $summary['sarakkeet'], true) ? 'checked' : '' ?>> <?= editValue($column['nimi']) ?></label><?php endforeach; ?></div><div class="col-md-1 text-end"><button class="btn btn-link btn-sm text-danger" type="submit" name="action" value="delete-table-summary" onclick="this.form.yhteenvetorivi_id.value='<?= (int) $summary['id'] ?>';">Poista</button></div></div><div class="mt-2 small fw-semibold">Laskentarivit</div><?php foreach ($table['laskentarivit'] as $calculation): ?><?php if ((int) $calculation['yhteenvetorivi_id'] === (int) $summary['id']): ?><div class="d-flex flex-wrap gap-2 align-items-center mt-1"><span><?= editValue($calculation['nimittaja']) ?> (yksiköt: <?= editValue($calculation['yksikot']) ?>), <?= $calculation['toiminto'] === 'jaa' ? 'jaa' : ($calculation['toiminto'] === 'kerro' ? 'kertoo' : 'kertoo % summasta') ?>, lähde: <?= editValue($table['sarakkeet'][array_search((int) $calculation['lahde_sarake_id'], array_column($table['sarakkeet'], 'id'), true)]['nimi'] ?? '') ?></span><button class="btn btn-link btn-sm text-danger" type="submit" name="action" value="delete-table-calculation" onclick="this.form.laskentarivi_id.value='<?= (int) $calculation['id'] ?>';">Poista</button></div><?php endif; ?><?php endforeach; ?><div class="row g-2 mt-2"><input type="hidden" name="laskenta[yhteenvetorivi_id]" value="<?= (int) $summary['id'] ?>"><div class="col-md-3"><input class="form-control form-control-sm" name="laskenta[nimittaja]" placeholder="Nimittäjä, esim. Lainaosuus per yksikkö"></div><div class="col-md-2"><input class="form-control form-control-sm" name="laskenta[yksikot]" inputmode="decimal" placeholder="Yksiköiden lukumäärä"></div><div class="col-md-2"><select class="form-select form-select-sm" name="laskenta[toiminto]"><option value="jaa">Jaa yksiköillä</option><option value="kerro">Kerro yksiköillä</option><option value="prosentti">Kerro % summasta</option></select></div><div class="col-md-3"><select class="form-select form-select-sm" name="laskenta[lahde_sarake_id]"><option value="0">Valitse summarivin solu</option><?php foreach ($table['sarakkeet'] as $column): ?><?php if (in_array((int) $column['id'], $summary['sarakkeet'], true)): ?><option value="<?= (int) $column['id'] ?>"><?= editValue($column['nimi']) ?></option><?php endif; ?><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100" type="submit" name="action" value="add-table-calculation">Lisää laskentarivi</button></div></div></div><?php endforeach; ?><?php endif; ?>
                    <input type="hidden" name="sarake_id" value=""><input type="hidden" name="taulukko_rivi_id" value=""><input type="hidden" name="yhteenvetorivi_id" value=""><input type="hidden" name="laskentarivi_id" value=""><div class="d-flex flex-wrap gap-2 align-items-center"><button class="btn btn-primary btn-sm" type="submit" name="action" value="update-table">Tallenna taulukko</button><input class="form-control form-control-sm" style="max-width:220px" name="uusi_sarake_nimi" placeholder="Uuden sarakkeen nimi"><button class="btn btn-outline-secondary btn-sm" type="submit" name="action" value="add-table-column">Tallenna ja lisää sarake</button><button class="btn btn-outline-secondary btn-sm" type="submit" name="action" value="add-table-row">Tallenna ja lisää rivi</button></div>
                    <div class="border rounded p-2 mt-2"><div class="small fw-semibold mb-1">Lisää summarivi</div><div class="row g-2"><div class="col-md-2"><select class="form-select form-select-sm" name="yhteenveto_tyyppi"><option value="summa">Summarivi</option><option value="valisummaa">Välisummaa</option></select></div><div class="col-md-3"><select class="form-select form-select-sm" name="yhteenveto_jalkeen_rivi_id"><option value="0">Taulukon loppuun</option><?php foreach ($table['rivit'] as $tableRow): ?><option value="<?= (int) $tableRow['id'] ?>">Rivin <?= editValue($table['solut'][(int) $tableRow['id']][(int) $table['sarakkeet'][0]['id']] ?? '') ?> jälkeen</option><?php endforeach; ?></select></div><div class="col-md-6"><?php foreach ($table['sarakkeet'] as $column): ?><label class="me-2"><input type="checkbox" name="yhteenveto_sarakkeet[]" value="<?= (int) $column['id'] ?>"> <?= editValue($column['nimi']) ?></label><?php endforeach; ?></div><div class="col-md-1"><button class="btn btn-outline-secondary btn-sm w-100" type="submit" name="action" value="add-table-summary">Lisää</button></div></div></div>
                </form>
            </div>
        <?php endif; ?><?php endforeach; ?>
        <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="d-flex gap-2 mt-3"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-table"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input class="form-control form-control-sm" name="taulukko_otsikko" placeholder="Uuden taulukon otsikko"><button class="btn btn-outline-primary btn-sm">+ Lisää taulukko</button></form>
        <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="report-text-add mt-3"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-text"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><label class="form-label" for="uusi-teksti-<?= (int) $row['id'] ?>">Lisää teksti osion loppuun</label><textarea class="form-control mb-2" id="uusi-teksti-<?= (int) $row['id'] ?>" name="tekstisisalto" rows="6" data-markdown-editor placeholder="Kirjoita tekstisisältö tähän"></textarea><button class="btn btn-outline-primary btn-sm">+ Lisää teksti</button></form>
    </div></details>
<?php endforeach; ?>

<section class="card border-0 shadow-sm mb-4" id="liitteet">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Raportin PDF-liitteet</h2></div>
    <div class="card-body p-4">
        <?php if (!$attachments): ?><p class="text-muted mb-3">Ei liitteitä.</p><?php endif; ?>
        <?php foreach ($attachments as $attachment): ?><div class="d-flex align-items-center gap-2 border-bottom py-2"><span class="flex-grow-1"><?= editValue($attachment['alkuperainen_nimi']) ?></span><small class="text-muted"><?= number_format((int) $attachment['tiedostokoko'] / 1024, 0, ',', ' ') ?> kt</small><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" onsubmit="return confirm('Poistetaanko liite?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-attachment"><input type="hidden" name="liite_id" value="<?= (int) $attachment['id'] ?>"><input type="hidden" name="return_anchor" value="liitteet"><button class="btn btn-link btn-sm text-danger">Poista</button></form></div><?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="row g-2 mt-3"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-attachment"><input type="hidden" name="return_anchor" value="liitteet"><div class="col-md-10"><div class="file-drop-zone" data-file-drop><input type="file" class="form-control" name="liite_tiedosto[]" accept="application/pdf,.pdf" multiple required><div class="file-drop-zone-hint">Raahaa PDF-tiedostot tähän tai valitse tiedostot.</div></div><div class="form-text">PDF-tiedostoja, enintään 10 MB/tiedosto. Liitteet tulevat raportin loppuun allekirjoituksen jälkeen valitussa järjestyksessä.</div></div><div class="col-md-2"><button class="btn btn-outline-primary w-100">+ Lisää liitteet</button></div></form>
    </div>
</section>

<span id="uusi-osio"></span>
<div class="card border-0 shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Lisää uusi osio</h2></div><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-row"><div class="card-body p-4"><input class="form-control mb-3" name="rivi_otsikko" placeholder="Osion otsikko" maxlength="255" required><textarea class="form-control mb-3" name="rivi_sisalto" rows="6" placeholder="Tekstisisältö" data-markdown-editor></textarea><div class="form-check"><input class="form-check-input" type="checkbox" id="uusi-asiakastiedot" name="sisaltaa_asiakastiedot"><label class="form-check-label" for="uusi-asiakastiedot">Tulosta asiakkaan tiedot tämän osion alussa</label></div></div><div class="card-footer bg-white border-0 px-4 pb-4"><button class="btn btn-primary">+ Lisää osio</button></div></form></div>

<dialog class="image-annotation-dialog" id="image-annotation-dialog" aria-labelledby="image-annotation-title">
    <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" id="image-annotation-form">
        <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>">
        <input type="hidden" name="action" value="update-media-annotations">
        <input type="hidden" name="media_id" id="image-annotation-media-id" value="">
        <input type="hidden" name="merkinnat_json" id="image-annotation-json" value="[]">
        <input type="hidden" name="return_anchor" id="image-annotation-return-anchor" value="">
        <div class="image-annotation-toolbar">
            <strong id="image-annotation-title" class="me-2">Kuvan merkinnät</strong>
            <button type="button" class="btn btn-outline-secondary btn-sm active" data-annotation-tool="select">Valitse</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-annotation-tool="arrow">Nuoli</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-annotation-tool="rectangle">Suorakulmio</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-annotation-tool="circle">Ympyrä</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-annotation-tool="freehand">Vapaa piirto</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-annotation-tool="text">Teksti</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-annotation-tool="crop">Rajaa</button>
            <label>Tekstin koko <select id="image-annotation-font-size"><option value="16">Pieni</option><option value="24" selected>Normaali</option><option value="36">Suuri</option><option value="52">Erittäin suuri</option></select></label>
            <label class="ms-2">Väri <input type="color" id="image-annotation-color" value="#dc3545"></label>
            <button type="button" class="btn btn-outline-danger btn-sm ms-auto" id="image-annotation-delete" disabled>Poista valittu</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="image-annotation-close">Sulje</button>
            <button type="submit" class="btn btn-primary btn-sm">Tallenna merkinnät</button>
        </div>
        <div class="image-annotation-canvas-wrap"><canvas id="image-annotation-canvas"></canvas></div>
    </form>
</dialog>

<script>
document.querySelectorAll('.report-table-form').forEach(function (form) {
    const table = form.querySelector('table');
    const tableContainer = table ? table.closest('.table-responsive') : null;
    const settingsRows = Array.from(form.children).filter(function (item) {
        return item.matches('.row.g-2.mb-2.align-items-end, .row.g-2.mb-2:not(.align-items-end)');
    });
    if (!table || !tableContainer || settingsRows.length !== 2) return;

    const settings = document.createElement('div');
    settings.className = 'report-table-settings';
    form.insertBefore(settings, tableContainer);
    settingsRows.forEach(function (row) {
        settings.appendChild(row);
    });

    const settingsRow = settingsRows.find(function (row) {
        return !row.classList.contains('align-items-end');
    });
    if (!settingsRow) return;

    const labels = Array.from(table.querySelectorAll('th label')).filter(function (label) {
        return label.querySelector('input[name^="sarake_taustavari["]');
    });
    if (!labels.length) return;

    const backgroundSettings = document.createElement('div');
    backgroundSettings.className = 'report-column-background-colors';
    backgroundSettings.innerHTML = '<span class="small text-muted">Sarakkeiden taustavärit:</span>';
    labels.forEach(function (label) {
        const columnTitle = label.closest('th').querySelector('input[name^="sarake_nimi["]');
        const caption = document.createElement('span');
        caption.textContent = columnTitle && columnTitle.value.trim() ? columnTitle.value.trim() : 'Sarake';
        Array.from(label.childNodes).forEach(function (node) {
            if (node.nodeType === Node.TEXT_NODE) node.remove();
        });
        label.insertBefore(caption, label.firstChild);
        backgroundSettings.appendChild(label);
    });
    settingsRow.appendChild(backgroundSettings);

    form.querySelectorAll('button[name="action"][value="delete-table-column"]').forEach(function (button) {
        button.textContent = 'Poista sarake';
    });
    form.querySelectorAll('button[name="action"][value="delete-table-row"]').forEach(function (button) {
        button.classList.add('report-delete-row-icon');
        button.setAttribute('aria-label', 'Poista rivi');
        button.setAttribute('title', 'Poista rivi');
        button.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>';
    });
});

document.querySelectorAll('form[action^="muokkaa_raportti.php"]').forEach(function (form) {
    form.addEventListener('submit', function () {
        if (form.querySelector('input[name="return_anchor"]')) return;
        const anchor = form.closest('[id^="taulukko-"], [id^="osio-"], #perustiedot');
        if (!anchor || !anchor.id) return;
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'return_anchor';
        input.value = anchor.id;
        form.appendChild(input);
    });
});

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
        (input.multiple ? droppedFiles : droppedFiles.slice(0, 1)).forEach(function (file) {
            transfer.items.add(file);
        });
        input.files = transfer.files;
    });
});

(function () {
    const dialog = document.getElementById('image-annotation-dialog');
    const canvas = document.getElementById('image-annotation-canvas');
    const context = canvas ? canvas.getContext('2d') : null;
    const form = document.getElementById('image-annotation-form');
    const fontSizeSelect = document.getElementById('image-annotation-font-size');
    if (!dialog || !canvas || !context || !form) return;

    const image = new Image();
    let annotations = [];
    let selectedIndex = -1;
    let activeTool = 'select';
    let drawing = null;
    let dragging = null;

    function pointFromEvent(event) {
        const bounds = canvas.getBoundingClientRect();
        return {
            x: Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width)),
            y: Math.max(0, Math.min(1, (event.clientY - bounds.top) / bounds.height))
        };
    }

    function canvasPoint(point) {
        return { x: point.x * canvas.width, y: point.y * canvas.height };
    }

    function drawAnnotation(annotation, index) {
        const start = canvasPoint({x: Number(annotation.x1) || 0, y: Number(annotation.y1) || 0});
        const end = canvasPoint({x: Number(annotation.x2) || 0, y: Number(annotation.y2) || 0});
        context.strokeStyle = annotation.color || '#dc3545';
        context.fillStyle = annotation.color || '#dc3545';
        context.lineWidth = index === selectedIndex ? 6 : 4;
        context.setLineDash(index === selectedIndex ? [8, 5] : []);
        if (annotation.type === 'rectangle') {
            context.strokeRect(start.x, start.y, end.x - start.x, end.y - start.y);
        } else if (annotation.type === 'crop') {
            context.fillStyle = 'rgba(255,255,255,.22)';
            context.fillRect(start.x, start.y, end.x - start.x, end.y - start.y);
            context.strokeStyle = '#198754';
            context.setLineDash([10, 6]);
            context.strokeRect(start.x, start.y, end.x - start.x, end.y - start.y);
        } else if (annotation.type === 'circle') {
            context.beginPath();
            context.ellipse((start.x + end.x) / 2, (start.y + end.y) / 2, Math.abs(end.x - start.x) / 2, Math.abs(end.y - start.y) / 2, 0, 0, Math.PI * 2);
            context.stroke();
        } else if (annotation.type === 'arrow') {
            context.beginPath();
            context.moveTo(start.x, start.y);
            context.lineTo(end.x, end.y);
            context.stroke();
            const angle = Math.atan2(end.y - start.y, end.x - start.x);
            const size = 18;
            context.beginPath();
            context.moveTo(end.x, end.y);
            context.lineTo(end.x - size * Math.cos(angle - .5), end.y - size * Math.sin(angle - .5));
            context.moveTo(end.x, end.y);
            context.lineTo(end.x - size * Math.cos(angle + .5), end.y - size * Math.sin(angle + .5));
            context.stroke();
        } else if (annotation.type === 'freehand') {
            const points = Array.isArray(annotation.points) ? annotation.points : [];
            if (points.length) {
                context.beginPath();
                points.forEach(function (point, pointIndex) {
                    const current = canvasPoint({x: Number(point[0]) || 0, y: Number(point[1]) || 0});
                    if (pointIndex === 0) context.moveTo(current.x, current.y);
                    else context.lineTo(current.x, current.y);
                });
                context.stroke();
            }
        } else if (annotation.type === 'text') {
            context.setLineDash([]);
            const fontSize = Math.max(8, (Number(annotation.fontSize) || .025) * canvas.width);
            context.font = 'bold ' + fontSize + 'px sans-serif';
            context.fillText(annotation.text || '', start.x, start.y);
        }
        context.setLineDash([]);
    }

    function render() {
        if (!image.complete || !image.naturalWidth) return;
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);
        annotations.forEach(drawAnnotation);
        if (drawing) drawAnnotation(drawing, -1);
        document.getElementById('image-annotation-delete').disabled = selectedIndex < 0;
    }

    function distanceToSegment(point, start, end) {
        const dx = end.x - start.x;
        const dy = end.y - start.y;
        const lengthSquared = dx * dx + dy * dy;
        const ratio = lengthSquared ? Math.max(0, Math.min(1, ((point.x - start.x) * dx + (point.y - start.y) * dy) / lengthSquared)) : 0;
        const x = start.x + ratio * dx;
        const y = start.y + ratio * dy;
        return Math.hypot(point.x - x, point.y - y);
    }

    function hitTest(point) {
        for (let index = annotations.length - 1; index >= 0; index--) {
            const annotation = annotations[index];
            const x1 = Math.min(Number(annotation.x1) || 0, Number(annotation.x2) || 0);
            const x2 = Math.max(Number(annotation.x1) || 0, Number(annotation.x2) || 0);
            const y1 = Math.min(Number(annotation.y1) || 0, Number(annotation.y2) || 0);
            const y2 = Math.max(Number(annotation.y1) || 0, Number(annotation.y2) || 0);
            if (annotation.type === 'freehand') {
                const points = Array.isArray(annotation.points) ? annotation.points : [];
                for (let pointIndex = 1; pointIndex < points.length; pointIndex++) {
                    if (distanceToSegment(point, {x: points[pointIndex - 1][0], y: points[pointIndex - 1][1]}, {x: points[pointIndex][0], y: points[pointIndex][1]}) < .03) return index;
                }
            } else if (annotation.type === 'text' ? Math.hypot(point.x - x1, point.y - y1) < .08 : point.x >= x1 - .03 && point.x <= x2 + .03 && point.y >= y1 - .03 && point.y <= y2 + .03) {
                return index;
            }
        }
        return -1;
    }

    function moveAnnotation(annotation, dx, dy) {
        annotation.x1 = Math.max(0, Math.min(1, Number(annotation.x1) + dx));
        annotation.y1 = Math.max(0, Math.min(1, Number(annotation.y1) + dy));
        annotation.x2 = Math.max(0, Math.min(1, Number(annotation.x2) + dx));
        annotation.y2 = Math.max(0, Math.min(1, Number(annotation.y2) + dy));
        if (annotation.type === 'freehand') {
            annotation.points = (annotation.points || []).map(function (point) {
                return [Math.max(0, Math.min(1, Number(point[0]) + dx)), Math.max(0, Math.min(1, Number(point[1]) + dy))];
            });
        }
    }

    document.querySelectorAll('[data-annotation-tool]').forEach(function (button) {
        button.addEventListener('click', function () {
            activeTool = button.dataset.annotationTool;
            document.querySelectorAll('[data-annotation-tool]').forEach(function (item) { item.classList.toggle('active', item === button); });
        });
    });

    document.querySelectorAll('[data-annotation-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            try { annotations = JSON.parse(button.dataset.annotations || '[]'); } catch (error) { annotations = []; }
            selectedIndex = -1;
            document.getElementById('image-annotation-media-id').value = button.dataset.mediaId;
            document.getElementById('image-annotation-return-anchor').value = (button.closest('[id^="osio-"]') || {}).id || '';
            image.onload = function () {
                // Sovita koko kuva työkalulle. Canvasin todellinen koko pienenee
                // tarvittaessa myös korkeuden perusteella, jotta vieritystä ei
                // tarvita ja koko rajattava alue on kerralla nähtävissä.
                const maxWidth = Math.min(1400, window.innerWidth * .9);
                const maxHeight = Math.max(240, window.innerHeight * .60);
                const scale = Math.min(1, maxWidth / image.naturalWidth, maxHeight / image.naturalHeight);
                canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
                canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
                render();
            };
            image.src = button.dataset.imageUrl;
            dialog.showModal();
        });
    });

    canvas.addEventListener('pointerdown', function (event) {
        event.preventDefault();
        canvas.setPointerCapture(event.pointerId);
        const point = pointFromEvent(event);
        const color = document.getElementById('image-annotation-color').value;
        if (activeTool === 'select') {
            selectedIndex = hitTest(point);
            dragging = selectedIndex >= 0 ? {index: selectedIndex, point: point, original: JSON.parse(JSON.stringify(annotations[selectedIndex]))} : null;
            render();
        } else if (activeTool === 'text') {
            const text = window.prompt('Teksti kuvaan:');
            if (text) {
                const size = Number(document.getElementById('image-annotation-font-size').value) || 24;
                annotations.push({type: 'text', x1: point.x, y1: point.y, x2: point.x, y2: point.y, text: text.slice(0, 500), fontSize: size / canvas.width, color: color});
                selectedIndex = annotations.length - 1;
                render();
            }
        } else if (activeTool === 'freehand') {
            drawing = {type: 'freehand', x1: point.x, y1: point.y, x2: point.x, y2: point.y, points: [[point.x, point.y]], color: color};
        } else if (activeTool === 'crop') {
            drawing = {type: 'crop', x1: point.x, y1: point.y, x2: point.x, y2: point.y, color: '#198754'};
        } else {
            drawing = {type: activeTool, x1: point.x, y1: point.y, x2: point.x, y2: point.y, color: color};
        }
    });

    canvas.addEventListener('pointermove', function (event) {
        const point = pointFromEvent(event);
        if (dragging) {
            annotations[dragging.index] = JSON.parse(JSON.stringify(dragging.original));
            moveAnnotation(annotations[dragging.index], point.x - dragging.point.x, point.y - dragging.point.y);
            render();
        } else if (drawing) {
            drawing.x2 = point.x;
            drawing.y2 = point.y;
            if (drawing.type === 'freehand') drawing.points.push([point.x, point.y]);
            render();
        }
    });

    canvas.addEventListener('pointerup', function () {
        if (drawing) {
            if (drawing.type === 'crop') annotations = annotations.filter(function (annotation) { return annotation.type !== 'crop'; });
            if (drawing.type !== 'freehand' || drawing.points.length > 1) annotations.push(drawing);
            selectedIndex = annotations.length - 1;
            drawing = null;
            render();
        }
        dragging = null;
    });

    document.getElementById('image-annotation-delete').addEventListener('click', function () {
        if (selectedIndex < 0) return;
        annotations.splice(selectedIndex, 1);
        selectedIndex = -1;
        render();
    });
    fontSizeSelect.addEventListener('change', function () {
        if (selectedIndex < 0 || annotations[selectedIndex].type !== 'text') return;
        annotations[selectedIndex].fontSize = (Number(fontSizeSelect.value) || 24) / canvas.width;
        render();
    });
    document.getElementById('image-annotation-close').addEventListener('click', function () { dialog.close(); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Delete' && dialog.open && selectedIndex >= 0) {
            annotations.splice(selectedIndex, 1);
            selectedIndex = -1;
            render();
        }
    });
    form.addEventListener('submit', function () {
        document.getElementById('image-annotation-json').value = JSON.stringify(annotations);
    });
    const initialButton = document.querySelector('[data-annotation-open][data-media-id="<?= $editMediaId ?>"]');
    if (initialButton) initialButton.click();
})();
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
<script>
(function () {
    const names = <?= json_encode(array_reduce($tables, static function (array $result, array $table): array {
        foreach ($table['yhteenvetorivit'] ?? [] as $summary) {
            $result[(string) $summary['id']] = trim((string) ($summary['nimittaja'] ?? '')) ?: 'Yhteensä';
        }
        return $result;
    }, []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    document.querySelectorAll('select[name^="yhteenveto["][name$="][tyyppi]"]').forEach(function (select) {
        const match = select.name.match(/^yhteenveto\[(\d+)\]/);
        if (!match) return;
        const id = match[1];
        const wrapper = document.createElement('div');
        wrapper.className = 'col-md-2';
        wrapper.innerHTML = '<label class="visually-hidden">Summarivin nimittäjä</label><input class="form-control form-control-sm" name="yhteenveto[' + id + '][nimittaja]" value="' + (names[id] || 'Yhteensä').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;') + '" maxlength="255" placeholder="Yhteensä">';
        select.parentElement.parentElement.insertBefore(wrapper, select.parentElement);
    });
    document.querySelectorAll('select[name="yhteenveto_tyyppi"]').forEach(function (select) {
        const wrapper = document.createElement('div');
        wrapper.className = 'col-md-2';
        wrapper.innerHTML = '<label class="visually-hidden">Summarivin nimittäjä</label><input class="form-control form-control-sm" name="yhteenveto_nimittaja" value="Yhteensä" maxlength="255" placeholder="Yhteensä">';
        select.parentElement.insertBefore(wrapper, select);
    });
}());
</script>
<?php closeLayoutShell(); ?>
