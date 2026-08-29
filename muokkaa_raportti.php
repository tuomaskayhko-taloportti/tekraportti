<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();
if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$currentLaatijaId = $laatijatId;
$raporttiId = (int) ($_GET['id'] ?? $_POST['raportti_id'] ?? 0);
$csrfToken = getApplicationCsrfToken();
$message = '';
$messageType = 'danger';
$report = null;
$customers = [];
$projects = [];
$sharedEmails = [];
$rows = [];
$media = [];
$tables = [];
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

    if (!hash_equals($csrfToken, $postedToken)) {
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
                $projectValue = $projectId > 0 ? $projectId : null;
                $placeValue = $place !== '' ? $place : null;
                $summaryValue = $summary !== '' ? $summary : null;
                $coverValue = $cover !== '' ? $cover : ($oldCover !== '' ? $oldCover : null);
                if (isset($_FILES['kansikuva']) && ($_FILES['kansikuva']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $coverValue = storeUploadedFile($_FILES['kansikuva'], 'kansikuvat');
                }
                if ($projectValue !== null) {
                    $query = $db->prepare('SELECT p.id FROM projektit p WHERE p.id = ? AND (p.laatijat_id = ? OR EXISTS (
                        SELECT 1 FROM projektijaot j WHERE j.projekti_id = p.id AND j.laatijat_id = ?
                    )) LIMIT 1');
                    $query->bind_param('iii', $projectValue, $laatijatId, $currentLaatijaId);
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
                        throw new RuntimeException('Sähköpostiosoitetta ei löytynyt laatijoista: ' . $sharedEmail);
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
                if ($rowTitle === '') {
                    throw new RuntimeException('Anna osiolle otsikko.');
                }
                $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $raporttiId, $laatijatId);
                $query->execute();
                $order = (int) $query->get_result()->fetch_assoc()['seuraava'];
                $query->close();
                $query = $db->prepare('INSERT INTO raporttirivit (laatijat_id, raportti_id, otsikko, sisalto, jarjestys) VALUES (?, ?, ?, ?, ?)');
                $query->bind_param('iissi', $laatijatId, $raporttiId, $rowTitle, $rowContent, $order);
                $query->execute();
                $query->close();
                $message = 'Osio lisättiin.';
                $messageType = 'success';
            } elseif ($action === 'update-row') {
                $rowId = (int) ($_POST['rivi_id'] ?? 0);
                $rowTitle = trim((string) ($_POST['rivi_otsikko'] ?? ''));
                $rowContent = trim((string) ($_POST['rivi_sisalto'] ?? ''));
                if ($rowId < 1 || $rowTitle === '') {
                    throw new RuntimeException('Osion otsikko puuttuu.');
                }
                $query = $db->prepare('UPDATE raporttirivit SET otsikko = ?, sisalto = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('ssiii', $rowTitle, $rowContent, $rowId, $raporttiId, $laatijatId);
                $query->execute();
                $query->close();
                $message = 'Osio tallennettiin.';
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
                $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttitaulukot WHERE raporttirivi_id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $rowId, $laatijatId);
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
            } elseif ($action === 'update-table') {
                $tableId = (int) ($_POST['taulukko_id'] ?? 0);
                $tableTitle = trim((string) ($_POST['taulukko_otsikko'] ?? ''));
                $columnNames = $_POST['sarake_nimi'] ?? [];
                $cellValues = $_POST['solu'] ?? [];
                $query = $db->prepare('SELECT id FROM raporttitaulukot WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $tableId, $raporttiId, $laatijatId); $query->execute();
                $tableExists = (bool) $query->get_result()->fetch_assoc(); $query->close();
                if (!$tableExists) {
                    throw new RuntimeException('Taulukkoa ei löytynyt.');
                }
                $query = $db->prepare('UPDATE raporttitaulukot SET otsikko = ? WHERE id = ? AND raportti_id = ? AND laatijat_id = ?');
                $query->bind_param('siii', $tableTitle, $tableId, $raporttiId, $laatijatId); $query->execute(); $query->close();
                $query = $db->prepare('UPDATE raporttitaulukko_sarakkeet SET nimi = ? WHERE id = ? AND taulukko_id = ? AND laatijat_id = ?');
                foreach ((array) ($columnNames[$tableId] ?? []) as $columnId => $columnName) {
                    $columnId = (int) $columnId;
                    $columnName = trim((string) $columnName);
                    if ($columnId > 0 && $columnName !== '') {
                        $query->bind_param('siii', $columnName, $columnId, $tableId, $laatijatId); $query->execute();
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
                $message = 'Taulukko tallennettiin.';
                $messageType = 'success';
            } elseif ($action === 'add-table-column') {
                $tableId = (int) ($_POST['taulukko_id'] ?? 0);
                $columnName = trim((string) ($_POST['sarake_nimi'] ?? ''));
                if ($columnName === '') { throw new RuntimeException('Anna sarakkeelle nimi.'); }
                $query = $db->prepare('SELECT id FROM raporttitaulukot WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $tableId, $raporttiId, $laatijatId); $query->execute();
                if (!(bool) $query->get_result()->fetch_assoc()) { throw new RuntimeException('Taulukkoa ei löytynyt.'); }
                $query->close();
                $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttitaulukko_sarakkeet WHERE taulukko_id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $tableId, $laatijatId); $query->execute(); $order = (int) $query->get_result()->fetch_assoc()['seuraava']; $query->close();
                $query = $db->prepare('INSERT INTO raporttitaulukko_sarakkeet (laatijat_id, taulukko_id, nimi, jarjestys) VALUES (?, ?, ?, ?)');
                $query->bind_param('iisi', $laatijatId, $tableId, $columnName, $order); $query->execute(); $query->close();
                $message = 'Sarake lisättiin.'; $messageType = 'success';
            } elseif ($action === 'add-table-row') {
                $tableId = (int) ($_POST['taulukko_id'] ?? 0);
                $query = $db->prepare('SELECT id FROM raporttitaulukot WHERE id = ? AND raportti_id = ? AND laatijat_id = ? LIMIT 1');
                $query->bind_param('iii', $tableId, $raporttiId, $laatijatId); $query->execute();
                if (!(bool) $query->get_result()->fetch_assoc()) { throw new RuntimeException('Taulukkoa ei löytynyt.'); }
                $query->close();
                $query = $db->prepare('SELECT COALESCE(MAX(jarjestys), 0) + 1 AS seuraava FROM raporttitaulukko_rivit WHERE taulukko_id = ? AND laatijat_id = ?');
                $query->bind_param('ii', $tableId, $laatijatId); $query->execute(); $order = (int) $query->get_result()->fetch_assoc()['seuraava']; $query->close();
                $query = $db->prepare('INSERT INTO raporttitaulukko_rivit (laatijat_id, taulukko_id, jarjestys) VALUES (?, ?, ?)');
                $query->bind_param('iii', $laatijatId, $tableId, $order); $query->execute(); $query->close();
                $message = 'Rivi lisättiin.'; $messageType = 'success';
            } elseif ($action === 'delete-table-column') {
                $columnId = (int) ($_POST['sarake_id'] ?? 0); $tableId = (int) ($_POST['taulukko_id'] ?? 0);
                $query = $db->prepare('DELETE FROM raporttitaulukko_sarakkeet WHERE id = ? AND taulukko_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $columnId, $tableId, $laatijatId); $query->execute(); $query->close();
                $message = 'Sarake poistettiin.'; $messageType = 'success';
            } elseif ($action === 'delete-table-row') {
                $tableRowId = (int) ($_POST['taulukko_rivi_id'] ?? 0); $tableId = (int) ($_POST['taulukko_id'] ?? 0);
                $query = $db->prepare('DELETE FROM raporttitaulukko_rivit WHERE id = ? AND taulukko_id = ? AND laatijat_id = ?');
                $query->bind_param('iii', $tableRowId, $tableId, $laatijatId); $query->execute(); $query->close();
                $message = 'Rivi poistettiin.'; $messageType = 'success';
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
            'update-row', 'delete-row', 'add-media', 'add-table' => 'osio-' . (int) ($_POST['rivi_id'] ?? 0),
            'update-table', 'add-table-column', 'add-table-row',
            'delete-table-column', 'delete-table-row', 'delete-table' => 'taulukko-' . (int) ($_POST['taulukko_id'] ?? 0),
            default => '',
        };
    }
    header('Location: muokkaa_raportti.php?id=' . $raporttiId . '&saved=1' . ($returnAnchor !== '' ? '#' . $returnAnchor : ''));
    exit;
}

try {
    $db = getDatabaseConnection();
    $query = $db->prepare('SELECT id, nimi, yritys FROM asiakkaat WHERE laatijat_id = ? ORDER BY nimi, yritys');
    $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $customers[] = $item; } $query->close();
    $query = $db->prepare('SELECT p.id, p.nimi FROM projektit p WHERE p.laatijat_id = ? OR EXISTS (
        SELECT 1 FROM projektijaot j WHERE j.projekti_id = p.id AND j.laatijat_id = ?
    ) ORDER BY p.nimi');
    $query->bind_param('ii', $laatijatId, $currentLaatijaId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $projects[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, otsikko, sisalto, jarjestys FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $rows[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, raporttirivi_id, tyyppi, url, kuvateksti FROM raporttimedia WHERE raportti_id = ? AND laatijat_id = ? ORDER BY raporttirivi_id, jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $media[] = $item; } $query->close();
    $query = $db->prepare('SELECT id, raporttirivi_id, otsikko, jarjestys FROM raporttitaulukot WHERE raportti_id = ? AND laatijat_id = ? ORDER BY raporttirivi_id, jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute(); $result = $query->get_result();
    while ($item = $result->fetch_assoc()) { $item['sarakkeet'] = []; $item['rivit'] = []; $item['solut'] = []; $tables[(int) $item['id']] = $item; } $query->close();
    if ($tables) {
        $query = $db->prepare('SELECT id, taulukko_id, nimi, jarjestys FROM raporttitaulukko_sarakkeet WHERE laatijat_id = ? ORDER BY taulukko_id, jarjestys, id');
        $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { if (isset($tables[(int) $item['taulukko_id']])) { $tables[(int) $item['taulukko_id']]['sarakkeet'][] = $item; } } $query->close();
        $query = $db->prepare('SELECT id, taulukko_id, jarjestys FROM raporttitaulukko_rivit WHERE laatijat_id = ? ORDER BY taulukko_id, jarjestys, id');
        $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { if (isset($tables[(int) $item['taulukko_id']])) { $tables[(int) $item['taulukko_id']]['rivit'][] = $item; } } $query->close();
        $query = $db->prepare('SELECT taulukko_id, rivi_id, sarake_id, sisalto FROM raporttitaulukko_solut WHERE laatijat_id = ? ORDER BY taulukko_id, rivi_id, sarake_id');
        $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { if (isset($tables[(int) $item['taulukko_id']])) { $tables[(int) $item['taulukko_id']]['solut'][(int) $item['rivi_id']][(int) $item['sarake_id']] = $item['sisalto']; } } $query->close();
    }
} catch (Throwable $error) {
    $message = 'Raportin tietojen haku epäonnistui.';
    error_log('Raportin muokkaussivun tietojen haku epäonnistui: ' . $error->getMessage());
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
.report-edit-header > a {
    white-space: nowrap;
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
        <?php if ($tables): ?><form method="get" action="esikatsele_raportti.php" target="_blank" class="report-edit-preview-form"><input type="hidden" name="id" value="<?= $raporttiId ?>"><?php foreach ($tables as $table): ?><label class="visually-hidden" for="kaavio-<?= (int) $table['id'] ?>">Kaavio taulukolle <?= (int) $table['id'] ?></label><select class="form-select form-select-sm" id="kaavio-<?= (int) $table['id'] ?>" name="kaavio[<?= (int) $table['id'] ?>]" title="Taulukon kaavio"><option value="">Ei kaaviota</option><option value="pie">Piirakka</option><option value="bar">Pylväs</option><option value="line">Viiva</option></select><?php endforeach; ?><button class="btn btn-outline-primary" type="submit">Esikatsele PDF:nä</button></form><?php else: ?><a class="btn btn-outline-primary" target="_blank" href="esikatsele_raportti.php?id=<?= $raporttiId ?>">Esikatsele PDF:nä</a><?php endif; ?>
        <a class="btn btn-outline-secondary" href="raportit.php">Takaisin raportteihin</a>
    </div>
</div>
<?php if ($message !== ''): ?><div class="alert alert-<?= editValue($messageType) ?>" role="alert"><?= editValue($message) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="card border-0 shadow-sm mb-4" id="perustiedot">
    <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-report"><input type="hidden" name="return_anchor" value="perustiedot">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Raportin perustiedot</h2></div><div class="card-body p-4"><div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="asiakas_id">Asiakas</label><select class="form-select" id="asiakas_id" name="asiakas_id" required><?php foreach ($customers as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $report['asiakas_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= editValue($item['yritys'] ? $item['nimi'] . ' – ' . $item['yritys'] : $item['nimi']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="projekti_id">Projekti</label><select class="form-select" id="projekti_id" name="projekti_id"><option value="">Ei projektia</option><?php foreach ($projects as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $report['projekti_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= editValue($item['nimi']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label" for="otsikko">Otsikko</label><input class="form-control" id="otsikko" name="otsikko" value="<?= editValue($report['otsikko']) ?>" maxlength="255" required></div>
        <div class="col-md-6"><label class="form-label" for="raporttipvm">Raporttipäivämäärä</label><input type="date" class="form-control" id="raporttipvm" name="raporttipvm" value="<?= editValue($report['raporttipvm']) ?>" required></div>
        <div class="col-md-6"><label class="form-label" for="laatimispaikka">Laatimispaikka</label><input class="form-control" id="laatimispaikka" name="laatimispaikka" value="<?= editValue($report['laatimispaikka']) ?>" maxlength="200"></div>
        <div class="col-md-6"><label class="form-label" for="jaettava_sahkoposti">Jaa raportti laatijoille</label><textarea class="form-control" id="jaettava_sahkoposti" name="jaettava_sahkoposti" rows="3" maxlength="4000" placeholder="laatija1@example.fi&#10;laatija2@example.fi"><?= editValue(implode("\n", $sharedEmails)) ?></textarea><div class="form-text">Yksi sähköpostiosoite riville. Osoitteiden täytyy löytyä laatijoista.</div></div>
        <div class="col-12"><label class="form-label" for="tiivistelma">Tiivistelmä</label><textarea class="form-control" id="tiivistelma" name="tiivistelma" rows="5"><?= editValue($report['tiivistelma']) ?></textarea></div>
        <div class="col-12"><label class="form-label" for="kansikuva">Kansikuva</label><div class="file-drop-zone" data-file-drop><input type="file" class="form-control" id="kansikuva" name="kansikuva" accept="image/jpeg,image/png,image/webp"><div class="file-drop-zone-hint">Voit myös raahata kuvan tähän.</div></div><div class="form-text">Uusi kuva korvaa nykyisen kansikuvan. JPG, PNG tai WebP, enintään 10 MB.</div></div>
    </div></div><div class="card-footer bg-white border-0 px-4 pb-4"><button class="btn btn-primary">Tallenna perustiedot</button></div>
</form>

<div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white"><h2 class="h5 mb-0">Tiivistelmä</h2></div><div class="card-body"><p class="mb-0"><?= nl2br(editValue($report['tiivistelma'] ?: 'Tiivistelmää ei ole vielä lisätty.')) ?></p></div></div>

<div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h4 mb-0">Raporttiosiot</h2></div>
<?php foreach ($rows as $row): ?>
    <div class="card border-0 shadow-sm mb-3" id="osio-<?= (int) $row['id'] ?>"><div class="card-body p-4">
        <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>">
            <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>">
            <label class="form-label">Osion otsikko</label><input class="form-control mb-3" name="rivi_otsikko" value="<?= editValue($row['otsikko']) ?>" maxlength="255" required>
            <label class="form-label">Tekstisisältö</label><textarea class="form-control mb-3" name="rivi_sisalto" rows="6"><?= editValue($row['sisalto']) ?></textarea>
            <button class="btn btn-primary btn-sm">Tallenna osio</button>
        </form>
        <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="mt-2" onsubmit="return confirm('Poistetaanko osio?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-row"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><button class="btn btn-outline-danger btn-sm">Poista osio</button></form>
        <?php foreach ($media as $item): ?><?php if ((int) $item['raporttirivi_id'] === (int) $row['id']): ?><div class="border-top mt-3 pt-2 small"><strong><?= editValue($item['tyyppi']) ?></strong>: <?= editValue($item['url']) ?>
            <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="row g-2 mt-1 align-items-center">
                <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-media"><input type="hidden" name="media_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>">
                <div class="col-md-8"><input class="form-control form-control-sm" name="kuvateksti" value="<?= editValue($item['kuvateksti']) ?>" placeholder="Kuvateksti"></div>
                <div class="col-md-auto"><button class="btn btn-outline-primary btn-sm">Tallenna kuvateksti</button></div>
            </form>
            <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="d-inline" onsubmit="return confirm('Poistetaanko media?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-media"><input type="hidden" name="media_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="return_anchor" value="osio-<?= (int) $row['id'] ?>"><button class="btn btn-link btn-sm text-danger">Poista</button></form>
        </div><?php endif; ?><?php endforeach; ?>
        <form method="post" enctype="multipart/form-data" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="row g-2 mt-2"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-media"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><div class="col-md-2"><select class="form-select form-select-sm" name="media_tyyppi" required><option value="kuva">Kuva</option><option value="videolinkki">Videolinkki</option></select></div><div class="col-md-4"><div class="file-drop-zone" data-file-drop><input type="file" class="form-control form-control-sm" name="media_files[]" multiple accept="image/jpeg,image/png,image/webp"><div class="file-drop-zone-hint">Raahaa kuvat tähän tai valitse tiedostot.</div></div><div class="form-text">Voit valita useita kuvia.</div></div><div class="col-md-3"><input class="form-control form-control-sm" name="media_url" placeholder="Videolinkin HTTPS-osoite"></div><div class="col-md-2"><input class="form-control form-control-sm" name="kuvateksti" placeholder="Kuvateksti"></div><div class="col-md-1"><button class="btn btn-outline-primary btn-sm w-100">+</button></div></form>
        <?php foreach ($tables as $table): ?><?php if ((int) $table['raporttirivi_id'] === (int) $row['id']): ?>
            <div class="border-top mt-4 pt-3" id="taulukko-<?= (int) $table['id'] ?>">
                <div class="d-flex justify-content-between align-items-center mb-2"><strong>Taulukko</strong>
                    <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" onsubmit="return confirm('Poistetaanko taulukko ja kaikki sen tiedot?');"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="delete-table"><input type="hidden" name="taulukko_id" value="<?= (int) $table['id'] ?>"><button class="btn btn-outline-danger btn-sm">Poista taulukko</button></form>
                </div>
                <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>">
                    <input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="update-table"><input type="hidden" name="taulukko_id" value="<?= (int) $table['id'] ?>">
                    <input class="form-control form-control-sm mb-2" name="taulukko_otsikko" value="<?= editValue($table['otsikko']) ?>" placeholder="Taulukon otsikko">
                    <div class="table-responsive"><table class="table table-bordered table-sm align-middle mb-2"><thead><tr><?php foreach ($table['sarakkeet'] as $column): ?><th><input class="form-control form-control-sm" name="sarake_nimi[<?= (int) $table['id'] ?>][<?= (int) $column['id'] ?>]" value="<?= editValue($column['nimi']) ?>"><div class="text-end mt-1"><button class="btn btn-link btn-sm text-danger p-0" type="submit" name="action" value="delete-table-column" onclick="this.form.sarake_id.value='<?= (int) $column['id'] ?>';">Poista</button></div></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($table['rivit'] as $tableRow): ?><tr><?php foreach ($table['sarakkeet'] as $column): ?><td><textarea class="form-control form-control-sm" name="solu[<?= (int) $table['id'] ?>][<?= (int) $tableRow['id'] ?>][<?= (int) $column['id'] ?>]" rows="2"><?= editValue($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '') ?></textarea></td><?php endforeach; ?><td class="border-0 p-1"><button class="btn btn-link btn-sm text-danger" type="submit" name="action" value="delete-table-row" onclick="this.form.taulukko_rivi_id.value='<?= (int) $tableRow['id'] ?>';">Poista rivi</button></td></tr><?php endforeach; ?></tbody></table></div>
                    <input type="hidden" name="sarake_id" value=""><input type="hidden" name="taulukko_rivi_id" value=""><button class="btn btn-primary btn-sm">Tallenna taulukko</button>
                </form>
                <div class="d-flex flex-wrap gap-2 mt-2"><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="d-flex gap-2"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-table-column"><input type="hidden" name="taulukko_id" value="<?= (int) $table['id'] ?>"><input class="form-control form-control-sm" name="sarake_nimi" placeholder="Uuden sarakkeen nimi" required><button class="btn btn-outline-secondary btn-sm">+ Sarake</button></form><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-table-row"><input type="hidden" name="taulukko_id" value="<?= (int) $table['id'] ?>"><button class="btn btn-outline-secondary btn-sm">+ Rivi</button></form></div>
            </div>
        <?php endif; ?><?php endforeach; ?>
        <form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>" class="d-flex gap-2 mt-3"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-table"><input type="hidden" name="rivi_id" value="<?= (int) $row['id'] ?>"><input class="form-control form-control-sm" name="taulukko_otsikko" placeholder="Uuden taulukon otsikko"><button class="btn btn-outline-primary btn-sm">+ Lisää taulukko</button></form>
    </div></div>
<?php endforeach; ?>

<span id="uusi-osio"></span>
<div class="card border-0 shadow-sm"><div class="card-header bg-white"><h2 class="h5 mb-0">Lisää uusi osio</h2></div><form method="post" action="muokkaa_raportti.php?id=<?= $raporttiId ?>"><input type="hidden" name="csrf_token" value="<?= editValue($csrfToken) ?>"><input type="hidden" name="action" value="add-row"><div class="card-body p-4"><input class="form-control mb-3" name="rivi_otsikko" placeholder="Osion otsikko" maxlength="255" required><textarea class="form-control" name="rivi_sisalto" rows="6" placeholder="Tekstisisältö"></textarea></div><div class="card-footer bg-white border-0 px-4 pb-4"><button class="btn btn-primary">+ Lisää osio</button></div></form></div>

<script>
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
</script>
<?php closeLayoutShell(); ?>
