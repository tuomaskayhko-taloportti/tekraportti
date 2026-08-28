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

class TekraPdf extends FPDF
{
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
$sectionImageHeight = 68;
$sectionImageAspect = $sectionImageWidth / $sectionImageHeight;
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
    $dots = str_repeat('.', max(4, 68 - mb_strlen($title)));
    $pdf->Cell(0, 8, pdfText($title . $dots . ($index + 3)), 0, 1);
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

$pdf->Output('I', 'raportti-' . $raporttiId . '.pdf');
