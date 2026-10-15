<?php

require_once __DIR__ . '/funktiot.php';

startApplicationSession();
if (empty($_SESSION['laatijat_id'])) {
    http_response_code(404);
    exit;
}

try {
    $path = storedFilePath((string) ($_GET['polku'] ?? ''));
} catch (Throwable $error) {
    error_log('Tiedoston avaaminen epäonnistui: ' . $error->getMessage());
    $path = null;
}

if ($path === null) {
    http_response_code(404);
    exit;
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
$allowedMimeTypes = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'video/mp4', 'video/webm', 'video/ogg', 'application/pdf',
];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Cache-Control: private, max-age=3600');
readfile($path);
