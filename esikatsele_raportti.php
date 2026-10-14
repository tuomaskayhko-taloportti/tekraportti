<?php

require_once __DIR__ . '/funktiot.php';
require_once __DIR__ . '/vendor/fpdf/fpdf.php';
require_once __DIR__ . '/vendor/fpdi/src/autoload.php';

startApplicationSession();
if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$attachments = [];
$sourceMaterial = null;
$currentLaatijaId = $laatijatId;
$raporttiId = (int) ($_GET['id'] ?? 0);

try {
    $db = getDatabaseConnection();
    $query = $db->prepare(
        'SELECT r.*, a.nimi AS asiakas_nimi, a.yritys AS asiakas_yritys,
                a.osoite AS asiakas_osoite, a.postinro AS asiakas_postinro,
                a.postitmp AS asiakas_postitmp, a.ytunnus AS asiakas_ytunnus,
                a.sahkoposti AS asiakas_sahkoposti, a.puhelin AS asiakas_puhelin,
                p.nimi AS projekti_nimi,
                l.nimi AS laatija_nimi, l.titteli AS laatija_titteli,
                l.yritys AS laatija_yritys, l.ytunnus AS laatija_ytunnus,
                l.sahkoposti AS laatija_sahkoposti, l.puhelin AS laatija_puhelin,
                l.osoite AS laatija_osoite, l.postinro AS laatija_postinro,
                l.postitmp AS laatija_postitmp, l.logo_url AS laatija_logo_url
         FROM raportit r
         INNER JOIN asiakkaat a ON a.id = r.asiakas_id
         INNER JOIN laatijat l ON l.id = r.laatijat_id
         LEFT JOIN projektit p ON p.id = r.projekti_id
         LEFT JOIN raporttijaot j ON j.raportti_id = r.id
         WHERE r.id = ? AND (r.laatijat_id = ? OR j.laatijat_id = ? OR EXISTS (
             SELECT 1 FROM projektijaot pj WHERE pj.projekti_id = r.projekti_id AND pj.laatijat_id = ?
         )) LIMIT 1'
    );
    $query->bind_param('iiii', $raporttiId, $currentLaatijaId, $currentLaatijaId, $currentLaatijaId);
    $query->execute();
    $report = $query->get_result()->fetch_assoc();
    $query->close();
    if (!$report) {
        throw new RuntimeException('Raporttia ei löytynyt.');
    }
    $laatijatId = (int) $report['laatijat_id'];
    try {
        $query = $db->prepare('SELECT tiedosto, alkuperainen_nimi, otsikot_json FROM raporttiaineistot WHERE raportti_id = ? AND laatijat_id = ? LIMIT 1');
        $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute();
        $sourceMaterial = $query->get_result()->fetch_assoc() ?: null; $query->close();
    } catch (mysqli_sql_exception $sourceTableError) {
        if ($sourceTableError->getCode() !== 1146) {
            throw $sourceTableError;
        }
    }
    if ($sourceMaterial) {
        $decodedHeadings = json_decode((string) $sourceMaterial['otsikot_json'], true);
        $sourceMaterial['otsikot'] = is_array($decodedHeadings) ? $decodedHeadings : [];
    }
    $query = $db->prepare('SELECT id, tiedosto, alkuperainen_nimi FROM raporttiliitteet WHERE raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId); $query->execute();
    $attachments = $query->get_result()->fetch_all(MYSQLI_ASSOC); $query->close();

    $query = $db->prepare('SELECT id, otsikko, sisalto, sisaltaa_asiakastiedot, jarjestys FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId);
    $query->execute();
    $rows = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $query->close();

    $customerDetails = [];
    $query = $db->prepare('SELECT d.nimi, d.arvo FROM asiakas_lisatiedot d
        INNER JOIN asiakkaat a ON a.id = d.asiakas_id AND a.laatijat_id = d.laatijat_id
        WHERE d.asiakas_id = ? ORDER BY d.jarjestys, d.id');
    $query->bind_param('i', $report['asiakas_id']);
    $query->execute();
    $customerDetails = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $query->close();

    $mediaByRow = [];
    $query = $db->prepare('SELECT raporttirivi_id, tyyppi, url, kuvateksti, merkinnat_json FROM raporttimedia WHERE raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId);
    $query->execute();
    $mediaResult = $query->get_result();
    while ($item = $mediaResult->fetch_assoc()) {
        $mediaByRow[(int) $item['raporttirivi_id']][] = $item;
    }
    $query->close();

    $textsByRow = [];
    $query = $db->prepare('SELECT id, raporttirivi_id, sisalto, jarjestys FROM raporttitekstit WHERE raportti_id = ? AND laatijat_id = ? ORDER BY raporttirivi_id, jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId);
    $query->execute();
    $textResult = $query->get_result();
    while ($item = $textResult->fetch_assoc()) {
        $textsByRow[(int) $item['raporttirivi_id']][] = $item;
    }
    $query->close();

    $tablesByRow = [];
    $tables = [];
    $query = $db->prepare('SELECT id, raporttirivi_id, otsikko, reunat, leveys_tila, tasaus_pysty, tasaus_vaaka, jarjestys FROM raporttitaulukot WHERE raportti_id = ? AND laatijat_id = ? ORDER BY raporttirivi_id, jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId);
    $query->execute();
    $tableResult = $query->get_result();
        while ($item = $tableResult->fetch_assoc()) {
            $item['sarakkeet'] = [];
            $item['rivit'] = [];
            $item['solut'] = [];
            $item['yhteenvetorivit'] = [];
            $item['laskentarivit'] = [];
        $tables[(int) $item['id']] = $item;
    }
    $query->close();
    if ($tables) {
        $query = $db->prepare('SELECT id, taulukko_id, nimi, taustavari, fonttivari, jarjestys FROM raporttitaulukko_sarakkeet WHERE laatijat_id = ? ORDER BY taulukko_id, jarjestys, id');
        $query->bind_param('i', $laatijatId);
        $query->execute();
        $result = $query->get_result();
        while ($item = $result->fetch_assoc()) {
            if (isset($tables[(int) $item['taulukko_id']])) {
                $tables[(int) $item['taulukko_id']]['sarakkeet'][] = $item;
            }
        }
        $query->close();
        $query = $db->prepare('SELECT id, taulukko_id, nimittaja, tyyppi, jalkeen_rivi_id, jarjestys FROM raporttitaulukko_yhteenvetorivit WHERE laatijat_id = ? ORDER BY taulukko_id, jarjestys, id');
        $query->bind_param('i', $laatijatId);
        $query->execute();
        $result = $query->get_result();
        $summaryRows = [];
        while ($item = $result->fetch_assoc()) { $item['sarakkeet'] = []; $summaryRows[(int) $item['id']] = $item; }
        $query->close();
        if ($summaryRows) {
            $query = $db->prepare('SELECT yhteenvetorivi_id, sarake_id FROM raporttitaulukko_yhteenvetorivi_sarakkeet WHERE laatijat_id = ?');
            $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
            while ($item = $result->fetch_assoc()) { if (isset($summaryRows[(int) $item['yhteenvetorivi_id']])) { $summaryRows[(int) $item['yhteenvetorivi_id']]['sarakkeet'][] = (int) $item['sarake_id']; } }
            $query->close();
        }
        foreach ($summaryRows as $summary) { if (isset($tables[(int) $summary['taulukko_id']])) { $tables[(int) $summary['taulukko_id']]['yhteenvetorivit'][] = $summary; } }

        $query = $db->prepare('SELECT id, taulukko_id, yhteenvetorivi_id, nimittaja, yksikot, toiminto, lahde_sarake_id, jarjestys FROM raporttitaulukko_laskentarivit WHERE laatijat_id = ? ORDER BY yhteenvetorivi_id, jarjestys, id');
        $query->bind_param('i', $laatijatId); $query->execute(); $result = $query->get_result();
        while ($item = $result->fetch_assoc()) { if (isset($tables[(int) $item['taulukko_id']])) { $tables[(int) $item['taulukko_id']]['laskentarivit'][] = $item; } } $query->close();

        $query = $db->prepare('SELECT id, taulukko_id, jarjestys FROM raporttitaulukko_rivit WHERE laatijat_id = ? ORDER BY taulukko_id, jarjestys, id');
        $query->bind_param('i', $laatijatId);
        $query->execute();
        $result = $query->get_result();
        while ($item = $result->fetch_assoc()) {
            if (isset($tables[(int) $item['taulukko_id']])) {
                $tables[(int) $item['taulukko_id']]['rivit'][] = $item;
            }
        }
        $query->close();

        $query = $db->prepare('SELECT taulukko_id, rivi_id, sarake_id, sisalto FROM raporttitaulukko_solut WHERE laatijat_id = ? ORDER BY taulukko_id, rivi_id, sarake_id');
        $query->bind_param('i', $laatijatId);
        $query->execute();
        $result = $query->get_result();
        while ($item = $result->fetch_assoc()) {
            if (isset($tables[(int) $item['taulukko_id']])) {
                $tables[(int) $item['taulukko_id']]['solut'][(int) $item['rivi_id']][(int) $item['sarake_id']] = (string) $item['sisalto'];
            }
        }
        $query->close();
    }
    foreach ($tables as $table) {
        $tablesByRow[(int) $table['raporttirivi_id']][] = $table;
    }
    $contentBlocksByRow = [];
    foreach ($tables as $table) {
        $contentBlocksByRow[(int) $table['raporttirivi_id']][] = [
            'type' => 'table',
            'id' => (int) $table['id'],
            'jarjestys' => (int) ($table['jarjestys'] ?? 0),
            'data' => $table,
        ];
    }
    foreach ($textsByRow as $rowId => $texts) {
        foreach ($texts as $text) {
            $contentBlocksByRow[(int) $rowId][] = [
                'type' => 'text',
                'id' => (int) $text['id'],
                'jarjestys' => (int) ($text['jarjestys'] ?? 0),
                'data' => $text,
            ];
        }
    }
    foreach ($contentBlocksByRow as &$blocks) {
        usort($blocks, static function (array $left, array $right): int {
            return [$left['jarjestys'], $left['id']] <=> [$right['jarjestys'], $right['id']];
        });
    }
    unset($blocks);
} catch (Throwable $error) {
    http_response_code(404);
    echo htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

function pdfText(string $value): string
{
    $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    return $converted === false ? '?' : $converted;
}

function pdfTableHorizontalAlignment(string $alignment): string
{
    return match ($alignment) {
        'keski' => 'C',
        'oikea' => 'R',
        default => 'L',
    };
}

function pdfTableCellTextY(float $cellY, float $cellHeight, int $lineCount, string $alignment): float
{
    $textHeight = max(1, $lineCount) * 5;
    return $cellY + match ($alignment) {
        'ylos' => 1,
        'keskelle' => max(1, ($cellHeight - $textHeight) / 2),
        default => max(1, $cellHeight - $textHeight - 1),
    };
}

function pdfTableNumber(string $value): ?array
{
    $value = trim(str_replace("\xc2\xa0", ' ', $value));
    if ($value === '') { return null; }
    $currency = str_contains($value, '$') ? '$' : (str_contains($value, '€') ? '€' : '');
    $value = str_replace(['$', '€'], '', $value);
    $value = preg_replace('/\s+/', '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }
    return preg_match('/^-?\d+(?:\.\d+)?$/', $value) ? ['value' => (float) $value, 'currency' => $currency] : null;
}

function pdfTableNumberText(float $value, string $currency = ''): string
{
    $text = number_format($value, 2, ',', ' ');
    return $currency === '$' ? '$' . $text : ($currency === '€' ? $text . ' €' : $text);
}

function pdfTableNumberRun(string $value): ?array
{
    $value = trim($value);
    $style = '';
    if (preg_match('/^(\*\*|\*)(.*)\1$/us', $value, $match)) {
        $style = $match[1] === '**' ? 'B' : 'I';
        $value = trim($match[2]);
    }
    $number = pdfTableNumber($value);
    return $number === null ? null : ['value' => $number['value'], 'currency' => $number['currency'], 'style' => $style, 'text' => $value];
}

function pdfTableColumnIsNumeric(array $table, array $displayRows, int $columnId): bool
{
    $hasRows = false;
    foreach ($displayRows as $displayRow) {
        if ($displayRow['summary'] || $displayRow['calculation']) {
            continue;
        }
        $hasRows = true;
        $value = (string) ($table['solut'][(int) $displayRow['row']['id']][$columnId] ?? '');
        if (pdfTableNumberRun($value) === null) {
            return false;
        }
    }
    return $hasRows;
}

function pdfHexColor(string $value, array $fallback = [255, 255, 255]): array
{
    if (!preg_match('/^#([0-9a-f]{6})$/i', trim($value), $match)) {
        return $fallback;
    }
    return [hexdec(substr($match[1], 0, 2)), hexdec(substr($match[1], 2, 2)), hexdec(substr($match[1], 4, 2))];
}

function pdfTableRowsWithSummaries(array $table): array
{
    $result = [];
    $summaries = $table['yhteenvetorivit'] ?? [];
    $summariesByAfter = [];
    foreach ($summaries as $summary) { $summariesByAfter[(int) ($summary['jalkeen_rivi_id'] ?? 0)][] = $summary; }
    foreach ($table['rivit'] as $tableRow) {
        $result[] = ['row' => $tableRow, 'summary' => false];
        foreach ($summariesByAfter[(int) $tableRow['id']] ?? [] as $summary) {
            $result[] = ['row' => ['id' => -((int) $summary['id']), 'summary' => $summary], 'summary' => true];
            foreach ($table['laskentarivit'] ?? [] as $calculation) {
                if ((int) $calculation['yhteenvetorivi_id'] === (int) $summary['id']) {
                    $result[] = ['row' => ['id' => -1000000000 - (int) $calculation['id'], 'calculation' => $calculation], 'calculation' => true, 'summary' => false];
                }
            }
        }
    }
    foreach ($summariesByAfter[0] ?? [] as $summary) {
        $result[] = ['row' => ['id' => -((int) $summary['id']), 'summary' => $summary], 'summary' => true];
        foreach ($table['laskentarivit'] ?? [] as $calculation) {
            if ((int) $calculation['yhteenvetorivi_id'] === (int) $summary['id']) {
                $result[] = ['row' => ['id' => -1000000000 - (int) $calculation['id'], 'calculation' => $calculation], 'calculation' => true, 'summary' => false];
            }
        }
    }
    $running = [];
    $summaryCellsById = [];
    foreach ($result as &$entry) {
        if (!empty($entry['calculation'])) {
            $calculation = $entry['row']['calculation'];
            $source = pdfTableNumber((string) (($summaryCellsById[(int) $calculation['yhteenvetorivi_id']][(int) $calculation['lahde_sarake_id']]) ?? ''));
            $units = (float) $calculation['yksikot'];
            $value = $source['value'] ?? 0.0;
            if ((string) $calculation['toiminto'] === 'jaa') {
                $value = $units > 0 ? $value / $units : 0.0;
            } elseif ((string) $calculation['toiminto'] === 'prosentti') {
                $value = $value * $units / 100;
            } else {
                $value *= $units;
            }
            $calculationCells = [];
            foreach ($table['sarakkeet'] as $columnIndex => $column) {
                if ($columnIndex === 0) {
                    $unitText = rtrim(rtrim(number_format($units, 2, ',', ' '), '0'), ',');
                    $calculationCells[(int) $column['id']] = (string) $calculation['nimittaja'] . ' (yksiköt: ' . $unitText . ')';
                } elseif ($columnIndex === count($table['sarakkeet']) - 1) {
                    $calculationCells[(int) $column['id']] = pdfTableNumberText($value, (string) ($source['currency'] ?? ''));
                } else {
                    $calculationCells[(int) $column['id']] = '';
                }
            }
            $entry['cells'] = $calculationCells;
            continue;
        }
        if (!$entry['summary']) {
            foreach ($table['sarakkeet'] as $column) {
                $number = pdfTableNumberRun((string) ($table['solut'][(int) $entry['row']['id']][(int) $column['id']] ?? ''));
                if ($number !== null) {
                    $columnId = (int) $column['id'];
                    $running[$columnId]['value'] = ($running[$columnId]['value'] ?? 0) + $number['value'];
                    if ($number['currency'] !== '') { $running[$columnId]['currency'] = $number['currency']; }
                }
            }
            continue;
        }
        $summary = $entry['row']['summary'];
        $cells = [];
        foreach ($table['sarakkeet'] as $columnIndex => $column) {
            $columnId = (int) $column['id'];
            $summaryName = trim((string) ($summary['nimittaja'] ?? '')) ?: ((string) $summary['tyyppi'] === 'valisummaa' ? 'Välisummaa' : 'Yhteensä');
            $cells[$columnId] = in_array($columnId, $summary['sarakkeet'], true) ? pdfTableNumberText((float) ($running[$columnId]['value'] ?? 0), (string) ($running[$columnId]['currency'] ?? '')) : ($columnIndex === 0 ? $summaryName : '');
        }
        $entry['cells'] = $cells;
        $summaryCellsById[(int) $summary['id']] = $cells;
        if ((string) $summary['tyyppi'] === 'valisummaa') { $running = []; }
    }
    unset($entry);
    return $result;
}

function pdfWriteMarkdown(FPDF $pdf, string $text, float $lineHeight = 6): void
{
    $parts = pdfMarkdownRuns($text);
    foreach ($parts as $part) {
        $pdf->SetFont('Helvetica', $part['style'], 11);
        $pdf->Write($lineHeight, pdfText($part['text']));
    }
    $pdf->SetFont('Helvetica', '', 11);
}

/** Pilkkoo markdown-tekstin PDF:ää varten tekstiksi ja fonttityyleiksi. */
function pdfMarkdownRuns(string $text): array
{
    $parts = preg_split('/(\*\*[^*\r\n]+\*\*|\*[^*\r\n]+\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
    $runs = [];
    foreach ($parts as $part) {
        $style = '';
        if (str_starts_with($part, '**') && str_ends_with($part, '**')) {
            $style = 'B';
            $part = substr($part, 2, -2);
        } elseif (str_starts_with($part, '*') && str_ends_with($part, '*')) {
            $style = 'I';
            $part = substr($part, 1, -1);
        }
        $runs[] = ['text' => $part, 'style' => $style];
    }
    return $runs;
}

/**
 * Tulostaa tekstin ja muuttaa rivien alussa olevat luettelomerkit PDF-listaksi.
 * Viivamerkityt rivit saavat neliömerkin, tähdellä merkityt rivit suljetun
 * ympyrän, o-merkillä merkityt rivit avoimen ympyrän ja numeroidut rivit
 * säilyttävät alkuperäisen numeronsa.
 */
function pdfTextWithLists(FPDF $pdf, string $text, int $sectionNumber, array &$headingCounters): void
{
    $listIndent = 7;
    $listStart = 20 + $listIndent;
    $contentStart = $listStart + $listIndent;
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [''];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(#{2,})\s+(.+)$/u', $line, $heading)) {
            $headingLevel = strlen($heading[1]) - 1;
            $headingCounters[$headingLevel] = ($headingCounters[$headingLevel] ?? 0) + 1;
            foreach ($headingCounters as $counterLevel => $value) {
                if ($counterLevel > $headingLevel) {
                    $headingCounters[$counterLevel] = 0;
                }
            }
            $headingPath = [];
            for ($counterLevel = 1; $counterLevel <= $headingLevel; $counterLevel++) {
                $headingPath[] = (string) ($headingCounters[$counterLevel] ?? 0);
            }
            // Raporttiosion alaotsikot alkavat samasta reunasta kuin muu sisältö.
            // Sisällysluettelon hierarkkinen sisennys rakennetaan erikseen alla.
            $headingIndent = 0;
            $pdf->SetX(20);
            $pdf->SetFont('Helvetica', 'B', max(11, 15 - min(4, $headingLevel)));
            $pdf->MultiCell($pdf->GetPageWidth() - 40 - $headingIndent, 7, pdfText($sectionNumber . '.' . implode('.', $headingPath) . ' ' . $heading[2]), 0, 'L');
            $pdf->Ln(1);
            $pdf->SetFont('Helvetica', '', 11);
            continue;
        }
        if (preg_match('/^\s*(\d+[.)]|[-*–—−]|o)(?:\s+|$)(.*)$/u', $line, $matches)) {
            $content = (string) $matches[2];
            $listWidth = $pdf->GetPageWidth() - 40 - ($listIndent * 2);
            $pdf->SetX($listStart);
            if ($matches[1] === 'o') {
                $pdf->drawOpenListMarker();
            } elseif ($matches[1] === '*') {
                $pdf->drawClosedListMarker();
            } elseif ($matches[1] === '-') {
                $pdf->Cell(5, 6, pdfText('–'), 0, 0, 'C');
            } elseif (in_array($matches[1], ['–', '—', '−'], true)) {
                $pdf->drawFilledListMarker();
            } else {
                $pdf->Cell(5, 6, pdfText($matches[1]), 0, 0, 'C');
            }
            $pdf->SetX($contentStart);
            pdfWriteMarkdown($pdf, $content);
            $pdf->Ln(6);
            continue;
        }

        pdfWriteMarkdown($pdf, $line);
        $pdf->Ln(6);
    }
}

/** Palauttaa raporttiosion tekstissä olevat alaotsikot tasoineen sisällysluetteloa varten. */
function reportSubheadings(string $text): array
{
    $headings = [];
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $counters = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(#{2,})\s+(.+)$/u', $line, $matches)) {
            $level = strlen($matches[1]) - 1;
            $counters[$level] = ($counters[$level] ?? 0) + 1;
            foreach ($counters as $counterLevel => $value) {
                if ($counterLevel > $level) {
                    $counters[$counterLevel] = 0;
                }
            }
            $number = [];
            for ($counterLevel = 1; $counterLevel <= $level; $counterLevel++) {
                $number[] = (string) ($counters[$counterLevel] ?? 0);
            }
            $headingTitle = preg_replace('/\*\*([^*\r\n]+)\*\*|(?<!\*)\*([^*\r\n]+)\*(?!\*)/u', '$1$2', $matches[2]);
            $headings[] = [
                'title' => trim($headingTitle === null ? $matches[2] : $headingTitle),
                'level' => $level,
                'number' => implode('.', $number),
            ];
        }
    }
    return $headings;
}

/** Laskee tilan, joka osion alusta tarvitaan otsikolle ja tekstin alulle. */
function pdfSectionStartHeight(FPDF $pdf, int $sectionNumber, string $sectionTitle, string $content): float
{
    $contentWidth = $pdf->GetPageWidth() - 40;
    $pdf->SetFont('Helvetica', 'B', 17);
    $titleLines = pdfTextChunksByWords($pdf, $sectionNumber . '. ' . $sectionTitle, $contentWidth);
    $height = count($titleLines) * 10 + 5;

    foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
        if (!preg_match('/^\s*(#{2,})\s+(.+)$/u', $line, $heading)) {
            continue;
        }
        $headingLevel = strlen($heading[1]) - 1;
        $headingNumber = $sectionNumber . '.' . implode('.', array_fill(0, $headingLevel - 1, '0'));
        if ($headingLevel > 1) {
            $headingNumber .= '.';
        }
        $headingNumber .= '1 ';
        $pdf->SetFont('Helvetica', 'B', max(11, 15 - min(4, $headingLevel)));
        $headingLines = pdfTextChunksByWords($pdf, $headingNumber . $heading[2], $contentWidth);
        return $height + count($headingLines) * 7 + 1 + 6;
    }

    // Jos alaotsikkoa ei ole, pidä osion otsikon yhteydessä vähintään yksi
    // tekstirivi tai vastaava määrä tilaa seuraavalle sisältöelementille.
    return $height + 6;
}

function pdfDate(string $value): string
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date ? $date->format('d.m.Y') : $value;
}

function pdfFooterContact(array $report): string
{
    $parts = [];
    foreach (['laatija_yritys', 'laatija_ytunnus', 'laatija_puhelin', 'laatija_sahkoposti', 'laatija_osoite'] as $field) {
        $value = trim((string) ($report[$field] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $postalCode = trim((string) ($report['laatija_postinro'] ?? ''));
    $postalTown = trim((string) ($report['laatija_postitmp'] ?? ''));
    $postal = trim($postalCode . ' ' . $postalTown);
    if ($postal !== '') {
        $parts[] = $postal;
    }

    return implode(' | ', $parts);
}

function pdfCustomerInfoRows(array $report, array $customerDetails): array
{
    $rows = [];
    $fields = [
        'Yhteyshenkilö' => 'asiakas_nimi',
        'Yritys' => 'asiakas_yritys',
        'Y-tunnus' => 'asiakas_ytunnus',
        'Sähköposti' => 'asiakas_sahkoposti',
        'Puhelin' => 'asiakas_puhelin',
        'Osoite' => 'asiakas_osoite',
    ];
    foreach ($fields as $label => $field) {
        $value = trim((string) ($report[$field] ?? ''));
        if ($value !== '') {
            $rows[] = [$label, $value];
        }
    }
    $postal = trim((string) ($report['asiakas_postinro'] ?? '') . ' ' . (string) ($report['asiakas_postitmp'] ?? ''));
    if ($postal !== '') {
        $rows[] = ['Postinumero ja -toimipaikka', $postal];
    }
    foreach ($customerDetails as $detail) {
        $name = trim((string) ($detail['nimi'] ?? ''));
        $value = trim((string) ($detail['arvo'] ?? ''));
        if ($name !== '' && $value !== '') {
            $rows[] = [$name, $value];
        }
    }
    return $rows;
}

function pdfTableLineCount(FPDF $pdf, string $text, float $width, string $baseStyle = ''): int
{
    $availableWidth = max(1, $width - 2);
    $lines = 0;
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [''] as $paragraph) {
        $lineWidth = 0.0;
        $paragraphLines = 1;
        foreach (pdfMarkdownRuns($paragraph) as $run) {
            $pdf->SetFont('Helvetica', $baseStyle . $run['style'], 9);
            $words = preg_split('/(\s+)/u', $run['text'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                if (preg_match('/^\s+$/u', $word)) {
                    if ($lineWidth > 0) {
                        $lineWidth += $pdf->GetStringWidth(pdfText($word));
                    }
                    continue;
                }
                foreach (pdfTextChunks($pdf, $word, $availableWidth) as $chunk) {
                    $chunkWidth = $pdf->GetStringWidth(pdfText($chunk));
                    if ($lineWidth > 0 && $lineWidth + $chunkWidth > $availableWidth) {
                        $paragraphLines++;
                        $lineWidth = $chunkWidth;
                    } else {
                        $lineWidth += $chunkWidth;
                    }
                }
            }
        }
        $lines += $paragraphLines;
    }
    $pdf->SetFont('Helvetica', $baseStyle, 9);
    return max(1, $lines);
}

/** Pilkkoo liian pitkän, välilyönnittömän tekstijakson taulukon solun leveyteen. */
function pdfTextChunks(FPDF $pdf, string $text, float $width): array
{
    $chunks = [];
    $chunk = '';
    $chunkWidth = 0.0;
    $availableWidth = max(1, $width);
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
        $characterWidth = $pdf->GetStringWidth(pdfText($character));
        if ($chunk !== '' && $chunkWidth + $characterWidth > $availableWidth) {
            $chunks[] = $chunk;
            $chunk = '';
            $chunkWidth = 0.0;
        }
        $chunk .= $character;
        $chunkWidth += $characterWidth;
    }
    if ($chunk !== '') {
        $chunks[] = $chunk;
    }
    return $chunks ?: [''];
}

/** Jakaa sisällysluettelon otsikon sanoihin ja tarvittaessa myös pitkät sanat riveille. */
function pdfTextChunksByWords(FPDF $pdf, string $text, float $width): array
{
    $lines = [];
    $line = '';
    foreach (preg_split('/\s+/u', trim($text)) ?: [''] as $word) {
        if ($word === '') {
            continue;
        }
        $wordChunks = pdfTextChunks($pdf, $word, $width);
        foreach ($wordChunks as $chunkIndex => $wordChunk) {
            $separator = ($chunkIndex === 0 && $line !== '') ? ' ' : '';
            $candidate = $line . $separator . $wordChunk;
            if ($line !== '' && $pdf->GetStringWidth($candidate) > $width) {
                $lines[] = $line;
                $line = $wordChunk;
            } else {
                $line = $candidate;
            }
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines ?: [''];
}

/** Tulostaa taulukon solun markdown-muotoilulla ja sovittaa tekstin solun leveyteen. */
function pdfWriteMarkdownCell(FPDF $pdf, string $text, float $width, float $lineHeight = 5, string $baseStyle = '', string $alignment = 'L'): void
{
    $availableWidth = max(1, $width);
    $startX = $pdf->GetX();
    $lineWidth = 0.0;
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [''] as $line) {
        $lineStartX = $startX;
        if ($alignment === 'R') {
            $fullLineWidth = 0.0;
            foreach (pdfMarkdownRuns($line) as $run) {
                $pdf->SetFont('Helvetica', $baseStyle . $run['style'], 9);
                $fullLineWidth += $pdf->GetStringWidth(pdfText($run['text']));
            }
            $lineStartX += max(0, $availableWidth - $fullLineWidth);
        }
        $pdf->SetX($lineStartX);
        foreach (pdfMarkdownRuns($line) as $run) {
            $pdf->SetFont('Helvetica', $baseStyle . $run['style'], 9);
            $tokens = preg_split('/(\s+)/u', $run['text'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (preg_match('/^\s+$/u', $token) && $lineWidth === 0.0) {
                    continue;
                }
                $chunks = preg_match('/^\s+$/u', $token)
                    ? [$token]
                    : pdfTextChunks($pdf, $token, $availableWidth);
                foreach ($chunks as $chunk) {
                    $tokenWidth = $pdf->GetStringWidth(pdfText($chunk));
                    if ($lineWidth > 0 && $lineWidth + $tokenWidth > $availableWidth) {
                        $pdf->Ln($lineHeight);
                        $pdf->SetX($startX);
                        $lineWidth = 0.0;
                        if (preg_match('/^\s+$/u', $chunk)) {
                            continue;
                        }
                    }
                    $pdf->SetFont('Helvetica', $baseStyle . $run['style'], 9);
                    $pdf->Cell($tokenWidth, $lineHeight, pdfText($chunk), 0, 0, $alignment);
                    $lineWidth += $tokenWidth;
                }
            }
        }
        $pdf->Ln($lineHeight);
        $pdf->SetX($startX);
        $lineWidth = 0.0;
    }
    $pdf->SetFont('Helvetica', $baseStyle, 9);
}

function chartNumber(string $value): ?float
{
    $value = trim(str_replace(["\xc2\xa0", ' '], '', $value));
    if ($value === '') {
        return null;
    }
    $number = preg_replace('/[^0-9,.\-]/', '', $value);
    if ($number === '' || $number === '-') {
        return null;
    }
    if (str_contains($number, ',')) {
        $number = str_replace('.', '', $number);
        $number = str_replace(',', '.', $number);
    } else {
        $number = str_replace(',', '', $number);
    }
    return is_numeric($number) ? (float) $number : null;
}

function chartFontPath(bool $bold = false, bool $italic = false): ?string
{
    static $paths = [
        'regular' => [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/Library/Fonts/Arial.ttf',
        ],
        'bold' => [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/Library/Fonts/Arial Bold.ttf',
        ],
        'italic' => [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Oblique.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Italic.ttf',
            '/Library/Fonts/Arial Italic.ttf',
        ],
    ];
    foreach ($paths[$italic ? 'italic' : ($bold ? 'bold' : 'regular')] as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function chartTextStyledCentered($image, int $size, float $centerX, int $y, string $text, int $color, string $style = ''): void
{
    $scale = (int) ($GLOBALS['chartRenderScale'] ?? 1);
    $bold = $style === 'B';
    $italic = $style === 'I';
    $font = chartFontPath($bold, $italic);
    $textWidth = chartTextWidth($size, $text, $bold);
    $x = max(0, (int) round($centerX - $textWidth / 2));
    if ($font !== null && function_exists('imagettftext')) {
        imagettftext($image, $size * $scale, 0, $x * $scale, ($y + $size) * $scale, $color, $font, $text);
        return;
    }
    imagestring($image, 5, $x * $scale, $y * $scale, pdfText($text), $color);
}

function chartMarkdownLabelParts(string $label): array
{
    $label = trim($label);
    if (preg_match('/^(\\*\\*|\\*)(.*)\\1$/us', $label, $match)) {
        return ['text' => trim($match[2]), 'style' => $match[1] === '**' ? 'B' : 'I'];
    }
    return ['text' => $label, 'style' => ''];
}

function chartText($image, int $size, int $x, int $y, string $text, int $color, bool $bold = false, int $angle = 0): void
{
    $scale = (int) ($GLOBALS['chartRenderScale'] ?? 1);
    $font = chartFontPath($bold);
    if ($font !== null && function_exists('imagettftext')) {
        imagettftext($image, $size * $scale, $angle, $x * $scale, ($y + $size) * $scale, $color, $font, $text);
        return;
    }
    imagestring($image, 5, $x * $scale, $y * $scale, pdfText($text), $color);
}

function chartLabelLines(string $label, int $maxCharacters = 14): array
{
    $words = preg_split('/\s+/', trim($label)) ?: [''];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        if (function_exists('mb_strlen') && mb_strlen($word, 'UTF-8') > $maxCharacters) {
            $word = mb_substr($word, 0, max(1, $maxCharacters - 1), 'UTF-8') . '…';
        } elseif (strlen($word) > $maxCharacters) {
            $word = substr($word, 0, max(1, $maxCharacters - 1)) . '…';
        }
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        $candidateLength = function_exists('mb_strlen')
            ? mb_strlen($candidate, 'UTF-8')
            : strlen($candidate);
        if ($line !== '' && $candidateLength > $maxCharacters) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }
    }
    if ($line !== '' || !$lines) {
        $lines[] = $line;
    }
    return $lines;
}

function chartTextWidth(int $size, string $text, bool $bold = false): int
{
    $font = chartFontPath($bold);
    if ($font !== null && function_exists('imagettfbbox')) {
        $box = imagettfbbox($size, 0, $font, $text);
        return (int) abs($box[2] - $box[0]);
    }
    return strlen($text) * $size;
}

function chartTextCentered($image, int $size, float $centerX, int $y, string $text, int $color, bool $bold = false): void
{
    $x = (int) round($centerX - chartTextWidth($size, $text, $bold) / 2);
    chartText($image, $size, max(0, $x), $y, $text, $color, $bold);
}

function chartValueLabelPosition(array &$placedLabels, float $centerX, int $preferredY, string $text, int $size = 15): int
{
    $textWidth = chartTextWidth($size, $text, true);
    $y = max(35, $preferredY);
    do {
        $overlaps = false;
        foreach ($placedLabels as $placed) {
            $horizontalOverlap = abs($centerX - $placed['centerX']) < (($textWidth + $placed['width']) / 2 + 4);
            $verticalOverlap = abs($y - $placed['y']) < ($size + 5);
            if ($horizontalOverlap && $verticalOverlap) {
                $y -= $size + 5;
                $overlaps = true;
                break;
            }
        }
    } while ($overlaps && $y >= 35);
    $placedLabels[] = ['centerX' => $centerX, 'y' => $y, 'width' => $textWidth];
    return $y;
}

function chartValueLabel(float $value, string $currency = ''): string
{
    $absolute = abs($value);
    if ($absolute >= 1000000) {
        $number = number_format($value / 1000000, 1, ',', ' ');
        return $number . ' M' . $currency;
    }
    if ($absolute >= 1000) {
        $number = number_format($value / 1000, 1, ',', ' ');
        return $number . ' t' . $currency;
    }
    $number = number_format($value, 2, ',', ' ');
    $number = rtrim(rtrim($number, '0'), ',');
    return $number . ($currency !== '' ? ' ' . $currency : '');
}

function chartBarValueLabel(float $value): string
{
    return str_replace(' t', ' k', chartValueLabel($value));
}

function createTableChart(array $table, string $chartType, array &$temporaryImages, array $selectedColumnIds = []): ?string
{
    if (!in_array($chartType, ['pie', 'bar', 'bar_horizontal', 'line', 'combo'], true) || count($table['sarakkeet']) < 2 || !$table['rivit']) {
        return null;
    }

    $series = [];
    foreach (array_slice($table['sarakkeet'], 1) as $column) {
        if ($selectedColumnIds && !in_array((int) $column['id'], $selectedColumnIds, true)) {
            continue;
        }
        $values = [];
        $currency = '';
        foreach ($table['rivit'] as $tableRow) {
            $cell = (string) ($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '');
            if (str_contains($cell, '€')) { $currency = '€'; }
            if (str_contains($cell, '$')) { $currency = '$'; }
            $values[] = chartNumber($cell);
        }
        if (count(array_filter($values, static fn ($value): bool => $value !== null)) > 0) {
            $series[] = ['name' => (string) $column['nimi'], 'values' => $values, 'currency' => $currency];
        }
    }
    if (!$series) {
        return null;
    }
    // Piirrä kaavio kolminkertaisella resoluutiolla. PDF:ään kuva asetetaan
    // edelleen samaan fyysiseen kokoon, joten tämä parantaa rasterireunojen
    // ja tekstin laatua ilman, että raportin taitto muuttuu.
    $scale = 3;
    $GLOBALS['chartRenderScale'] = $scale;
    $width = 1200 * $scale;
    $height = 650 * $scale;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $dark = imagecolorallocate($image, 35, 45, 60);
    imagefill($image, 0, 0, $white);
    $drawLine = static function ($image, int $x1, int $y1, int $x2, int $y2, int $color) use ($scale): void {
        imageline($image, $x1 * $scale, $y1 * $scale, $x2 * $scale, $y2 * $scale, $color);
    };
    $drawRectangle = static function ($image, int $x1, int $y1, int $x2, int $y2, int $color) use ($scale): void {
        imagefilledrectangle($image, $x1 * $scale, $y1 * $scale, $x2 * $scale, $y2 * $scale, $color);
    };
    $drawEllipse = static function ($image, int $x, int $y, int $width, int $height, int $color) use ($scale): void {
        imagefilledellipse($image, $x * $scale, $y * $scale, $width * $scale, $height * $scale, $color);
    };
    $drawArc = static function ($image, int $x, int $y, int $width, int $height, float $start, float $end, int $color) use ($scale): void {
        imagefilledarc($image, $x * $scale, $y * $scale, $width * $scale, $height * $scale, $start, $end, $color, IMG_ARC_PIE);
    };
    $tableTitle = trim((string) ($table['otsikko'] ?? ''));
    $diagramTitle = $tableTitle;
    $diagramTitleSize = $chartType === 'line' ? 20 : 24;
    $currencies = array_values(array_filter(array_unique(array_map(
        static fn (array $item): string => (string) ($item['currency'] ?? ''),
        $series
    ))));
    if ($currencies) {
        $currencySuffix = '(' . implode(' / ', $currencies) . ')';
        $diagramTitle = $diagramTitle !== '' ? $diagramTitle . ' ' . $currencySuffix : $currencySuffix;
    }
    $palette = [
        imagecolorallocate($image, 31, 111, 235),
        imagecolorallocate($image, 241, 139, 33),
        imagecolorallocate($image, 42, 160, 104),
        imagecolorallocate($image, 188, 75, 155),
        imagecolorallocate($image, 110, 95, 205),
    ];
    $labels = [];
    foreach ($table['rivit'] as $tableRow) {
        $firstColumn = $table['sarakkeet'][0];
        $label = trim((string) ($table['solut'][(int) $tableRow['id']][(int) $firstColumn['id']] ?? ''));
        $labels[] = $label !== '' ? $label : (string) (count($labels) + 1);
    }

    if ($chartType === 'pie') {
        // Piirakan sektorit ovat rivejä. Nimittäjä/selite tulee aina taulukon
        // ensimmäisestä sarakkeesta ja valittujen numerossarakkeiden arvot
        // summataan rivikohtaisesti.
        $pieLabels = [];
        $values = [];
        foreach ($table['rivit'] as $rowIndex => $tableRow) {
            $value = 0.0;
            foreach ($series as $item) {
                $value += max(0, (float) ($item['values'][$rowIndex] ?? 0));
            }
            if ($value > 0) {
                $pieLabels[] = $labels[$rowIndex] ?? (string) ($rowIndex + 1);
                $values[] = $value;
            }
        }
        $total = array_sum($values);
        if ($total <= 0 || !$values) {
            imagedestroy($image);
            return null;
        }
        $centerX = 390;
        $centerY = 315;
        $diameter = 480;
        $start = 0.0;
        $piePercentages = [];
        foreach ($values as $index => $value) {
            $angle = $value / $total * 360;
            // GD:n kulmapyöristys voi jättää viereisten sektoreiden väliin
            // yhden pikselin valkoisen raon. Piirretään sektorit hieman
            // päällekkäin, jotta saumakohta täyttyy myös suurennettuna.
            $drawArc($image, $centerX, $centerY, $diameter, $diameter, $start, $start + $angle + 0.5, $palette[$index % count($palette)]);
            $piePercentages[] = [
                'angle' => $start + $angle / 2,
                'percent' => $value / $total * 100,
                'text' => number_format($value / $total * 100, 1, ',', '') . '%',
            ];
            $start += $angle;
        }
        $labelRadius = $diameter * 0.30;
        $externalLabels = ['left' => [], 'right' => []];
        foreach ($piePercentages as $percentage) {
            $angleRadians = deg2rad($percentage['angle']);
            if ($percentage['percent'] < 8) {
                $side = cos($angleRadians) >= 0 ? 'right' : 'left';
                $externalLabels[$side][] = [
                    'angle' => $percentage['angle'],
                    'text' => $percentage['text'],
                    'y' => $centerY + sin($angleRadians) * ($diameter * 0.52),
                ];
                continue;
            }
            $labelX = $centerX + cos($angleRadians) * $labelRadius;
            $labelY = $centerY + sin($angleRadians) * $labelRadius - 9;
            // Tumma varjo pitää valkoisen prosenttitekstin luettavana myös
            // vaaleassa sektorissa.
            chartTextCentered($image, 18, $labelX + 1, (int) $labelY + 1, $percentage['text'], $dark, true);
            chartTextCentered($image, 18, $labelX, (int) $labelY, $percentage['text'], $white, true);
        }
        foreach ($externalLabels as $side => $labelsOnSide) {
            usort($labelsOnSide, static fn (array $first, array $second): int => $first['y'] <=> $second['y']);
            $previousY = 45;
            foreach ($labelsOnSide as $label) {
                $labelY = max(45, min(555, (float) $label['y']));
                if ($labelY < $previousY + 24) { $labelY = min(555, $previousY + 24); }
                $previousY = $labelY;
                $angleRadians = deg2rad($label['angle']);
                $lineX = $centerX + cos($angleRadians) * ($diameter * 0.50);
                $lineY = $centerY + sin($angleRadians) * ($diameter * 0.50);
                $textX = $side === 'right' ? 655 : 25;
                $lineEndX = $side === 'right' ? $textX - 6 : $textX + 42;
                $drawLine($image, (int) $lineX, (int) $lineY, $lineEndX, (int) ($labelY + 7), $dark);
                chartText($image, 18, $textX, (int) $labelY, $label['text'], $dark, true);
            }
        }
        if ($diagramTitle !== '') {
            chartText($image, $diagramTitleSize, 10, 15, $diagramTitle, $dark, true);
        }
        $legendY = 100;
        foreach ($pieLabels as $index => $label) {
            $drawRectangle($image, 730, $legendY, 750, $legendY + 20, $palette[$index % count($palette)]);
            chartText($image, 20, 765, $legendY - 1, $label, $dark);
            $legendY += 32;
        }
    } elseif ($chartType === 'bar_horizontal') {
        $left = 300;
        $right = 1130;
        $top = 75;
        $bottom = 545;
        $maxValue = 0.0;
        foreach ($series as $item) {
            foreach ($item['values'] as $value) { $maxValue = max($maxValue, (float) ($value ?? 0)); }
        }
        $maxValue = $maxValue > 0 ? $maxValue * 1.1 : 1;
        for ($tick = 0; $tick <= 5; $tick++) {
            $x = $left + ($right - $left) * $tick / 5;
            $value = $maxValue * $tick / 5;
            $grid = imagecolorallocate($image, 220, 225, 232);
            $drawLine($image, (int) $x, $top, (int) $x, $bottom, $grid);
            chartTextCentered($image, 16, $x, $bottom + 8, number_format($value, 1, ',', ' '), $dark);
        }
        $drawLine($image, $left, $top, $left, $bottom, $dark);
        $groupHeight = ($bottom - $top) / max(1, count($labels));
        $barHeight = max(8, min(34, ($groupHeight * .72) / max(1, count($series))));
        foreach ($labels as $index => $label) {
            $groupY = $top + $groupHeight * $index + $groupHeight / 2;
            $labelParts = chartMarkdownLabelParts($label);
            $labelLines = chartLabelLines($labelParts['text'], 20);
            $labelStartY = $groupY - (($labelLinesCount = count($labelLines) - 1) * 18) / 2 - 7;
            foreach ($labelLines as $lineIndex => $labelLine) {
                chartTextStyledCentered($image, 16, 150, (int) ($labelStartY + $lineIndex * 18), $labelLine, $dark, $labelParts['style']);
            }
            foreach ($series as $seriesIndex => $item) {
                $value = max(0, (float) ($item['values'][$index] ?? 0));
                $y = $groupY + ($seriesIndex - (count($series) - 1) / 2) * $barHeight;
                $barRight = $left + ($right - $left) * $value / $maxValue;
                $drawRectangle($image, $left, (int) ($y - $barHeight / 2), (int) $barRight, (int) ($y + $barHeight / 2), $palette[$seriesIndex % count($palette)]);
                chartText($image, 14, (int) $barRight + 5, (int) ($y - 7), chartBarValueLabel($value), $dark, true);
            }
        }
        if ($diagramTitle !== '') { chartText($image, $diagramTitleSize, 10, 15, $diagramTitle, $dark, true); }
        foreach ($series as $seriesIndex => $item) {
            $legendColumn = $seriesIndex % 2;
            $legendRow = (int) floor($seriesIndex / 2);
            $legendX = $legendColumn === 0 ? 300 : 740;
            $legendY = 575 + $legendRow * 28;
            $drawRectangle($image, $legendX, $legendY, $legendX + 18, $legendY + 18, $palette[$seriesIndex % count($palette)]);
            chartText($image, 16, $legendX + 26, $legendY - 1, $item['name'], $dark, true);
        }
    } else {
        $left = 140;
        $right = 1130;
        $top = 75;
        $maxLabelLines = 1;
        foreach ($labels as $label) {
            $maxLabelLines = max($maxLabelLines, count(chartLabelLines(chartMarkdownLabelParts($label)['text'])));
        }
        $bottom = max(455, min(545, 635 - $maxLabelLines * 20));
        $maxValue = 0.0;
        foreach ($series as $item) {
            foreach ($item['values'] as $value) {
                $maxValue = max($maxValue, (float) ($value ?? 0));
            }
        }
        $maxValue = $maxValue > 0 ? $maxValue * 1.1 : 1;
        $drawLine($image, $left, $top, $left, $bottom, $dark);
        $drawLine($image, $left, $bottom, $right, $bottom, $dark);
        for ($tick = 0; $tick <= 5; $tick++) {
            $y = $bottom - ($bottom - $top) * $tick / 5;
            $value = $maxValue * $tick / 5;
            $grid = imagecolorallocate($image, 220, 225, 232);
            $drawLine($image, $left, (int) $y, $right, (int) $y, $grid);
            chartText($image, 18, 10, (int) $y - 10, number_format($value, 1, ',', ' '), $dark);
        }
        if ($diagramTitle !== '') {
            chartText($image, $diagramTitleSize, 10, 15, $diagramTitle, $dark, true);
        }
        $count = max(1, count($labels));
        $placedValueLabels = [];
        if (in_array($chartType, ['bar', 'combo'], true)) {
            $groupWidth = ($right - $left) / $count;
            // Käytä ryhmän vapaata tilaa, mutta jätä pylväiden väliin selkeä
            // marginaali ja pidä usean sarjan pylväät erillään toisistaan.
            $barSeries = $chartType === 'combo' ? array_slice($series, 0, 1) : $series;
            $barWidth = max(8, min(140, ($groupWidth * .80) / max(1, count($barSeries))));
            foreach ($labels as $index => $label) {
                $groupX = $left + $groupWidth * $index + $groupWidth / 2;
                foreach ($barSeries as $seriesIndex => $item) {
                    $value = max(0, (float) ($item['values'][$index] ?? 0));
                    $x = $groupX + ($seriesIndex - (count($barSeries) - 1) / 2) * $barWidth;
                    $barTop = $bottom - ($bottom - $top) * $value / $maxValue;
                    $drawRectangle($image, (int) ($x - $barWidth / 2), (int) $barTop, (int) ($x + $barWidth / 2), $bottom, $palette[$seriesIndex % count($palette)]);
                    $valueLabel = chartBarValueLabel($value);
                    $valueLabelY = chartValueLabelPosition($placedValueLabels, $x, (int) $barTop - 22, $valueLabel);
                    chartTextCentered($image, 15, $x, $valueLabelY, $valueLabel, $dark, true);
                }
                $labelParts = chartMarkdownLabelParts($label);
                foreach (chartLabelLines($labelParts['text']) as $lineIndex => $labelLine) {
                    chartTextStyledCentered($image, 16, $groupX, $bottom + 12 + $lineIndex * 20, $labelLine, $dark, $labelParts['style']);
                }
            }
        }
        if ($chartType === 'line' || $chartType === 'combo') {
            $lineSeries = $chartType === 'combo' ? array_slice($series, 1, null, true) : $series;
            foreach ($lineSeries as $seriesIndex => $item) {
                $previousX = null;
                $previousY = null;
                foreach ($labels as $index => $label) {
                    $value = max(0, (float) ($item['values'][$index] ?? 0));
                    $x = $count === 1 ? ($left + $right) / 2 : $left + ($right - $left) * $index / ($count - 1);
                    $y = $bottom - ($bottom - $top) * $value / $maxValue;
                    if ($previousX !== null) {
                        $drawLine($image, (int) $previousX, (int) $previousY, (int) $x, (int) $y, $palette[$seriesIndex % count($palette)]);
                    }
                    $drawEllipse($image, (int) $x, (int) $y, 12, 12, $palette[$seriesIndex % count($palette)]);
                    $valueLabel = chartValueLabel($value);
                    $valueLabelY = chartValueLabelPosition($placedValueLabels, $x, (int) $y - 22, $valueLabel);
                    chartTextCentered($image, 15, $x, $valueLabelY, $valueLabel, $dark, true);
                    $previousX = $x;
                    $previousY = $y;
                    if ($seriesIndex === 0 && $chartType !== 'combo') {
                        $labelParts = chartMarkdownLabelParts($label);
                        foreach (chartLabelLines($labelParts['text']) as $lineIndex => $labelLine) {
                            chartTextStyledCentered($image, 16, $x, $bottom + 12 + $lineIndex * 20, $labelLine, $dark, $labelParts['style']);
                        }
                    }
                }
            }
            foreach ($series as $seriesIndex => $item) {
                chartText($image, 18, 1000, 20 + $seriesIndex * 26, $item['name'], $palette[$seriesIndex % count($palette)], true);
            }
        }
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'tekraportti-chart-');
    $pngPath = $temporaryPath !== false ? $temporaryPath . '.png' : false;
    if ($temporaryPath !== false) {
        @unlink($temporaryPath);
    }
    $created = $pngPath !== false && imagepng($image, $pngPath);
    imagedestroy($image);
    if (!$created) {
        if ($pngPath !== false) {
            @unlink($pngPath);
        }
        return null;
    }
    $temporaryImages[] = $pngPath;
    return $pngPath;
}

function compressPdfIfNeeded(string $pdfContent): string
{
    if (strlen($pdfContent) <= 5 * 1024 * 1024) {
        return $pdfContent;
    }
    $input = tempnam(sys_get_temp_dir(), 'tekraportti-pdf-');
    $output = tempnam(sys_get_temp_dir(), 'tekraportti-pdf-packed-');
    if ($input === false || $output === false) {
        if ($input !== false) { @unlink($input); }
        if ($output !== false) { @unlink($output); }
        return $pdfContent;
    }
    file_put_contents($input, $pdfContent);
    $command = 'gs -q -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -dPDFSETTINGS=/ebook'
        . ' -sOutputFile=' . escapeshellarg($output) . ' ' . escapeshellarg($input);
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($process)) {
        foreach ($pipes as $pipe) { fclose($pipe); }
        $exitCode = proc_close($process);
        if ($exitCode === 0 && is_file($output) && filesize($output) > 0) {
            $packed = file_get_contents($output);
            if (is_string($packed) && strlen($packed) < strlen($pdfContent)) {
                $pdfContent = $packed;
            }
        }
    }
    @unlink($input); @unlink($output);
    return $pdfContent;
}

function localImagePath(string $url): ?string
{
    if ($url === '' || str_contains($url, '..') || preg_match('/^https?:\/\//i', $url)) {
        return null;
    }
    $path = realpath(__DIR__ . '/' . ltrim($url, '/'));
    if (!$path || !str_starts_with($path, realpath(__DIR__ . '/Tiedostot') . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $path;
}

function isPdfImagePath(?string $path): bool
{
    return $path !== null
        && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function normalizePdfImageOrientation(string $path, array &$temporaryImages): string
{
    $imageSize = @getimagesize($path);
    if (!$imageSize || $imageSize[2] !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
        return $path;
    }
    $exif = @exif_read_data($path);
    $orientation = (int) ($exif['Orientation'] ?? 1);
    if ($orientation < 2 || $orientation > 8) {
        return $path;
    }
    $image = @imagecreatefromjpeg($path);
    if (!$image) { return $path; }
    $angle = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
    if ($angle !== 0) { $image = imagerotate($image, $angle, 0); }
    if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
    }
    $temporaryPath = tempnam(sys_get_temp_dir(), 'tekraportti-cover-');
    $jpegPath = $temporaryPath !== false ? $temporaryPath . '.jpg' : false;
    if ($temporaryPath !== false) { @unlink($temporaryPath); }
    $created = $jpegPath !== false && imagejpeg($image, $jpegPath, 92);
    imagedestroy($image);
    if (!$created) { if ($jpegPath !== false) { @unlink($jpegPath); } return $path; }
    $temporaryImages[] = $jpegPath;
    return $jpegPath;
}

function pdfAnnotationColor($image, string $value)
{
    if (!preg_match('/^#([0-9a-f]{6})$/i', $value, $matches)) {
        return imagecolorallocate($image, 220, 53, 69);
    }
    return imagecolorallocate(
        $image,
        hexdec(substr($matches[1], 0, 2)),
        hexdec(substr($matches[1], 2, 2)),
        hexdec(substr($matches[1], 4, 2))
    );
}

/** Piirtää kuvan tekstimerkinnän siten, että Y-koordinaatti on tekstin perusviiva kuten Canvasissa. */
function drawPdfAnnotationText($image, int $size, int $x, int $baselineY, string $text, int $color): void
{
    $font = chartFontPath();
    if ($font !== null && function_exists('imagettftext')) {
        imagettftext($image, $size, 0, $x, $baselineY, $color, $font, $text);
        return;
    }

    $fontId = 5;
    imagestring($image, $fontId, $x, max(0, $baselineY - imagefontheight($fontId)), pdfText($text), $color);
}

function drawPdfImageAnnotations($image, array $annotations, int $sourceWidth, int $sourceHeight, int $cropX, int $cropY, int $cropWidth, int $cropHeight, int $outputX, int $outputY, int $outputWidth, int $outputHeight): void
{
    $toOutput = static function (float $x, float $y) use ($sourceWidth, $sourceHeight, $cropX, $cropY, $cropWidth, $cropHeight, $outputWidth, $outputHeight): array {
        $sourceX = max(0, min(1, $x)) * $sourceWidth;
        $sourceY = max(0, min(1, $y)) * $sourceHeight;
        return [
            $outputX + (int) round(($sourceX - $cropX) * $outputWidth / $cropWidth),
            $outputY + (int) round(($sourceY - $cropY) * $outputHeight / $cropHeight),
        ];
    };
    imagesetthickness($image, 5);
    foreach ($annotations as $annotation) {
        $color = pdfAnnotationColor($image, (string) ($annotation['color'] ?? '#dc3545'));
        $start = $toOutput((float) ($annotation['x1'] ?? 0), (float) ($annotation['y1'] ?? 0));
        $end = $toOutput((float) ($annotation['x2'] ?? 0), (float) ($annotation['y2'] ?? 0));
        $type = (string) ($annotation['type'] ?? '');
        if ($type === 'rectangle') {
            imagerectangle($image, $start[0], $start[1], $end[0], $end[1], $color);
        } elseif ($type === 'circle') {
            imageellipse($image, (int) round(($start[0] + $end[0]) / 2), (int) round(($start[1] + $end[1]) / 2), abs($end[0] - $start[0]), abs($end[1] - $start[1]), $color);
        } elseif ($type === 'arrow') {
            imageline($image, $start[0], $start[1], $end[0], $end[1], $color);
            $angle = atan2($end[1] - $start[1], $end[0] - $start[0]);
            $headLength = 22;
            imageline($image, $end[0], $end[1], (int) round($end[0] - $headLength * cos($angle - 0.5)), (int) round($end[1] - $headLength * sin($angle - 0.5)), $color);
            imageline($image, $end[0], $end[1], (int) round($end[0] - $headLength * cos($angle + 0.5)), (int) round($end[1] - $headLength * sin($angle + 0.5)), $color);
        } elseif ($type === 'freehand') {
            $previous = null;
            foreach ((array) ($annotation['points'] ?? []) as $point) {
                if (!is_array($point) || count($point) < 2) {
                    continue;
                }
                $current = $toOutput((float) $point[0], (float) $point[1]);
                if ($previous !== null) {
                    imageline($image, $previous[0], $previous[1], $current[0], $current[1], $color);
                }
                $previous = $current;
            }
        } elseif ($type === 'text') {
            $fontSize = max(8, min(240, (int) round((float) ($annotation['fontSize'] ?? 0.025) * $outputWidth)));
            drawPdfAnnotationText($image, $fontSize, $start[0], $start[1], (string) ($annotation['text'] ?? ''), $color);
        }
    }
    imagesetthickness($image, 1);
}

function cropPdfImage(string $path, float $targetAspect, array &$temporaryImages, array $annotations = []): ?string
{
    $imageSize = @getimagesize($path);
    if (!$imageSize || $imageSize[0] < 1 || $imageSize[1] < 1 || $targetAspect <= 0) {
        return null;
    }

    $source = match ($imageSize[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_GIF => @imagecreatefromgif($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
    if (!$source) {
        return null;
    }

    // GD ei huomioi JPEG:n EXIF Orientation-tietoa. Normalisoi kuva ennen
    // mittojen ja merkintöjen käsittelyä, jotta PDF vastaa esikatselua.
    $orientation = 1;
    if ($imageSize[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);
    }
    if ($orientation >= 2 && $orientation <= 8) {
        $angle = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($angle !== 0) { $source = imagerotate($source, $angle, 0); }
        if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) { imageflip($source, IMG_FLIP_HORIZONTAL); }
    }
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $sourceAspect = $sourceWidth / $sourceHeight;
    $cropAnnotation = null;
    foreach ($annotations as $annotation) { if (($annotation['type'] ?? '') === 'crop') { $cropAnnotation = $annotation; break; } }
    if ($cropAnnotation) {
        $x1 = min((float) $cropAnnotation['x1'], (float) $cropAnnotation['x2']); $x2 = max((float) $cropAnnotation['x1'], (float) $cropAnnotation['x2']);
        $y1 = min((float) $cropAnnotation['y1'], (float) $cropAnnotation['y2']); $y2 = max((float) $cropAnnotation['y1'], (float) $cropAnnotation['y2']);
        $cropX = (int) round(max(0, min(1, $x1)) * $sourceWidth);
        $cropY = (int) round(max(0, min(1, $y1)) * $sourceHeight);
        $cropWidth = max(1, (int) round(max(0.01, min(1, $x2 - $x1)) * $sourceWidth));
        $cropHeight = max(1, (int) round(max(0.01, min(1, $y2 - $y1)) * $sourceHeight));
        $cropWidth = min($cropWidth, $sourceWidth - $cropX); $cropHeight = min($cropHeight, $sourceHeight - $cropY);
    } else {
        // Ilman käyttäjän tekemää rajausta käytetään raportin vakiomuotoista
        // keskeltä tehtävää rajausta.
        if ($sourceAspect > $targetAspect) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) floor($sourceHeight * $targetAspect);
            $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) floor($sourceWidth / $targetAspect);
            $cropX = 0;
            $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }
    }

    $outputWidth = 1200;
    $outputHeight = max(1, (int) round($outputWidth / $targetAspect));
    $output = imagecreatetruecolor($outputWidth, $outputHeight);
    $white = imagecolorallocate($output, 255, 255, 255);
    imagefill($output, 0, 0, $white);
    $renderX = 0;
    $renderY = 0;
    $renderWidth = $outputWidth;
    $renderHeight = $outputHeight;
    imagecopyresampled($output, $source, $renderX, $renderY, $cropX, $cropY, $renderWidth, $renderHeight, $cropWidth, $cropHeight);
    drawPdfImageAnnotations($output, array_values(array_filter($annotations, static fn (array $annotation): bool => ($annotation['type'] ?? '') !== 'crop')), $sourceWidth, $sourceHeight, $cropX, $cropY, $cropWidth, $cropHeight, $renderX, $renderY, $renderWidth, $renderHeight);

    $temporaryPath = tempnam(sys_get_temp_dir(), 'tekraportti-image-');
    $jpegPath = $temporaryPath !== false ? $temporaryPath . '.jpg' : false;
    if ($temporaryPath !== false) {
        @unlink($temporaryPath);
    }
    $created = $jpegPath !== false && imagejpeg($output, $jpegPath, 90);
    imagedestroy($output);
    imagedestroy($source);
    if (!$created) {
        if ($jpegPath !== false) {
            @unlink($jpegPath);
        }
        return null;
    }

    $temporaryImages[] = $jpegPath;
    return $jpegPath;
}

function youtubeVideoId(string $url): ?string
{
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = trim((string) ($parts['path'] ?? ''), '/');
    $videoId = null;

    if ($host === 'youtu.be' || $host === 'www.youtu.be') {
        $videoId = explode('/', $path)[0] ?? null;
    } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
        parse_str((string) ($parts['query'] ?? ''), $query);
        if ($path === 'watch') {
            $videoId = $query['v'] ?? null;
        } elseif (preg_match('#^(?:embed|shorts|live)/([^/]+)#', $path, $matches)) {
            $videoId = $matches[1];
        }
    }

    return is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) ? $videoId : null;
}

function youtubeThumbnailPath(string $url, array &$temporaryImages): ?string
{
    $videoId = youtubeVideoId($url);
    if (!$videoId) {
        return null;
    }

    $curl = curl_init('https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_MAXFILESIZE => 5 * 1024 * 1024,
        CURLOPT_USERAGENT => 'Tekraportti PDF preview',
    ]);
    $thumbnail = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($thumbnail) || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $thumbnailImage = @imagecreatefromstring($thumbnail);
    if (!$thumbnailImage) {
        return null;
    }
    $thumbnailWidth = imagesx($thumbnailImage);
    $thumbnailHeight = imagesy($thumbnailImage);
    $playRadius = (int) round(min($thumbnailWidth, $thumbnailHeight) * 0.14);
    $playCenterX = (int) round($thumbnailWidth / 2);
    $playCenterY = (int) round($thumbnailHeight / 2);
    $playColor = imagecolorallocatealpha($thumbnailImage, 210, 0, 0, 25);
    imagefilledellipse($thumbnailImage, $playCenterX, $playCenterY, $playRadius * 2, $playRadius * 2, $playColor);
    $triangleWidth = (int) round($playRadius * 0.8);
    $triangleHeight = (int) round($playRadius * 1.1);
    $triangle = [
        $playCenterX - (int) round($triangleWidth / 2), $playCenterY - (int) round($triangleHeight / 2),
        $playCenterX - (int) round($triangleWidth / 2), $playCenterY + (int) round($triangleHeight / 2),
        $playCenterX + (int) round($triangleWidth / 2), $playCenterY,
    ];
    $playTriangleColor = imagecolorallocate($thumbnailImage, 255, 255, 255);
    imagefilledpolygon($thumbnailImage, $triangle, $playTriangleColor);

    $temporaryPath = tempnam(sys_get_temp_dir(), 'tekraportti-youtube-');
    $jpegPath = $temporaryPath !== false ? $temporaryPath . '.jpg' : false;
    if ($temporaryPath !== false) {
        @unlink($temporaryPath);
    }
    $created = $jpegPath !== false && imagejpeg($thumbnailImage, $jpegPath, 90);
    imagedestroy($thumbnailImage);
    if (!$created) {
        if ($jpegPath !== false) {
            @unlink($jpegPath);
        }
        return null;
    }

    $temporaryImages[] = $jpegPath;
    return $jpegPath;
}

class TekraPdf extends \setasign\Fpdi\Fpdi
{
    public function writePageNumber(int $pdfPage, float $x, float $y, float $width, string $text, bool $bold): void
    {
        if (!isset($this->pages[$pdfPage])) {
            return;
        }
        $currentPage = $this->page;
        $currentX = $this->x;
        $currentY = $this->y;
        $this->page = $pdfPage;
        $this->SetXY($x, $y);
        $this->SetFont('Helvetica', $bold ? 'B' : '', $bold ? 12 : 11);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($width, 8, pdfText($text), 0, 0, 'R');
        $this->page = $currentPage;
        $this->SetXY($currentX, $currentY);
    }

    public function drawClosedListMarker(): void
    {
        $centerX = $this->GetX() + 2.5;
        $centerY = $this->GetY() + 3;
        $radius = 0.9;
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.25);
        for ($offset = -0.75; $offset <= 0.75; $offset += 0.25) {
            $halfWidth = sqrt(max(0, $radius * $radius - $offset * $offset));
            $this->Line($centerX - $halfWidth, $centerY + $offset, $centerX + $halfWidth, $centerY + $offset);
        }
        $this->SetLineWidth(0.2);
        $this->Cell(5, 6, '', 0, 0);
    }

    public function drawFilledListMarker(): void
    {
        $this->drawClosedListMarker();
    }

    public function drawOpenListMarker(): void
    {
        $centerX = $this->GetX() + 2.5;
        $centerY = $this->GetY() + 3;
        $radius = 1.2;
        $points = [];
        for ($index = 0; $index < 13; $index++) {
            $angle = 2 * M_PI * $index / 12;
            $points[] = [$centerX + cos($angle) * $radius, $centerY + sin($angle) * $radius];
        }
        $this->SetLineWidth(0.25);
        for ($index = 0; $index < 12; $index++) {
            $this->Line($points[$index][0], $points[$index][1], $points[$index + 1][0], $points[$index + 1][1]);
        }
        $this->Cell(5, 6, '', 0, 0);
    }

    public function ensureSpace(float $height): void
    {
        if ($this->GetY() + $height > $this->PageBreakTrigger) {
            $this->AddPage();
        }
    }

    public function Header(): void
    {
        $printDate = (string) ($GLOBALS['printDate'] ?? '');
        $this->SetY(7);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(0, 0, 0);
        $logoPath = $GLOBALS['reportLogoPath'] ?? null;
        $logoSize = is_string($logoPath) && isPdfImagePath($logoPath) ? @getimagesize($logoPath) : false;
        if ($logoSize && $logoSize[0] > 0 && $logoSize[1] > 0) {
            $maxWidth = 38;
            $maxHeight = 8;
            $scale = min($maxWidth / $logoSize[0], $maxHeight / $logoSize[1]);
            $this->Image($logoPath, $this->lMargin, 5, $logoSize[0] * $scale, $logoSize[1] * $scale);
        } else {
            $this->Cell(125, 5, pdfText((string) $GLOBALS['report']['otsikko']), 0, 0, 'L');
        }
        $this->Cell(0, 5, pdfText($printDate), 0, 1, 'R');
        $this->SetDrawColor(110, 110, 110);
        $this->SetLineWidth(0.2);
        $this->Line($this->lMargin, 15, $this->GetPageWidth() - $this->rMargin, 15);
        // FPDF jatkaa Header()-metodin jälkeen siitä kohdasta, johon header jäi.
        // Palautetaan sisältö alkamaan asetetun ylämarginaalin kohdalta.
        $this->SetY($this->tMargin);
    }

    public function Footer(): void
    {
        // Kansisivu ei kuulu raportin sivunumerointiin.
        if ($this->PageNo() === 1) {
            return;
        }

        $this->SetY(-26);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 5, ($this->PageNo() - 1) . ' / ' . '{nb}', 0, 1, 'R');
        $this->SetDrawColor(110, 110, 110);
        $this->SetLineWidth(0.2);
        $this->Line($this->lMargin, $this->GetY() + 1, $this->GetPageWidth() - $this->rMargin, $this->GetY() + 1);
        $this->SetY($this->GetY() + 3);
        $this->SetFont('Helvetica', '', 7.5);
        $this->Cell(0, 5, pdfText(pdfFooterContact($GLOBALS['report'])), 0, 0, 'C');
    }

    protected function _putpage($pageNumber): void
    {
        $this->_newobj();
        $this->_put('<</Type /Page');
        $this->_put('/Parent 1 0 R');
        if (isset($this->PageInfo[$pageNumber]['size'])) {
            $this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]', $this->PageInfo[$pageNumber]['size'][0], $this->PageInfo[$pageNumber]['size'][1]));
        }
        if (isset($this->PageInfo[$pageNumber]['rotation'])) {
            $this->_put('/Rotate ' . $this->PageInfo[$pageNumber]['rotation']);
        }
        $this->_put('/Resources 2 0 R');
        if (!empty($this->PageLinks[$pageNumber])) {
            $links = '/Annots [';
            foreach ($this->PageLinks[$pageNumber] as $link) {
                $links .= $link[5] . ' 0 R ';
            }
            $this->_put($links . ']');
        }
        if ($this->WithAlpha) {
            $this->_put('/Group <</Type /Group /S /Transparency /CS /DeviceRGB>>');
        }
        $this->_put('/Contents ' . ($this->n + 1) . ' 0 R>>');
        $this->_put('endobj');

        if (!empty($this->AliasNbPages)) {
            $this->pages[$pageNumber] = str_replace($this->AliasNbPages, max(1, $this->page - 1), $this->pages[$pageNumber]);
        }
        $this->_putstreamobject($this->pages[$pageNumber]);
        $this->_putlinks($pageNumber);
    }
}

function appendPdfAttachments(TekraPdf $pdf, array $attachments, array $attachmentSectionNumbers): array
{
    $attachmentPages = [];
    foreach ($attachments as $attachmentIndex => $attachment) {
        $attachmentPath = localImagePath((string) $attachment['tiedosto']);
        if (!$attachmentPath || !is_file($attachmentPath)) {
            continue;
        }
        try {
            $pageCount = $pdf->setSourceFile($attachmentPath);
            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $template = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($template);
                $landscape = $size['width'] > $size['height'];
                $pdf->AddPage($landscape ? 'L' : 'P', 'A4');

                // Sovita liite sivumarginaalien sekä ylä- ja alatunnisteen
                // väliin. Säilytä mittasuhteet ja kohdista vasemmalle sekä ylös.
                $contentX = 20.0;
                $contentY = 22.0;
                if ($pageNumber === 1) {
                    $attachmentName = trim((string) ($attachment['alkuperainen_nimi'] ?? ''));
                    $attachmentNumber = (int) ($attachmentSectionNumbers[$attachmentIndex] ?? 0);
                    $attachmentTitle = ($attachmentNumber > 0 ? $attachmentNumber . '. ' : '') . $attachmentName;
                    $pdf->SetXY(20, $contentY);
                    $pdf->SetFont('Helvetica', 'B', 17);
                    $pdf->MultiCell($pdf->GetPageWidth() - 40, 10, pdfText($attachmentTitle), 0, 'L');
                    $contentY = $pdf->GetY() + 5;
                }
                $contentWidth = $pdf->GetPageWidth() - $contentX - 7.5;
                $contentHeight = $pdf->GetPageHeight() - $contentY - 26.0;
                $scale = min($contentWidth / $size['width'], $contentHeight / $size['height']);
                $renderWidth = $size['width'] * $scale;
                $renderHeight = $size['height'] * $scale;
                $pdf->useTemplate($template, $contentX, $contentY, $renderWidth, $renderHeight);
                if (!isset($attachmentPages[$attachmentIndex])) {
                    $attachmentPages[$attachmentIndex] = max(1, $pdf->PageNo() - 1);
                }
            }
        } catch (Throwable $error) {
            error_log('PDF-liitteen lisääminen epäonnistui: ' . $error->getMessage());
        }
    }
    return $attachmentPages;
}

/** Lisää valmiin raporttiaineiston sivut ilman erillistä otsikkoa. */
function appendPdfSourceMaterial(TekraPdf $pdf, ?array $sourceMaterial): array
{
    if (!$sourceMaterial) {
        return [];
    }
    $sourcePath = localImagePath((string) ($sourceMaterial['tiedosto'] ?? ''));
    if (!$sourcePath || !is_file($sourcePath)) {
        return [];
    }

    $pageMap = [];
    try {
        $pageCount = $pdf->setSourceFile($sourcePath);
        for ($sourcePage = 1; $sourcePage <= $pageCount; $sourcePage++) {
            $template = $pdf->importPage($sourcePage);
            $size = $pdf->getTemplateSize($template);
            $landscape = $size['width'] > $size['height'];
            $pdf->AddPage($landscape ? 'L' : 'P', 'A4');
            // Käytä tuodulle aineistolle tekstisisältöä leveämpää aluetta.
            // Ylä- ja alatunnisteille jää oma tila, ja alkuperäinen kuvasuhde
            // säilyy sekä pysty- että vaakasivuilla.
            $contentX = 7.5;
            $contentY = 17.5;
            $contentWidth = $pdf->GetPageWidth() - 15.0;
            $contentHeight = $pdf->GetPageHeight() - $contentY - 26.0;
            $scale = min($contentWidth / $size['width'], $contentHeight / $size['height']);
            $renderWidth = $size['width'] * $scale;
            $renderHeight = $size['height'] * $scale;
            $pdf->useTemplate($template, $contentX, $contentY, $renderWidth, $renderHeight);
            $pageMap[$sourcePage] = max(1, $pdf->PageNo() - 1);
        }
    } catch (Throwable $error) {
        error_log('Valmiin PDF-aineiston lisääminen epäonnistui: ' . $error->getMessage());
    }
    return $pageMap;
}

$pdf = new TekraPdf();
$GLOBALS['printDate'] = date('d.m.Y');
$pdf->AliasNbPages();
$pdf->SetTitle(pdfText($report['otsikko']));
$pdf->SetAuthor(pdfText($report['laatija_nimi']));
$pdf->SetMargins(20, 22, 20);
$pdf->SetAutoPageBreak(true, 26);
$GLOBALS['reportLogoPath'] = localImagePath((string) ($report['laatija_logo_url'] ?? ''));
$temporaryImages = [];
$chartSelections = is_array($_GET['kaavio'] ?? null) ? $_GET['kaavio'] : [];
$chartColumnSelections = is_array($_GET['kaavio_sarakkeet'] ?? null) ? $_GET['kaavio_sarakkeet'] : [];
$hideTableSelections = is_array($_GET['piilota_taulukko'] ?? null) ? $_GET['piilota_taulukko'] : [];
$showSignature = (string) ($_GET['allekirjoitus'] ?? '1') !== '0';
$customerInfoColorOverride = strtolower(trim((string) ($_GET['asiakastiedot_taustavari'] ?? '')));
if (!preg_match('/^#[0-9a-f]{6}$/', $customerInfoColorOverride)) {
    $customerInfoColorOverride = '';
}
// Osiokuvien kiinteä mallikoko ensimmäisen mallikuvan perusteella.
$sectionImageWidth = 82.5;
$sectionImagePixelWidth = 390;
$sectionImagePixelHeight = 322;
$sectionImageAspect = $sectionImagePixelWidth / $sectionImagePixelHeight;
$sectionImageHeight = $sectionImageWidth / $sectionImageAspect;
register_shutdown_function(static function () use (&$temporaryImages): void {
    foreach ($temporaryImages as $temporaryImage) {
        @unlink($temporaryImage);
    }
});

// Kansisivu.
$pdf->AddPage();
$pdf->Ln(35);
$pdf->SetFont('Helvetica', 'B', 24);
$pdf->MultiCell(0, 12, pdfText($report['otsikko']), 0, 'C');
$pdf->Ln(8);
$coverPath = localImagePath((string) ($report['kansikuva'] ?? ''));
if ($coverPath !== null) {
    $coverPath = normalizePdfImageOrientation($coverPath, $temporaryImages);
}
if ($coverPath && in_array(strtolower(pathinfo($coverPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'], true)) {
    $imageSize = @getimagesize($coverPath);
    if ($imageSize) {
        // Kansikuva saa olla kaksi osiokuvan kokoista.
        $maxImageWidth = $sectionImageWidth * 2;
        // Jätä kansisivulle tilaa asiakastiedoille ja laatijan tiedoille,
        // jotta ne eivät siirry omalle sivulleen.
        $maxImageHeight = 115;
        $scale = min(
            $maxImageWidth / $imageSize[0],
            $maxImageHeight / $imageSize[1]
        );
        $imageWidth = $imageSize[0] * $scale;
        $imageHeight = $imageSize[1] * $scale;
        $imageX = 20 + ($maxImageWidth - $imageWidth) / 2;
        $pdf->Image($coverPath, $imageX, $pdf->GetY(), $imageWidth, $imageHeight);
        $pdf->Ln($imageHeight + 15);
    }
}
$pdf->SetFont('Helvetica', '', 13);
$customerCompany = trim((string) ($report['asiakas_yritys'] ?? ''));
$customerAddress = trim((string) ($report['asiakas_osoite'] ?? ''));
$customerPostal = trim((string) ($report['asiakas_postinro'] ?? ''));
$customerCity = trim((string) ($report['asiakas_postitmp'] ?? ''));
if ($customerCompany !== '') {
    $coverCustomerLines = [$customerCompany];
} elseif ($customerAddress !== '') {
    $coverCustomerLines = [$customerAddress];
    $postalLine = trim($customerPostal . ($customerPostal !== '' && $customerCity !== '' ? ' ' : '') . $customerCity);
    if ($postalLine !== '') {
        $coverCustomerLines[] = $postalLine;
    }
} else {
    $coverCustomerLines = [trim((string) $report['asiakas_nimi'])];
}
foreach ($coverCustomerLines as $coverCustomerLine) {
    $pdf->Cell(0, 8, pdfText($coverCustomerLine), 0, 1, 'C');
}
$pdf->Cell(0, 8, pdfText(pdfDate($report['raporttipvm'])), 0, 1, 'C');
$pdf->Ln(18);
$pdf->SetFont('Helvetica', '', 11);
$pdf->Cell(0, 7, pdfText($report['laatija_nimi']), 0, 1, 'C');
if ($report['laatija_yritys']) {
    $pdf->Cell(0, 7, pdfText($report['laatija_yritys']), 0, 1, 'C');
}

// Sisällysluettelo.
$pdf->AddPage();
$pdf->SetFont('Helvetica', 'B', 18);
$pdf->Cell(0, 12, pdfText('Sisällysluettelo'), 0, 1);
$pdf->Ln(8);
$lastSectionIndex = array_key_last($rows);
$deferredSignatureSectionIndex = null;
if ($lastSectionIndex !== null) {
    $lastSectionTitle = mb_strtolower(trim((string) $rows[$lastSectionIndex]['otsikko']), 'UTF-8');
    if (in_array($lastSectionTitle, ['allekirjoitus', 'allekirjoitukset'], true)) {
        $deferredSignatureSectionIndex = $lastSectionIndex;
    }
}
$attachmentStartNumber = $deferredSignatureSectionIndex !== null
    ? $deferredSignatureSectionIndex + 1
    : count($rows) + 1;
$attachmentSectionNumbers = [];
foreach (array_keys($attachments) as $attachmentOrdinal => $attachmentIndex) {
    $attachmentSectionNumbers[$attachmentIndex] = $attachmentStartNumber + $attachmentOrdinal;
}
$sectionNumbers = [];
foreach (array_keys($rows) as $sectionIndex) {
    $sectionNumbers[$sectionIndex] = $sectionIndex + 1;
    if ($deferredSignatureSectionIndex !== null && $sectionIndex >= $deferredSignatureSectionIndex) {
        $sectionNumbers[$sectionIndex] += count($attachments);
    }
}
$tocEntries = [];
$sourceHeadings = is_array($sourceMaterial['otsikot'] ?? null) ? $sourceMaterial['otsikot'] : [];
foreach ($sourceHeadings as $heading) {
    if (!is_array($heading)) {
        continue;
    }
    $heading = normalizeStoredPdfHeading($heading);
    if ($heading === null) {
        continue;
    }
    $tocEntries[] = [
        'number' => $heading['number'],
        'title' => $heading['title'],
        'material_page' => $heading['page'],
        'indent' => max(0, min(3, $heading['level'])),
    ];
}
$attachmentTocEntriesAdded = false;
foreach ($rows as $index => $row) {
    if ($index === $deferredSignatureSectionIndex) {
        foreach ($attachments as $attachmentIndex => $attachment) {
            $tocEntries[] = [
                'number' => $attachmentSectionNumbers[$attachmentIndex] . '.',
                'title' => (string) $attachment['alkuperainen_nimi'],
                'attachment_index' => $attachmentIndex,
                'indent' => false,
            ];
        }
        $attachmentTocEntriesAdded = true;
    }
    $sectionNumber = $sectionNumbers[$index];
    $tocEntries[] = [
        'number' => $sectionNumber . '.',
        'title' => $row['otsikko'],
        'section_index' => $index,
        'indent' => false,
    ];
    $sectionTextParts = [(string) $row['sisalto']];
    foreach ($contentBlocksByRow[(int) $row['id']] ?? [] as $contentBlock) {
        if (($contentBlock['type'] ?? '') === 'text') {
            $sectionTextParts[] = (string) ($contentBlock['data']['sisalto'] ?? '');
        }
    }
    foreach (reportSubheadings(implode("\n\n", $sectionTextParts)) as $heading) {
        $tocEntries[] = [
            'number' => $sectionNumber . '.' . $heading['number'],
            'title' => $heading['title'],
            'section_index' => $index,
            'indent' => (int) $heading['level'],
        ];
    }
}
if (!$attachmentTocEntriesAdded) {
    foreach ($attachments as $attachmentIndex => $attachment) {
        $tocEntries[] = [
            'number' => $attachmentSectionNumbers[$attachmentIndex] . '.',
            'title' => (string) $attachment['alkuperainen_nimi'],
            'attachment_index' => $attachmentIndex,
            'indent' => false,
        ];
    }
}
$tocPageNumberPositions = [];
foreach ($tocEntries as $entry) {
    $pdf->SetFont('Helvetica', $entry['indent'] ? '' : 'B', $entry['indent'] ? 11 : 12);
    $numberText = pdfText((string) ($entry['number'] ?? ''));
    $title = (string) $entry['title'];
    $contentWidth = $pdf->GetPageWidth() - 40;
    $indent = ((int) $entry['indent']) * 8;
    $pageNumberWidth = $pdf->GetStringWidth('000');
    $numberWidth = $pdf->GetStringWidth($numberText);
    $pageNumberGap = 3;
    $numberGap = $numberText === '' ? 0 : 2;
    $titleMaxWidth = $contentWidth - $indent - $numberWidth - $numberGap - $pageNumberWidth - $pageNumberGap - 2;
    // Pilko otsikko UTF-8-muodossa. Muunnos PDF:n Windows-1252-merkistöön
    // tehdään vasta pilkkomisen jälkeen, jotta esimerkiksi ä ja ö eivät
    // riko UTF-8-regexiä ja hävitä koko otsikkoa.
    $titleLines = array_map(
        static fn(string $line): string => pdfText($line),
        pdfTextChunksByWords($pdf, $title, $titleMaxWidth)
    );
    foreach (array_slice($titleLines, 0, -1) as $titleLine) {
        $pdf->SetX(20 + $indent + $numberWidth + $numberGap);
        $pdf->Cell($titleMaxWidth, 7, $titleLine, 0, 1, 'L');
    }
    $lastTitleLine = end($titleLines) ?: '';
    $pdf->SetX(20 + $indent);
    if ($numberWidth + $numberGap > 0) {
        $pdf->Cell($numberWidth + $numberGap, 7, $numberText . ' ', 0, 0, 'L');
    }
    $titleWidth = $pdf->GetStringWidth($lastTitleLine);
    $spaceWidth = $pdf->GetStringWidth(' ');
    $dotsWidth = max(0, $contentWidth - $indent - $numberWidth - $numberGap - $titleWidth - $pageNumberWidth - $spaceWidth - $pageNumberGap);
    $dotWidth = $pdf->GetStringWidth('.');
    $dots = $dotWidth > 0 ? str_repeat('.', (int) floor($dotsWidth / $dotWidth)) : '';
    $pdf->Cell($titleWidth + $spaceWidth, 7, $lastTitleLine . ' ', 0, 0, 'L');
    $pdf->Cell($dotsWidth, 8, $dots, 0, 0, 'L');
    $pdf->Cell($pageNumberGap, 8, '', 0, 0, 'L');
    $tocPageNumberPositions[] = [
        'pdf_page' => $pdf->PageNo(),
        'x' => $pdf->GetX(),
        'y' => $pdf->GetY(),
        'width' => $pageNumberWidth,
        'section_index' => isset($entry['section_index']) ? (int) $entry['section_index'] : null,
        'attachment_index' => isset($entry['attachment_index']) ? (int) $entry['attachment_index'] : null,
        'material_page' => isset($entry['material_page']) ? (int) $entry['material_page'] : null,
        'bold' => !$entry['indent'],
    ];
    $pdf->Cell($pageNumberWidth, 8, '', 0, 1, 'R');
}
if (!$tocEntries) {
    $pdf->Cell(0, 8, pdfText('Ei vielä lisättyjä osioita.'), 0, 1);
}

// Raporttiosiot.
$sourceMaterialPages = appendPdfSourceMaterial($pdf, $sourceMaterial);
$hasSourceMaterialPages = $sourceMaterialPages !== [];
$sectionPages = [];
$hasAttachmentPages = false;
$attachmentPages = [];
$attachmentsInserted = false;
foreach ($rows as $index => $row) {
    $sectionNumber = $sectionNumbers[$index];
    $sectionStartsAfterAttachments = false;
    if ($index === $deferredSignatureSectionIndex) {
        $attachmentPages = appendPdfAttachments($pdf, $attachments, $attachmentSectionNumbers);
        $hasAttachmentPages = $attachmentPages !== [];
        $attachmentsInserted = true;
        if ($hasAttachmentPages) {
            $pdf->AddPage('P', 'A4');
            $sectionStartsAfterAttachments = true;
        }
    }
    if (!$sectionStartsAfterAttachments && $index === 0) {
        $pdf->AddPage();
    } elseif (!$sectionStartsAfterAttachments) {
        $sectionContent = trim((string) $row['sisalto']);
        if ($sectionContent === '') {
            foreach ($contentBlocksByRow[(int) $row['id']] ?? [] as $contentBlock) {
                if (($contentBlock['type'] ?? '') === 'text') {
                    $sectionContent = trim((string) ($contentBlock['data']['sisalto'] ?? ''));
                    if ($sectionContent !== '') {
                        break;
                    }
                }
            }
        }
        $pdf->Ln(8);
        $pdf->ensureSpace(pdfSectionStartHeight($pdf, $sectionNumber, (string) $row['otsikko'], $sectionContent));
    }
    $sectionPages[$index] = max(1, $pdf->PageNo() - 1);
    $pdf->SetFont('Helvetica', 'B', 17);
    $pdf->MultiCell(0, 10, pdfText($sectionNumber . '. ' . $row['otsikko']), 0, 'L');
    if (!empty($row['sisaltaa_asiakastiedot'])) {
        $customerInfoRows = pdfCustomerInfoRows($report, $customerDetails);
        if ($customerInfoRows) {
            $nextTable = $tablesByRow[(int) $row['id']][0] ?? null;
            $defaultCustomerInfoColor = '#ffffff';
            if ($nextTable && !empty($nextTable['sarakkeet'])) {
                $candidateColor = (string) ($nextTable['sarakkeet'][0]['taustavari'] ?? '');
                if (preg_match('/^#[0-9a-f]{6}$/i', $candidateColor)) { $defaultCustomerInfoColor = strtolower($candidateColor); }
            }
            $customerInfoColor = $customerInfoColorOverride !== '' ? $customerInfoColorOverride : $defaultCustomerInfoColor;
            $customerInfoTableWidth = $pdf->GetPageWidth() - 40;
            $customerInfoColumnWidths = [$customerInfoTableWidth * 0.48, $customerInfoTableWidth * 0.52];
            $customerInfoRowHeights = [];
            $customerInfoHeight = 0.0;
            foreach ($customerInfoRows as $customerInfoRow) {
                $rowLines = max(
                    pdfTableLineCount($pdf, (string) $customerInfoRow[0], $customerInfoColumnWidths[0] - 2),
                    pdfTableLineCount($pdf, (string) $customerInfoRow[1], $customerInfoColumnWidths[1] - 2)
                );
                $rowHeight = $rowLines * 5 + 2;
                $customerInfoRowHeights[] = $rowHeight;
                $customerInfoHeight += $rowHeight;
            }
            $pdf->ensureSpace($customerInfoHeight + 5);
            $pdf->Ln(2);
            $customerInfoY = $pdf->GetY();
            foreach ($customerInfoRows as $rowIndex => $customerInfoRow) {
                $rowHeight = $customerInfoRowHeights[$rowIndex];
                $x = 20;
                foreach ($customerInfoRow as $columnIndex => $cellText) {
                    $pdf->SetXY($x, $customerInfoY);
                    $cellColor = $columnIndex === 0 ? pdfHexColor($customerInfoColor) : [255, 255, 255];
                    $pdf->SetFillColor($cellColor[0], $cellColor[1], $cellColor[2]);
                    $pdf->SetTextColor(35, 45, 60);
                    $pdf->Cell($customerInfoColumnWidths[$columnIndex], $rowHeight, '', 1, 0, 'L', true);
                    $pdf->SetXY($x + 1, $customerInfoY + 1);
                    pdfWriteMarkdownCell($pdf, (string) $cellText, $customerInfoColumnWidths[$columnIndex] - 2, 5, '', 'L');
                    $x += $customerInfoColumnWidths[$columnIndex];
                }
                $customerInfoY += $rowHeight;
            }
            $pdf->SetXY(20, $customerInfoY + 5);
        }
    }
    $pdf->Ln(5);
    $pdf->SetFont('Helvetica', '', 11);
    $headingCounters = [];
    $content = trim((string) $row['sisalto']);
    if ($content !== '') {
        pdfTextWithLists($pdf, $content, $sectionNumber, $headingCounters);
    }

    // Osioon liitetyt tekstilohkot ja taulukot niiden yhteisessä järjestyksessä.
    foreach ($contentBlocksByRow[(int) $row['id']] ?? [] as $contentBlock) {
        if (($contentBlock['type'] ?? '') === 'text') {
            $textContent = trim((string) ($contentBlock['data']['sisalto'] ?? ''));
            if ($textContent !== '') {
                $pdf->Ln(5);
                $pdf->SetFont('Helvetica', '', 11);
                pdfTextWithLists($pdf, $textContent, $sectionNumber, $headingCounters);
            }
            continue;
        }
        $table = $contentBlock['data'];
        $columns = $table['sarakkeet'];
        if (!$columns) {
            continue;
        }
        $chartType = (string) ($chartSelections[(int) $table['id']] ?? '');
        $hideTable = $chartType !== '' && isset($hideTableSelections[(int) $table['id']]);
        if (!$hideTable) {
        $pageTableWidth = $pdf->GetPageWidth() - 40;
        $tableWidth = (string) ($table['leveys_tila'] ?? 'keskitetty') === 'kokosivu' ? $pageTableWidth : $pageTableWidth * .88;
        $tableLeft = 20 + (($pageTableWidth - $tableWidth) / 2);
        $tableBorder = (int) ($table['reunat'] ?? 1) === 1 ? 1 : 0;
        $columnWidths = [];
        $widthTotal = 0.0;
        foreach ($columns as $column) {
            $columnWidth = (float) ($column['leveys'] ?? 0);
            $columnWidths[] = $columnWidth;
            $widthTotal += $columnWidth > 0 ? $columnWidth : 0;
        }
        if ($widthTotal <= 0) {
            $columnWidths = array_fill(0, count($columns), $tableWidth / count($columns));
        } else {
            foreach ($columnWidths as $columnIndex => $columnWidth) {
                $columnWidths[$columnIndex] = ($columnWidth > 0 ? $columnWidth : $widthTotal / count($columns)) * $tableWidth / $widthTotal;
            }
        }

        $tableTitle = trim((string) ($table['otsikko'] ?? ''));
        $displayRows = pdfTableRowsWithSummaries($table);
        $headerLines = 1;
        foreach ($columns as $columnIndex => $column) {
            $headerLines = max(
                $headerLines,
                pdfTableLineCount($pdf, (string) $column['nimi'], $columnWidths[$columnIndex] - 2, 'B')
            );
        }
        $numericColumns = [];
        foreach ($columns as $column) {
            $numericColumns[] = pdfTableColumnIsNumeric($table, $displayRows, (int) $column['id']);
        }
        $headerHeight = $headerLines * 5 + 2;
        // Pidä sarakeotsikot ja taulukon ensimmäinen tietorivi yhdessä. Koko
        // taulukon korkeutta ei varata etukäteen, jotta pitkä taulukko voi alkaa
        // osion otsikon ja tekstin jälkeen jo samalla sivulla.
        $tableStartHeight = $headerHeight + 2;
        if ($tableTitle !== '') {
            $tableStartHeight += 10;
        }
        $firstRowHeight = null;
        $summaryRowHeights = [];
        foreach ($displayRows as $displayRow) {
            $tableRow = $displayRow['row'];
            $rowLines = 1;
            foreach ($columns as $columnIndex => $column) {
                $cellText = ($displayRow['summary'] || !empty($displayRow['calculation'])) ? (string) ($displayRow['cells'][(int) $column['id']] ?? '') : (string) ($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '');
                $rowLines = max($rowLines, pdfTableLineCount($pdf, $cellText, $columnWidths[$columnIndex]));
            }
            $rowHeight = $rowLines * 5 + 2;
            if ($displayRow['summary']) { $rowHeight = (int) ceil($rowHeight * 1.3); }
            if (!empty($displayRow['calculation'])) { $rowHeight = $summaryRowHeights[(int) $displayRow['row']['calculation']['yhteenvetorivi_id']] ?? $rowHeight; }
            if ($displayRow['summary']) { $summaryRowHeights[(int) $tableRow['summary']['id']] = $rowHeight; }
            if ($firstRowHeight === null) { $firstRowHeight = $rowHeight; }
        }
        $pdf->ensureSpace($tableStartHeight + ($firstRowHeight ?? 0));
        if ($tableTitle !== '') {
            $pdf->Ln(4);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell(0, 6, pdfText($tableTitle), 0, 1);
        }
        $headerY = $pdf->GetY() + 2;
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(230, 235, 242);
        $x = $tableLeft;
        foreach ($columns as $columnIndex => $column) {
            $pdf->SetXY($x, $headerY);
            $headerColor = pdfHexColor((string) ($column['taustavari'] ?? '#ffffff'), [230, 235, 242]);
            $pdf->SetFillColor($headerColor[0], $headerColor[1], $headerColor[2]);
            $headerTextColor = pdfHexColor((string) ($column['fonttivari'] ?? '#232d3c'), [35, 45, 60]);
            $pdf->SetTextColor($headerTextColor[0], $headerTextColor[1], $headerTextColor[2]);
            $pdf->Cell($columnWidths[$columnIndex], $headerHeight, '', $tableBorder, 0, 'L', true);
            $headerLinesForCell = pdfTableLineCount($pdf, (string) $column['nimi'], $columnWidths[$columnIndex] - 2, 'B');
            $pdf->SetXY($x + 1, pdfTableCellTextY($headerY, $headerHeight, $headerLinesForCell, (string) ($table['tasaus_pysty'] ?? 'alas')));
            $headerAlignment = $columnIndex === 0 ? 'L' : pdfTableHorizontalAlignment((string) ($table['tasaus_vaaka'] ?? 'vasen'));
            pdfWriteMarkdownCell($pdf, (string) $column['nimi'], $columnWidths[$columnIndex] - 2, 5, 'B', $headerAlignment);
            $x += $columnWidths[$columnIndex];
        }
        $tableY = $headerY + $headerHeight;
        $pdf->SetXY($tableLeft, $tableY);
        $summaryRowHeights = [];
        foreach ($displayRows as $displayRow) {
            $tableRow = $displayRow['row'];
            $rowLines = 1;
            foreach ($columns as $columnIndex => $column) {
                $cellText = ($displayRow['summary'] || !empty($displayRow['calculation'])) ? (string) ($displayRow['cells'][(int) $column['id']] ?? '') : (string) ($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '');
                $rowLines = max($rowLines, pdfTableLineCount($pdf, $cellText, $columnWidths[$columnIndex]));
            }
            $rowHeight = $rowLines * 5 + 2;
            if ($displayRow['summary']) {
                $rowHeight = (int) ceil($rowHeight * 1.3);
            }
            if (!empty($displayRow['calculation'])) {
                $rowHeight = $summaryRowHeights[(int) $displayRow['row']['calculation']['yhteenvetorivi_id']] ?? $rowHeight;
            }
            if ($displayRow['summary']) {
                $summaryRowHeights[(int) $tableRow['summary']['id']] = $rowHeight;
            }
            $pdf->ensureSpace($rowHeight);
            $tableY = $pdf->GetY();
            $x = $tableLeft;
            $pdf->SetFont('Helvetica', ($displayRow['summary'] || !empty($displayRow['calculation'])) ? 'B' : '', 9);
            foreach ($columns as $columnIndex => $column) {
                $cellText = ($displayRow['summary'] || !empty($displayRow['calculation'])) ? (string) ($displayRow['cells'][(int) $column['id']] ?? '') : (string) ($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '');
                $pdf->SetXY($x, $tableY);
                $cellColor = pdfHexColor((string) ($column['taustavari'] ?? '#ffffff'));
                $pdf->SetFillColor($cellColor[0], $cellColor[1], $cellColor[2]);
                $textColor = pdfHexColor((string) ($column['fonttivari'] ?? '#232d3c'), [35, 45, 60]);
                $pdf->SetTextColor($textColor[0], $textColor[1], $textColor[2]);
                $pdf->Cell($columnWidths[$columnIndex], $rowHeight, '', $tableBorder, 0, 'L', true);
                $cellLines = pdfTableLineCount($pdf, $cellText, $columnWidths[$columnIndex] - 2, ($displayRow['summary'] || !empty($displayRow['calculation'])) ? 'B' : '');
                $cellTextY = pdfTableCellTextY($tableY, $rowHeight, $cellLines, (string) ($table['tasaus_pysty'] ?? 'alas'));
                $cellAlignment = $columnIndex === 0 ? 'L' : pdfTableHorizontalAlignment((string) ($table['tasaus_vaaka'] ?? 'vasen'));
                $pdf->SetXY($x + 1, $cellTextY);
                $cellNumber = pdfTableNumber($cellText);
                $styledCellNumber = pdfTableNumberRun($cellText);
                if ($cellNumber !== null || $styledCellNumber !== null) {
                    $numberStyle = ($displayRow['summary'] || !empty($displayRow['calculation'])) ? 'B' : (string) ($styledCellNumber['style'] ?? '');
                    $numberText = $styledCellNumber !== null ? (string) $styledCellNumber['text'] : $cellText;
                    $pdf->SetFont('Helvetica', $numberStyle, 9);
                    $pdf->Cell($columnWidths[$columnIndex] - 2, 5, pdfText($numberText), 0, 0, $cellAlignment);
                } else {
                    pdfWriteMarkdownCell($pdf, $cellText, $columnWidths[$columnIndex] - 2, 5, ($displayRow['summary'] || !empty($displayRow['calculation'])) ? 'B' : '', $cellAlignment);
                }
                $x += $columnWidths[$columnIndex];
            }
            $pdf->SetXY($tableLeft, $tableY + $rowHeight);
        }
        }
        $pdf->SetTextColor(35, 45, 60);
        if ($hideTable) {
            $pdf->Ln(4);
        }
        $pdf->Ln(6);
        $selectedColumnIds = array_map('intval', (array) ($chartColumnSelections[(int) $table['id']] ?? []));
        $chartPath = createTableChart($table, $chartType, $temporaryImages, $selectedColumnIds);
        if ($chartPath !== null) {
            $chartWidth = $pdf->GetPageWidth() - 40;
            $chartHeight = $chartWidth * 650 / 1200;
            $pdf->ensureSpace($chartHeight + 8);
            $chartY = $pdf->GetY();
            $pdf->Image($chartPath, 20, $chartY, $chartWidth, $chartHeight, 'PNG');
            $pdf->SetXY(20, $chartY + $chartHeight + 6);
        }
    }

    $mediaItems = $mediaByRow[(int) $row['id']] ?? [];
    $mediaCount = count($mediaItems);
    for ($mediaIndex = 0; $mediaIndex < $mediaCount; $mediaIndex++) {
        $item = $mediaItems[$mediaIndex];
        $imagePath = $item['tyyppi'] === 'kuva' ? localImagePath((string) $item['url']) : null;
        $isImage = isPdfImagePath($imagePath);
        if ($isImage) {
            $imageItems = [$item];
            if ($mediaIndex + 1 < $mediaCount) {
                $nextItem = $mediaItems[$mediaIndex + 1];
                $nextImagePath = $nextItem['tyyppi'] === 'kuva' ? localImagePath((string) $nextItem['url']) : null;
                if (isPdfImagePath($nextImagePath)) {
                    $imageItems[] = $nextItem;
                    $mediaIndex++;
                }
            }

            $columnWidth = $sectionImageWidth;
            $columnGap = 5;
            $pdf->ensureSpace(6 + $sectionImageHeight + 2 + 5);
            $rowY = $pdf->GetY() + 6;
            $imagePaths = [$imagePath];
            if (count($imageItems) === 2) {
                $imagePaths[] = $nextImagePath;
            }
            $imageHeight = $sectionImageHeight;
            foreach ($imagePaths as $imageItemIndex => $currentImagePath) {
                $croppedPath = cropPdfImage(
                    $currentImagePath,
                    $sectionImageAspect,
                    $temporaryImages,
                    decodeImageAnnotations((string) ($imageItems[$imageItemIndex]['merkinnat_json'] ?? ''))
                );
                if ($croppedPath === null) {
                    continue;
                }
                $imageX = 20 + $imageItemIndex * ($columnWidth + $columnGap);
                $pdf->Image($croppedPath, $imageX, $rowY, $columnWidth, $imageHeight);
            }

            $captionY = $rowY + $imageHeight + 2;
            $captionHeight = 0;
            foreach ($imageItems as $imageItemIndex => $imageItem) {
                if (!$imageItem['kuvateksti']) {
                    continue;
                }
                $imageX = 20 + $imageItemIndex * ($columnWidth + $columnGap);
                $pdf->SetXY($imageX, $captionY);
                $pdf->SetFont('Helvetica', 'I', 9);
                $captionStartY = $pdf->GetY();
                $pdf->MultiCell($columnWidth, 5, pdfText($imageItem['kuvateksti']), 0, 'L');
                $captionHeight = max($captionHeight, $pdf->GetY() - $captionStartY);
            }
            $pdf->SetXY(20, max($captionY + $captionHeight, $rowY + $imageHeight) + 6);
        } else {
            $youtubeThumbnail = $item['tyyppi'] === 'videolinkki'
                ? youtubeThumbnailPath((string) $item['url'], $temporaryImages)
                : null;
            if ($youtubeThumbnail) {
                $youtubeSize = @getimagesize($youtubeThumbnail);
                $videoWidth = $pdf->GetPageWidth() - 40;
                $videoHeight = $youtubeSize && $youtubeSize[0] > 0
                    ? $videoWidth * $youtubeSize[1] / $youtubeSize[0]
                    : $sectionImageHeight;
                $pdf->ensureSpace(6 + $videoHeight + 2 + 5);
                $videoY = $pdf->GetY() + 6;
                $pdf->Image($youtubeThumbnail, 20, $videoY, $videoWidth, $videoHeight, 'JPG', (string) $item['url']);
                $pdf->SetXY(20, $videoY + $videoHeight + 2);
                $pdf->SetFont('Helvetica', 'I', 9);
                $pdf->MultiCell($sectionImageWidth, 5, pdfText($item['kuvateksti'] ?: 'Avaa video YouTubessa'), 0, 'L');
                $pdf->SetXY(20, $pdf->GetY() + 6);
            } else {
                $pdf->Ln(6);
                $pdf->SetFont('Helvetica', 'I', 10);
                $pdf->MultiCell(0, 6, pdfText(ucfirst($item['tyyppi']) . ': ' . $item['url']), 0, 'L');
                if ($item['kuvateksti']) {
                    $pdf->SetFont('Helvetica', 'I', 9);
                    $pdf->MultiCell(0, 5, pdfText($item['kuvateksti']), 0, 'L');
                }
            }
        }
    }
}

if (!$attachmentsInserted) {
    // Tavallisesti liitteet tulevat kaikkien sisältöosioiden jälkeen.
    $attachmentPages = appendPdfAttachments($pdf, $attachments, $attachmentSectionNumbers);
    $hasAttachmentPages = $attachmentPages !== [];
}

// Allekirjoitus on raportin viimeisenä liitteiden jälkeen. Jos raportissa on
// liitteitä, allekirjoitus aloitetaan aina omalta sivultaan.
if ($showSignature) {
    $place = trim((string) ($report['laatimispaikka'] ?? ''));
    $date = (string) $GLOBALS['printDate'];
    $placeAndDate = $place !== '' ? $place . ', ' . $date : $date;
    $title = trim((string) ($report['laatija_titteli'] ?? ''));

    $closingTextHeight = 8 + 3 + 7 + ($title !== '' ? 7 : 0);
    $closingLineGap = 14;
    $closingFooterGap = 14;
    $signatureNeedsOwnPage = $hasAttachmentPages || ($hasSourceMaterialPages && !$rows && !$attachments);
    if ($signatureNeedsOwnPage) {
        $pdf->AddPage('P', 'A4');
    }
    $closingPageBreakY = $pdf->GetPageHeight() - 26;
    $closingTargetY = $closingPageBreakY - $closingFooterGap - $closingTextHeight;
    if ($signatureNeedsOwnPage) {
        $pdf->SetY($closingTargetY);
    } elseif ($pdf->GetY() + $closingLineGap > $closingTargetY) {
        $pdf->AddPage();
        $pdf->Ln($closingLineGap);
    } else {
        $pdf->SetY($closingTargetY);
    }
    $pdf->SetFont('Helvetica', '', 13);
    $pdf->Cell(0, 8, pdfText($placeAndDate), 0, 1, 'L');
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', '', 11);
    $pdf->Cell(0, 7, pdfText((string) $report['laatija_nimi']), 0, 1, 'L');
    if ($title !== '') {
        $pdf->Cell(0, 7, pdfText($title), 0, 1, 'L');
    }
}

foreach ($tocPageNumberPositions as $position) {
    if ($position['material_page'] !== null) {
        $targetPage = $sourceMaterialPages[(int) $position['material_page']] ?? '';
    } elseif ($position['attachment_index'] !== null) {
        $targetPage = $attachmentPages[(int) $position['attachment_index']] ?? '';
    } else {
        $targetPage = $sectionPages[(int) $position['section_index']] ?? 1;
    }
    if ($targetPage === '') {
        continue;
    }
    $pdf->writePageNumber(
        (int) $position['pdf_page'],
        (float) $position['x'],
        (float) $position['y'],
        (float) $position['width'],
        (string) $targetPage,
        (bool) $position['bold']
    );
}

$pdfContent = compressPdfIfNeeded($pdf->Output('S'));
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="raportti-' . $raporttiId . '.pdf"');
header('Content-Length: ' . strlen($pdfContent));
echo $pdfContent;
