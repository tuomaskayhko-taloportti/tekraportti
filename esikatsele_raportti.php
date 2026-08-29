<?php

require_once __DIR__ . '/funktiot.php';
require_once __DIR__ . '/vendor/fpdf/fpdf.php';

startApplicationSession();
if (!isset($_SESSION['laatijat_id'])) {
    header('Location: index.php');
    exit;
}

$laatijatId = (int) $_SESSION['laatijat_id'];
$currentLaatijaId = $laatijatId;
$raporttiId = (int) ($_GET['id'] ?? 0);

try {
    $db = getDatabaseConnection();
    $query = $db->prepare(
        'SELECT r.*, a.nimi AS asiakas_nimi, a.yritys AS asiakas_yritys,
                a.osoite AS asiakas_osoite, a.postinro AS asiakas_postinro,
                a.postitmp AS asiakas_postitmp, p.nimi AS projekti_nimi,
                l.nimi AS laatija_nimi, l.titteli AS laatija_titteli
         FROM raportit r
         INNER JOIN asiakkaat a ON a.id = r.asiakas_id AND a.laatijat_id = r.laatijat_id
         INNER JOIN laatijat l ON l.id = r.laatijat_id
         LEFT JOIN projektit p ON p.id = r.projekti_id AND p.laatijat_id = r.laatijat_id
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

function chartFontPath(bool $bold = false): ?string
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
    ];
    foreach ($paths[$bold ? 'bold' : 'regular'] as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    return null;
}

function chartText($image, int $size, int $x, int $y, string $text, int $color, bool $bold = false, int $angle = 0): void
{
    $font = chartFontPath($bold);
    if ($font !== null && function_exists('imagettftext')) {
        imagettftext($image, $size, $angle, $x, $y + $size, $color, $font, $text);
        return;
    }
    imagestring($image, 5, $x, $y, pdfText($text), $color);
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

function createTableChart(array $table, string $chartType, array &$temporaryImages): ?string
{
    if (!in_array($chartType, ['pie', 'bar', 'line'], true) || count($table['sarakkeet']) < 2 || !$table['rivit']) {
        return null;
    }

    $series = [];
    foreach (array_slice($table['sarakkeet'], 1) as $column) {
        $values = [];
        foreach ($table['rivit'] as $tableRow) {
            $cell = (string) ($table['solut'][(int) $tableRow['id']][(int) $column['id']] ?? '');
            $values[] = chartNumber($cell);
        }
        if (count(array_filter($values, static fn ($value): bool => $value !== null)) > 0) {
            $series[] = ['name' => (string) $column['nimi'], 'values' => $values];
        }
    }
    if (!$series) {
        return null;
    }

    $width = 1200;
    $height = 650;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $dark = imagecolorallocate($image, 35, 45, 60);
    imagefill($image, 0, 0, $white);
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
        $values = array_map(static fn ($value): float => max(0, (float) ($value ?? 0)), $series[0]['values']);
        $total = array_sum($values);
        if ($total <= 0) {
            imagedestroy($image);
            return null;
        }
        $centerX = 390;
        $centerY = 315;
        $diameter = 480;
        $start = 0.0;
        foreach ($values as $index => $value) {
            $angle = $value / $total * 360;
            imagefilledarc($image, $centerX, $centerY, $diameter, $diameter, $start, $start + $angle, $palette[$index % count($palette)], IMG_ARC_PIE);
            $start += $angle;
        }
        $legendY = 100;
        chartText($image, 24, 730, 55, $series[0]['name'], $dark, true);
        foreach ($labels as $index => $label) {
            imagefilledrectangle($image, 730, $legendY, 750, $legendY + 20, $palette[$index % count($palette)]);
            chartText($image, 20, 765, $legendY - 1, $label, $dark);
            $legendY += 32;
        }
    } else {
        $left = 140;
        $right = 1130;
        $top = 75;
        $bottom = 545;
        $maxValue = 0.0;
        foreach ($series as $item) {
            foreach ($item['values'] as $value) {
                $maxValue = max($maxValue, (float) ($value ?? 0));
            }
        }
        $maxValue = $maxValue > 0 ? $maxValue * 1.1 : 1;
        imageline($image, $left, $top, $left, $bottom, $dark);
        imageline($image, $left, $bottom, $right, $bottom, $dark);
        for ($tick = 0; $tick <= 5; $tick++) {
            $y = $bottom - ($bottom - $top) * $tick / 5;
            $value = $maxValue * $tick / 5;
            $grid = imagecolorallocate($image, 220, 225, 232);
            imageline($image, $left, (int) $y, $right, (int) $y, $grid);
            chartText($image, 18, 10, (int) $y - 10, number_format($value, 1, ',', ' '), $dark);
        }
        $tableTitle = trim((string) ($table['otsikko'] ?? ''));
        if ($tableTitle !== '') {
            chartText($image, $chartType === 'line' ? 20 : 24, 10, 15, $tableTitle, $dark, true);
        }
        $count = max(1, count($labels));
        if ($chartType === 'bar') {
            $groupWidth = ($right - $left) / $count;
            $barWidth = min(48, max(8, $groupWidth / max(1, count($series) + 1)));
            foreach ($labels as $index => $label) {
                $groupX = $left + $groupWidth * $index + $groupWidth / 2;
                foreach ($series as $seriesIndex => $item) {
                    $value = max(0, (float) ($item['values'][$index] ?? 0));
                    $x = $groupX + ($seriesIndex - (count($series) - 1) / 2) * $barWidth;
                    $barTop = $bottom - ($bottom - $top) * $value / $maxValue;
                    imagefilledrectangle($image, (int) ($x - $barWidth / 2), (int) $barTop, (int) ($x + $barWidth / 2), $bottom, $palette[$seriesIndex % count($palette)]);
                }
                foreach (chartLabelLines($label) as $lineIndex => $labelLine) {
                    chartTextCentered($image, 16, $groupX, $bottom + 12 + $lineIndex * 20, $labelLine, $dark);
                }
            }
        } else {
            foreach ($series as $seriesIndex => $item) {
                $previousX = null;
                $previousY = null;
                foreach ($labels as $index => $label) {
                    $value = max(0, (float) ($item['values'][$index] ?? 0));
                    $x = $count === 1 ? ($left + $right) / 2 : $left + ($right - $left) * $index / ($count - 1);
                    $y = $bottom - ($bottom - $top) * $value / $maxValue;
                    if ($previousX !== null) {
                        imageline($image, (int) $previousX, (int) $previousY, (int) $x, (int) $y, $palette[$seriesIndex % count($palette)]);
                    }
                    imagefilledellipse($image, (int) $x, (int) $y, 12, 12, $palette[$seriesIndex % count($palette)]);
                    $previousX = $x;
                    $previousY = $y;
                    if ($seriesIndex === 0) {
                        foreach (chartLabelLines($label) as $lineIndex => $labelLine) {
                            chartTextCentered($image, 16, $x, $bottom + 12 + $lineIndex * 20, $labelLine, $dark);
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
$chartSelections = is_array($_GET['kaavio'] ?? null) ? $_GET['kaavio'] : [];
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
        $chartType = (string) ($chartSelections[(int) $table['id']] ?? '');
        $chartPath = createTableChart($table, $chartType, $temporaryImages);
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

// Laatimispaikka ja laatijan tiedot viimeisenä tekstiryhmänä.
$place = trim((string) ($report['laatimispaikka'] ?? ''));
$date = pdfDate((string) $report['raporttipvm']);
$placeAndDate = $place !== '' ? $place . ', ' . $date : $date;
$title = trim((string) ($report['laatija_titteli'] ?? ''));

$closingTextHeight = 8 + 3 + 7 + ($title !== '' ? 7 : 0);
$pdf->ensureSpace(8 + $closingTextHeight);
$pdf->Ln(8);
$pdf->SetFont('Helvetica', '', 13);
$pdf->Cell(0, 8, pdfText($placeAndDate), 0, 1, 'L');
$pdf->Ln(3);
$pdf->SetFont('Helvetica', '', 11);
$pdf->Cell(0, 7, pdfText((string) $report['laatija_nimi']), 0, 1, 'L');
if ($title !== '') {
    $pdf->Cell(0, 7, pdfText($title), 0, 1, 'L');
}

$pdf->Output('I', 'raportti-' . $raporttiId . '.pdf');
