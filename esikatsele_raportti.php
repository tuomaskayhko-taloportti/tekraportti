<?php

require_once __DIR__ . '/funktiot.php';
require_once __DIR__ . '/vendor/fpdf/fpdf.php';

startApplicationSession();
if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$raporttiId = (int) ($_GET['id'] ?? 0);

try {
    $db = getDatabaseConnection();
    $query = $db->prepare(
        'SELECT r.*, a.nimi AS asiakas_nimi, p.nimi AS projekti_nimi,
                l.nimi AS laatija_nimi, l.yritys AS laatija_yritys
         FROM raportit r
         INNER JOIN asiakkaat a ON a.id = r.asiakas_id AND a.laatijat_id = r.laatijat_id
         INNER JOIN laatijat l ON l.id = r.laatijat_id
         LEFT JOIN projektit p ON p.id = r.projekti_id AND p.laatijat_id = r.laatijat_id
         WHERE r.id = ? AND r.laatijat_id = ? LIMIT 1'
    );
    $query->bind_param('ii', $raporttiId, $laatijatId);
    $query->execute();
    $report = $query->get_result()->fetch_assoc();
    $query->close();
    if (!$report) {
        throw new RuntimeException('Raporttia ei löytynyt.');
    }

    $query = $db->prepare('SELECT id, otsikko, sisalto, jarjestys FROM raporttirivit WHERE raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId);
    $query->execute();
    $rows = $query->get_result()->fetch_all(MYSQLI_ASSOC);
    $query->close();

    $mediaByRow = [];
    $query = $db->prepare('SELECT raporttirivi_id, tyyppi, url, kuvateksti FROM raporttimedia WHERE raportti_id = ? AND laatijat_id = ? ORDER BY jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId);
    $query->execute();
    $mediaResult = $query->get_result();
    while ($item = $mediaResult->fetch_assoc()) {
        $mediaByRow[(int) $item['raporttirivi_id']][] = $item;
    }
    $query->close();

    $tablesByRow = [];
    $tables = [];
    $query = $db->prepare('SELECT id, raporttirivi_id, otsikko, jarjestys FROM raporttitaulukot WHERE raportti_id = ? AND laatijat_id = ? ORDER BY raporttirivi_id, jarjestys, id');
    $query->bind_param('ii', $raporttiId, $laatijatId);
    $query->execute();
    $tableResult = $query->get_result();
    while ($item = $tableResult->fetch_assoc()) {
        $item['sarakkeet'] = [];
        $item['rivit'] = [];
        $item['solut'] = [];
        $tables[(int) $item['id']] = $item;
    }
    $query->close();
    if ($tables) {
        $query = $db->prepare('SELECT id, taulukko_id, nimi, jarjestys FROM raporttitaulukko_sarakkeet WHERE laatijat_id = ? ORDER BY taulukko_id, jarjestys, id');
        $query->bind_param('i', $laatijatId);
        $query->execute();
        $result = $query->get_result();
        while ($item = $result->fetch_assoc()) {
            if (isset($tables[(int) $item['taulukko_id']])) {
                $tables[(int) $item['taulukko_id']]['sarakkeet'][] = $item;
            }
        }
        $query->close();

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

function pdfDate(string $value): string
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date ? $date->format('d.m.Y') : $value;
}

function pdfTableLineCount(FPDF $pdf, string $text, float $width): int
{
    $lines = 0;
    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [''] as $paragraph) {
        $words = preg_split('/\s+/', trim($paragraph)) ?: [''];
        $lineWidth = 0.0;
        $paragraphLines = 1;
        foreach ($words as $word) {
            $wordWidth = $pdf->GetStringWidth(pdfText($word . ' '));
            if ($lineWidth > 0 && $lineWidth + $wordWidth > max(1, $width - 2)) {
                $paragraphLines++;
                $lineWidth = $wordWidth;
            } else {
                $lineWidth += $wordWidth;
            }
        }
        $lines += $paragraphLines;
    }
    return max(1, $lines);
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
        && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'], true);
}

function cropPdfImage(string $path, float $targetAspect, array &$temporaryImages): ?string
{
    $imageSize = @getimagesize($path);
    if (!$imageSize || $imageSize[0] < 1 || $imageSize[1] < 1 || $targetAspect <= 0) {
        return null;
    }

    $source = match ($imageSize[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_GIF => @imagecreatefromgif($path),
        default => false,
    };
    if (!$source) {
        return null;
    }

    $sourceWidth = $imageSize[0];
    $sourceHeight = $imageSize[1];
    $sourceAspect = $sourceWidth / $sourceHeight;
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

    $outputWidth = 1200;
    $outputHeight = max(1, (int) round($outputWidth / $targetAspect));
    $output = imagecreatetruecolor($outputWidth, $outputHeight);
    $white = imagecolorallocate($output, 255, 255, 255);
    imagefill($output, 0, 0, $white);
    imagecopyresampled($output, $source, 0, 0, $cropX, $cropY, $outputWidth, $outputHeight, $cropWidth, $cropHeight);

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

class TekraPdf extends FPDF
{
    public function ensureSpace(float $height): void
    {
        if ($this->GetY() + $height > $this->PageBreakTrigger) {
            $this->AddPage();
        }
    }

    public function Header(): void
    {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(90, 100, 115);
        $this->Cell(0, 8, pdfText('TEKRAPORTTI – Esikatselu'), 0, 1, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(110, 110, 110);
        $this->Cell(0, 10, pdfText('Sivu ') . $this->PageNo(), 0, 0, 'R');
    }
}

$pdf = new TekraPdf();
$pdf->SetTitle(pdfText($report['otsikko']));
$pdf->SetAuthor(pdfText($report['laatija_nimi']));
$pdf->SetMargins(20, 20, 20);
$pdf->SetAutoPageBreak(true, 20);
$temporaryImages = [];
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
$coverPath = localImagePath((string) ($report['kansikuva'] ?? ''));
if ($coverPath && in_array(strtolower(pathinfo($coverPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif'], true)) {
    $imageSize = @getimagesize($coverPath);
    if ($imageSize) {
        $maxImageWidth = 170;
        $maxImageHeight = 80;
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
$pdf->SetFont('Helvetica', 'B', 24);
$pdf->MultiCell(0, 12, pdfText($report['otsikko']), 0, 'C');
$pdf->Ln(8);
$pdf->SetFont('Helvetica', '', 13);
$pdf->Cell(0, 8, pdfText($report['asiakas_nimi']), 0, 1, 'C');
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
$pdf->SetFont('Helvetica', '', 12);
foreach ($rows as $index => $row) {
    $title = ($index + 1) . '. ' . $row['otsikko'];
    $pageNumber = (string) ($index + 3);
    $titleText = pdfText($title);
    $pageNumberText = pdfText($pageNumber);
    $contentWidth = $pdf->GetPageWidth() - 40;
    $titleWidth = $pdf->GetStringWidth($titleText);
    $pageNumberWidth = $pdf->GetStringWidth($pageNumberText);
    $spaceWidth = $pdf->GetStringWidth(' ');
    $pageNumberGap = 3;
    $dotsWidth = max(0, $contentWidth - $titleWidth - $pageNumberWidth - $spaceWidth - $pageNumberGap);
    $dotWidth = $pdf->GetStringWidth('.');
    $dots = $dotWidth > 0 ? str_repeat('.', (int) floor($dotsWidth / $dotWidth)) : '';
    $pdf->Cell($titleWidth + $spaceWidth, 8, $titleText . ' ', 0, 0, 'L');
    $pdf->Cell($dotsWidth, 8, $dots, 0, 0, 'L');
    $pdf->Cell($pageNumberGap, 8, '', 0, 0, 'L');
    $pdf->Cell($pageNumberWidth, 8, $pageNumberText, 0, 1, 'R');
}
if (!$rows) {
    $pdf->Cell(0, 8, pdfText('Ei vielä lisättyjä osioita.'), 0, 1);
}

// Raporttiosiot.
foreach ($rows as $index => $row) {
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 17);
    $pdf->MultiCell(0, 10, pdfText(($index + 1) . '. ' . $row['otsikko']), 0, 'L');
    $pdf->Ln(5);
    $pdf->SetFont('Helvetica', '', 11);
    $content = trim((string) $row['sisalto']);
    $pdf->MultiCell(0, 6, pdfText($content !== '' ? $content : 'Ei tekstisisältöä.'), 0, 'L');

    // Osioon liitetyt taulukot.
    foreach ($tablesByRow[(int) $row['id']] ?? [] as $table) {
        $columns = $table['sarakkeet'];
        if (!$columns) {
            continue;
        }
        $tableWidth = $pdf->GetPageWidth() - 40;
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
        $headerHeight = 8;
        $estimatedHeight = $headerHeight + 4;
        foreach ($table['rivit'] as $tableRow) {
            $rowLines = 1;
            foreach ($columns as $columnIndex => $column) {
                $cellText = (string) ($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '');
                $rowLines = max($rowLines, pdfTableLineCount($pdf, $cellText, $columnWidths[$columnIndex]));
            }
            $estimatedHeight += $rowLines * 5 + 2;
        }
        $pdf->ensureSpace($estimatedHeight);
        if ($tableTitle !== '') {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell(0, 6, pdfText($tableTitle), 0, 1);
        }
        $headerY = $pdf->GetY() + 2;
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(230, 235, 242);
        $x = 20;
        foreach ($columns as $columnIndex => $column) {
            $pdf->SetXY($x, $headerY);
            $pdf->Cell($columnWidths[$columnIndex], $headerHeight, pdfText((string) $column['nimi']), 1, 0, 'L', true);
            $x += $columnWidths[$columnIndex];
        }
        $tableY = $headerY + $headerHeight;
        $pdf->SetXY(20, $tableY);
        $pdf->SetFillColor(255, 255, 255);
        foreach ($table['rivit'] as $tableRow) {
            $rowLines = 1;
            foreach ($columns as $columnIndex => $column) {
                $cellText = (string) ($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '');
                $rowLines = max($rowLines, pdfTableLineCount($pdf, $cellText, $columnWidths[$columnIndex]));
            }
            $rowHeight = $rowLines * 5 + 2;
            $pdf->ensureSpace($rowHeight);
            $tableY = $pdf->GetY();
            $x = 20;
            $pdf->SetFont('Helvetica', '', 9);
            foreach ($columns as $columnIndex => $column) {
                $cellText = (string) ($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '');
                $pdf->SetXY($x, $tableY);
                $pdf->Cell($columnWidths[$columnIndex], $rowHeight, '', 1);
                $pdf->SetXY($x + 1, $tableY + 1);
                $pdf->MultiCell($columnWidths[$columnIndex] - 2, 5, pdfText($cellText), 0, 'L');
                $x += $columnWidths[$columnIndex];
            }
            $pdf->SetXY(20, $tableY + $rowHeight);
        }
        $pdf->Ln(6);
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
                $croppedPath = cropPdfImage($currentImagePath, $sectionImageAspect, $temporaryImages);
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

$pdf->Output('I', 'raportti-' . $raporttiId . '.pdf');
