<?php
// Taruh di C:\Docker\PHP\www\api.php (sefolder dengan obrolan.txt)
// Format file: 1 objek JSON per baris (NDJSON), sama seperti versi web.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
$file = __DIR__ . '/obrolan.txt';
if (!file_exists($file)) file_put_contents($file, '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $name    = trim($in['name'] ?? '');
    $message = trim($in['message'] ?? '');
    $cid     = preg_replace('/[^a-z0-9]/i', '', $in['cid'] ?? '');
    if ($name === '' || $message === '') {
        http_response_code(400);
        echo json_encode(['error' => 'name/message kosong']);
        exit;
    }
    $row = [
        'name'    => mb_substr($name, 0, 30),
        'message' => mb_substr($message, 0, 1000),
        'ip'      => $_SERVER['REMOTE_ADDR'],
        'cid'     => $cid,
        'time'    => time(),
    ];
    $fp = fopen($file, 'a+');
    flock($fp, LOCK_EX);
    clearstatcache(true, $file);
    $prefix = '';
    if (filesize($file) > 0) {
        fseek($fp, -1, SEEK_END);
        if (fread($fp, 1) !== "\n") $prefix = "\n";
    }
    fwrite($fp, $prefix . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    echo json_encode(['ok' => true]);
    exit;
}

// GET: ubah baris-baris NDJSON jadi array JSON
$out = [];
foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $d = json_decode($line, true);
    if (is_array($d)) $out[] = $d;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
