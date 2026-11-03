<?php

// ==========================================
// PENGATURAN
// ==========================================

$file       = __DIR__ . '/links.txt';
$onlineFile = sys_get_temp_dir() . '/local_linktree_online.json';

// Database obrolan (satu pesan per baris, format JSON)
define('CHAT_FILE', __DIR__ . '/obrolan.txt');

// Pesan lebih lama dari ini (jam) otomatis dihapus. Isi 0 = tidak pernah dihapus.
define('CHAT_RETENTION_HOURS', 48);

// Client dianggap offline jika tidak ada sinyal selama ... detik
define('ONLINE_TIMEOUT', 45);


// ==========================================
// FUNGSI BANTU
// ==========================================

// Ambil IP client. Jika ada reverse proxy, header forwarded dipakai lebih dulu.
function clientIP() {

    $candidates = [];

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $candidates[] = trim($part);
        }
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_X_REAL_IP']);
    }

    $candidates[] = $_SERVER['REMOTE_ADDR'] ?? '';

    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'Unknown';
}

// IP internal Docker (172.16.0.0 - 172.31.255.255) bukan IP client asli
function isDockerIP($ip) {

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    $long = ip2long($ip);

    return $long >= ip2long('172.16.0.0') && $long <= ip2long('172.31.255.255');
}

// Tebak perangkat & browser dari User-Agent
function deviceLabel() {

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (stripos($ua, 'Android') !== false)                 $os = 'Android';
    elseif (preg_match('/iPhone|iPad|iPod/i', $ua))        $os = 'iOS';
    elseif (stripos($ua, 'Windows') !== false)             $os = 'Windows';
    elseif (stripos($ua, 'Mac OS') !== false)              $os = 'Mac';
    elseif (stripos($ua, 'Linux') !== false)               $os = 'Linux';
    else                                                   $os = 'Perangkat';

    if (stripos($ua, 'Edg') !== false)                     $browser = 'Edge';
    elseif (stripos($ua, 'OPR') !== false || stripos($ua, 'Opera') !== false) $browser = 'Opera';
    elseif (stripos($ua, 'Firefox') !== false)             $browser = 'Firefox';
    elseif (stripos($ua, 'Chrome') !== false)              $browser = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false)              $browser = 'Safari';
    else                                                   $browser = 'Browser';

    return $os . ' • ' . $browser;
}


// ==========================================
// SISTEM ONLINE CLIENT
// Kunci = ID unik browser, bukan IP
// (di Docker Desktop IP semua client bisa sama)
// ==========================================

function onlineData($file, $clientId = null, $name = '') {

    $fp = @fopen($file, 'c+');

    if (!$fp) {
        return [];
    }

    flock($fp, LOCK_EX);

    rewind($fp);
    $content = stream_get_contents($fp);

    $clients = json_decode($content ?: '{}', true);

    if (!is_array($clients)) {
        $clients = [];
    }

    $now = time();

    // Hapus client tidak aktif
    foreach ($clients as $id => $info) {
        if (!is_array($info) || !isset($info['last']) || ($now - (int)$info['last']) > ONLINE_TIMEOUT) {
            unset($clients[$id]);
        }
    }

    // Update client ini
    if ($clientId !== null) {
        $clients[$clientId] = [
            'ip'     => clientIP(),
            'device' => deviceLabel(),
            'name'   => $name,
            'last'   => $now
        ];
    }

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($clients, JSON_UNESCAPED_UNICODE));
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    return $clients;
}


// ==========================================
// SISTEM OBROLAN (disimpan di obrolan.txt)
// ==========================================

function chatCutoff() {
    return CHAT_RETENTION_HOURS > 0 ? time() - (CHAT_RETENTION_HOURS * 3600) : 0;
}

function chatParseLines($raw) {

    $messages = [];
    $cutoff = chatCutoff();

    foreach (preg_split('/\r\n|\r|\n/', (string)$raw) as $line) {

        $line = trim($line);

        if ($line === '') {
            continue;
        }

        $m = json_decode($line, true);

        if (
            is_array($m) &&
            isset($m['name'], $m['message'], $m['time']) &&
            is_numeric($m['time']) &&
            (int)$m['time'] >= $cutoff
        ) {
            $messages[] = $m;
        }
    }

    return $messages;
}

// Baca riwayat (tanpa menulis ke file)
function chatRead() {

    if (!is_file(CHAT_FILE)) {
        return [];
    }

    $raw = @file_get_contents(CHAT_FILE);

    if ($raw === false) {
        return [];
    }

    return chatParseLines($raw);
}

// Tambah pesan baru + bersihkan pesan lama. Return array pesan, atau false jika gagal.
function chatAppend($newMessage, &$error = null) {

    $fp = @fopen(CHAT_FILE, 'c+');

    if (!$fp) {
        $error = 'Tidak bisa menulis ke obrolan.txt. Cek izin folder/file (lihat catatan Docker).';
        return false;
    }

    flock($fp, LOCK_EX);

    rewind($fp);
    $raw = stream_get_contents($fp);

    $messages = chatParseLines($raw);
    $messages[] = $newMessage;

    $out = '';
    foreach ($messages as $m) {
        $out .= json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    rewind($fp);
    ftruncate($fp, 0);
    $written = fwrite($fp, $out);
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    if ($written === false) {
        $error = 'Gagal menyimpan pesan ke obrolan.txt.';
        return false;
    }

    return $messages;
}


// Bersihkan ID client dari input
function cleanCid($value) {
    return substr(preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$value), 0, 64);
}

// Siapkan pesan untuk dikirim ke browser:
// tandai pesan milik client ini ("mine"), sembunyikan ID & IP Docker
function chatForClient($messages, $cid) {

    $result = [];

    foreach ($messages as $m) {

        $ip = $m['ip'] ?? '';

        $result[] = [
            'name'    => $m['name'],
            'message' => $m['message'],
            'time'    => $m['time'],
            'ip'      => isDockerIP($ip) ? '' : $ip,
            'mine'    => ($cid !== '' && ($m['cid'] ?? '') === $cid)
        ];
    }

    return $result;
}


// ==========================================
// AJAX
// ==========================================

if (isset($_GET['action'])) {

    ini_set('display_errors', '0');

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    // Heartbeat: client mengirim sinyal "saya masih online"
    if ($_GET['action'] === 'heartbeat') {

        $cid = preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['cid'] ?? '');
        $cid = substr($cid, 0, 64);

        if (strlen($cid) < 8) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID client tidak valid']);
            exit;
        }

        $name = mb_substr(trim($_GET['name'] ?? ''), 0, 30, 'UTF-8');

        $clients = onlineData($onlineFile, $cid, $name);

        echo json_encode([
            'success' => true,
            'count'   => count($clients)
        ]);

        exit;
    }

    // Daftar client online
    if ($_GET['action'] === 'online') {

        $clients = onlineData($onlineFile);

        $list = [];
        foreach ($clients as $id => $info) {
            $list[] = [
                'id'     => $id,
                'ip'     => isDockerIP($info['ip'] ?? '') ? '' : ($info['ip'] ?? ''),
                'device' => $info['device'] ?? '',
                'name'   => $info['name'] ?? ''
            ];
        }

        echo json_encode([
            'count'   => count($list),
            'clients' => $list
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // Ambil riwayat obrolan
    if ($_GET['action'] === 'chat_list') {

        echo json_encode([
            'success'  => true,
            'messages' => chatForClient(chatRead(), cleanCid($_GET['cid'] ?? ''))
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    // Kirim pesan obrolan
    if ($_GET['action'] === 'chat_send') {

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Gunakan POST']);
            exit;
        }

        $name    = trim($_POST['name'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($name === '' || $message === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nama dan pesan wajib diisi']);
            exit;
        }

        $cid = cleanCid($_POST['cid'] ?? '');

        $newMessage = [
            'name'    => mb_substr($name, 0, 30, 'UTF-8'),
            'message' => mb_substr($message, 0, 500, 'UTF-8'),
            'ip'      => clientIP(),
            'cid'     => $cid,
            'time'    => time()
        ];

        $error = null;
        $messages = chatAppend($newMessage, $error);

        if ($messages === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'success'  => true,
            'messages' => chatForClient($messages, $cid)
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    echo json_encode(['error' => 'Invalid action']);
    exit;
}


// ==========================================
// MEMBACA LINKS.TXT
// ==========================================

$categories = [];
$currentCategory = "Link Lainnya";

if (file_exists($file)) {

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {

        $line = trim($line);

        if (strpos($line, '//') === 0) {

            $currentCategory = trim(substr($line, 2));

            if ($currentCategory === '') {
                $currentCategory = "Link Lainnya";
            }

            continue;
        }

        $parts = explode('|', $line, 3);

        if (count($parts) >= 2) {

            $name  = trim($parts[0]);
            $url   = trim($parts[1]);
            $image = isset($parts[2]) ? trim($parts[2]) : '';

            if (
                $name !== '' &&
                filter_var($url, FILTER_VALIDATE_URL) &&
                in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'])
            ) {

                $categories[$currentCategory][] = [
                    'name'  => $name,
                    'url'   => $url,
                    'image' => $image
                ];
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Portal Link Kantor V4</title>

<style>

* {
    box-sizing: border-box;
}

/* ======================================
   POPUP CLIENT
====================================== */

.online-popup {
    display: none;

    position: absolute;
    top: 48px;
    left: 0;

    width: 260px;
    max-width: calc(100vw - 30px);
    max-height: 60vh;
    overflow-y: auto;

    background: white;
    color: #243b55;

    border-radius: 12px;
    padding: 15px;

    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}

.online-popup.show {
    display: block;
}

.popup-title {
    font-size: 14px;
    font-weight: bold;

    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}

.client-item {
    padding: 9px 0;
    border-bottom: 1px solid #eee;
    overflow-wrap: anywhere;
}

.client-name {
    font-size: 13px;
    font-weight: bold;
}

.client-meta {
    font-size: 11px;
    color: #777;
    margin-top: 2px;
}

.client-you {
    color: #16a34a;
    font-weight: bold;
}

.online-empty {
    font-size: 12px;
    color: #777;
    padding-top: 10px;
}

/* ======================================
   ONLINE BADGE
====================================== */

.online-container {
    position: fixed !important;
    top: 15px;
    left: 15px;
    z-index: 99999;
}

.online-badge {
    background: #16a34a;
    color: white;

    padding: 11px 17px;
    border-radius: 30px;

    font-size: 13px;
    font-weight: bold;

    cursor: pointer;
    border: none;

    box-shadow: 0 4px 15px rgba(0,0,0,0.3);

    transition: 0.2s;
}

.online-badge:hover {
    background: #15803d;
    transform: translateY(-2px);
}

/* ======================================
   TOMBOL DAN POPUP OBROLAN - BIRU
====================================== */

.chat-container {
    position: fixed;
    left: 15px;
    bottom: 15px;
    z-index: 99998;
    font-family: Arial, sans-serif;
}

.chat-badge {
    background: #2563eb;
    color: white;
    padding: 11px 18px;
    border: none;
    border-radius: 30px;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
    box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    transition: 0.2s;
}

.chat-badge:hover {
    background: #1d4ed8;
    transform: translateY(-2px);
}

.chat-popup {
    display: none;
    position: absolute;
    left: 0;
    bottom: 52px;
    width: 310px;
    max-width: calc(100vw - 30px);
    height: 410px;
    max-height: calc(100vh - 100px);
    background: #ffffff;
    color: #243b55;
    border-radius: 14px;
    box-shadow: 0 8px 25px rgba(0,0,0,0.3);
    overflow: hidden;
    flex-direction: column;
}

.chat-popup.show {
    display: flex;
}

.chat-popup-header {
    background: #2563eb;
    color: white;
    padding: 13px 15px;
    font-size: 14px;
    font-weight: bold;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chat-close {
    background: transparent;
    border: none;
    color: white;
    font-size: 21px;
    cursor: pointer;
    line-height: 1;
}

.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    background: #f3f6fb;
}

.chat-message {
    background: white;
    border: 1px solid #e3eaf5;
    border-radius: 10px 10px 10px 2px;
    padding: 8px 10px;
    margin: 0 auto 9px 0;
    max-width: 88%;
    width: fit-content;
    overflow-wrap: anywhere;
}

/* Pesan sendiri: di kanan */
.chat-message.mine {
    background: #dbeafe;
    border-color: #bfd6fb;
    border-radius: 10px 10px 2px 10px;
    margin: 0 0 9px auto;
}

.chat-message.mine .chat-message-info {
    text-align: right;
}

.chat-message-name {
    color: #2563eb;
    font-size: 12px;
    font-weight: bold;
    margin-bottom: 4px;
}

.chat-message-text {
    color: #243b55;
    font-size: 13px;
    white-space: pre-wrap;
}

.chat-message-info {
    color: #8a96a8;
    font-size: 10px;
    margin-top: 5px;
}

.chat-empty {
    color: #7b8794;
    text-align: center;
    font-size: 12px;
    padding: 18px 5px;
}

.chat-form {
    padding: 10px;
    background: white;
    border-top: 1px solid #e5eaf1;
}

.chat-input {
    width: 100%;
    border: 1px solid #d5deeb;
    border-radius: 8px;
    padding: 9px 10px;
    margin-bottom: 7px;
    font-size: 13px;
    outline: none;
}

.chat-input:focus {
    border-color: #2563eb;
}

.chat-send-row {
    display: flex;
    gap: 7px;
}

.chat-text-input {
    flex: 1;
    min-width: 0;
    resize: none;
    height: 38px;
    border: 1px solid #d5deeb;
    border-radius: 8px;
    padding: 9px;
    font-family: Arial, sans-serif;
    font-size: 13px;
    outline: none;
}

.chat-send-button {
    border: none;
    border-radius: 8px;
    background: #2563eb;
    color: white;
    padding: 0 13px;
    font-weight: bold;
    cursor: pointer;
}

.chat-send-button:hover {
    background: #1d4ed8;
}

.chat-send-button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.chat-status {
    min-height: 14px;
    color: #dc2626;
    font-size: 11px;
    padding-top: 4px;
}

/* ======================================
   HALAMAN UTAMA
====================================== */

body {
    margin: 0;
    padding: 40px 15px;
    font-family: Arial, sans-serif;

    background-image: url('http://192.168.60.228:8070/gambar/background.jpeg');
    background-color: #243b55;
    background-size: cover;
    background-position: center center;
    background-repeat: no-repeat;
    background-attachment: fixed;

    color: white;
    min-height: 100vh;
}

.container {
    max-width: 500px;
    margin: auto;
}

.profile {
    text-align: center;
    margin-bottom: 40px;
}

.avatar {
    width: 95px;
    height: 95px;
    background: white;
    color: #243b55;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: auto;
    font-size: 32px;
    font-weight: bold;
}

.category {
    margin-bottom: 32px;
}

.category-title {
    font-size: 17px;
    font-weight: bold;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.25);
}

.link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    background: white;
    color: #243b55;
    text-decoration: none;
    padding: 12px 15px;
    border-radius: 12px;
    margin-bottom: 12px;
    font-weight: bold;
    min-height: 62px;
    transition: 0.2s;
}

.link:hover {
    background: #dce8f5;
    transform: translateY(-3px);
}

.link-name {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}

.link-image {
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    object-fit: contain;
    border-radius: 6px;
}

.footer {
    text-align: center;
    margin-top: 40px;
    color: #aab8c7;
    font-size: 12px;
}

</style>

</head>

<body>


<!-- ONLINE STATUS -->

<div class="online-container">

    <button class="online-badge" type="button" onclick="toggleOnline()">
        🟢 Online : <span id="onlineCount">0</span>
    </button>

    <div class="online-popup" id="onlinePopup">

        <div class="popup-title">
            CLIENT ONLINE
        </div>

        <div id="clientList"></div>

    </div>

</div>


<!-- TOMBOL DAN POPUP OBROLAN -->
<div class="chat-container" id="chatContainer">

    <button class="chat-badge" type="button" onclick="toggleChat()">
        💬 Obrolan
    </button>

    <div class="chat-popup" id="chatPopup">

        <div class="chat-popup-header">
            <span>💬 Obrolan Aja</span>
            <button class="chat-close" type="button" onclick="toggleChat(false)" aria-label="Tutup">&times;</button>
        </div>

        <div class="chat-messages" id="chatMessages">
            <div class="chat-empty">Memuat obrolan...</div>
        </div>

        <form class="chat-form" id="chatForm">
            <input
                class="chat-input"
                type="text"
                id="chatName"
                maxlength="30"
                placeholder="Nama kamu"
                autocomplete="name"
                required
            >

            <div class="chat-send-row">
                <textarea
                    class="chat-text-input"
                    id="chatText"
                    maxlength="500"
                    placeholder="Tulis pesan..."
                    required
                ></textarea>
                <button class="chat-send-button" id="chatSendButton" type="submit">KIRIM</button>
            </div>

            <div class="chat-status" id="chatStatus"></div>
        </form>

    </div>
</div>


<!-- HALAMAN UTAMA -->

<div class="container">

    <div class="profile">

        <div class="avatar">IT</div>

        <h2>PORTAL LINK IT SUPPORT</h2>

        <p>Pusat akses aplikasi dan formulir pelayanan <br/>
			by : Samman
		</p>

    </div>


    <?php foreach ($categories as $category => $links): ?>

        <div class="category">

            <div class="category-title">
                <?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>
            </div>

            <?php foreach ($links as $link): ?>

                <a class="link"
                   href="<?= htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank"
                   rel="noopener noreferrer">

                    <span class="link-name">
                        <?= htmlspecialchars($link['name'], ENT_QUOTES, 'UTF-8') ?>
                    </span>

                    <?php if ($link['image'] !== ''): ?>

                        <img
                            class="link-image"
                            src="<?= htmlspecialchars($link['image'], ENT_QUOTES, 'UTF-8') ?>"
                            alt=""
                            loading="lazy"
                        >

                    <?php endif; ?>

                </a>

            <?php endforeach; ?>

        </div>

    <?php endforeach; ?>


    <div class="footer">
        © 2026 PORTAL LINK IT SUPPORT Versi 4
    </div>

</div>


<script>

// ==========================================
// ID UNIK PER BROWSER
// (crypto.randomUUID tidak tersedia di http non-localhost,
//  jadi dibuat manual)
// ==========================================

function makeClientId() {
    let id = '';
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    for (let i = 0; i < 24; i++) {
        id += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return id;
}

function safeGet(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
}

function safeSet(key, value) {
    try { localStorage.setItem(key, value); } catch (e) {}
}

let clientId = safeGet('portalClientId');

if (!clientId || clientId.length < 8) {
    clientId = makeClientId();
    safeSet('portalClientId', clientId);
}


// ==========================================
// ONLINE
// ==========================================

const popup = document.getElementById('onlinePopup');

function toggleOnline() {

    popup.classList.toggle('show');

    if (popup.classList.contains('show')) {
        updateOnline();
    }
}

async function heartbeat() {

    try {

        const name = safeGet('portalChatName') || '';

        await fetch('?action=heartbeat&cid=' + encodeURIComponent(clientId) +
                    '&name=' + encodeURIComponent(name), {
            cache: 'no-store'
        });

    } catch (error) {
        console.log(error);
    }
}

async function updateOnline() {

    try {

        const response = await fetch('?action=online', {
            cache: 'no-store'
        });

        const data = await response.json();

        document.getElementById('onlineCount').textContent = data.count;

        if (!popup.classList.contains('show')) {
            return;
        }

        const clientList = document.getElementById('clientList');
        clientList.innerHTML = '';

        if (!data.clients.length) {

            const empty = document.createElement('div');
            empty.className = 'online-empty';
            empty.textContent = 'Belum ada client online';
            clientList.appendChild(empty);
            return;
        }

        data.clients.forEach(function(client) {

            const item = document.createElement('div');
            item.className = 'client-item';

            const title = document.createElement('div');
            title.className = 'client-name';
            title.textContent = '🖥️ ' + (client.name || 'Tanpa nama');

            if (client.id === clientId) {
                const you = document.createElement('span');
                you.className = 'client-you';
                you.textContent = ' (kamu)';
                title.appendChild(you);
            }

            const meta = document.createElement('div');
            meta.className = 'client-meta';
            meta.textContent = [client.device, client.ip].filter(Boolean).join(' • ');

            item.appendChild(title);
            item.appendChild(meta);
            clientList.appendChild(item);
        });

    } catch (error) {
        console.log(error);
    }
}

async function refreshOnline() {
    await heartbeat();
    await updateOnline();
}

refreshOnline();
setInterval(refreshOnline, 15000);

document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        refreshOnline();
    }
});

document.addEventListener('click', function(event) {

    if (!event.target.closest('.online-container')) {
        popup.classList.remove('show');
    }

    if (!event.target.closest('.chat-container')) {
        toggleChat(false);
    }
});


// ==========================================
// OBROLAN
// ==========================================

const chatPopup = document.getElementById('chatPopup');
const chatMessages = document.getElementById('chatMessages');
const chatForm = document.getElementById('chatForm');
const chatName = document.getElementById('chatName');
const chatText = document.getElementById('chatText');
const chatStatus = document.getElementById('chatStatus');
const chatSendButton = document.getElementById('chatSendButton');

let chatPollTimer = null;
let lastChatSignature = '';

chatName.value = safeGet('portalChatName') || '';

function toggleChat(forceState) {

    const shouldOpen = (typeof forceState === 'boolean')
        ? forceState
        : !chatPopup.classList.contains('show');

    chatPopup.classList.toggle('show', shouldOpen);

    if (shouldOpen) {
        loadChat();
        chatName.focus();

        if (chatPollTimer === null) {
            chatPollTimer = setInterval(function() {
                if (chatPopup.classList.contains('show')) {
                    loadChat();
                }
            }, 3000);
        }
    } else if (chatPollTimer !== null) {
        clearInterval(chatPollTimer);
        chatPollTimer = null;
    }
}

function formatChatTime(timestamp) {

    const date = new Date(Number(timestamp) * 1000);

    return date.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit'
    });
}

async function loadChat() {

    try {

        const response = await fetch('?action=chat_list&cid=' + encodeURIComponent(clientId), {
            cache: 'no-store'
        });

        const data = await response.json();

        if (!data.success) {
            return;
        }

        const signature = JSON.stringify(data.messages);

        if (signature === lastChatSignature) {
            return;
        }

        lastChatSignature = signature;
        chatMessages.innerHTML = '';

        if (!data.messages.length) {
            const empty = document.createElement('div');
            empty.className = 'chat-empty';
            empty.textContent = 'Belum ada obrolan. Mulai percakapan yuk!';
            chatMessages.appendChild(empty);
            return;
        }

        data.messages.forEach(function(message) {

            const item = document.createElement('div');
            item.className = 'chat-message' + (message.mine ? ' mine' : '');

            const name = document.createElement('div');
            name.className = 'chat-message-name';
            name.textContent = (message.name || 'Pengguna') +
                (message.ip ? ' <' + message.ip + '>' : '');

            const content = document.createElement('div');
            content.className = 'chat-message-text';
            content.textContent = message.message || '';

            const info = document.createElement('div');
            info.className = 'chat-message-info';
            info.textContent = formatChatTime(message.time);

            item.appendChild(name);
            item.appendChild(content);
            item.appendChild(info);
            chatMessages.appendChild(item);
        });

        chatMessages.scrollTop = chatMessages.scrollHeight;

    } catch (error) {
        chatStatus.textContent = 'Gagal memuat obrolan.';
        console.log(error);
    }
}

chatForm.addEventListener('submit', async function(event) {

    event.preventDefault();

    const name = chatName.value.trim();
    const message = chatText.value.trim();

    if (!name || !message) {
        chatStatus.textContent = 'Nama dan pesan wajib diisi.';
        return;
    }

    chatSendButton.disabled = true;
    chatStatus.textContent = 'Mengirim...';

    try {

        const formData = new FormData();
        formData.append('name', name);
        formData.append('message', message);
        formData.append('cid', clientId);

        const response = await fetch('?action=chat_send', {
            method: 'POST',
            body: formData,
            cache: 'no-store'
        });

        let data = {};

        try {
            data = await response.json();
        } catch (e) {
            throw new Error('Server tidak membalas dengan benar (HTTP ' + response.status + ').');
        }

        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Pesan gagal dikirim');
        }

        const nameChanged = safeGet('portalChatName') !== name;
        safeSet('portalChatName', name);

        chatText.value = '';
        chatStatus.textContent = '';
        lastChatSignature = '';
        await loadChat();

        // Perbarui nama di daftar online
        if (nameChanged) {
            refreshOnline();
        }

    } catch (error) {
        chatStatus.textContent = error.message || 'Pesan gagal dikirim.';
    } finally {
        chatSendButton.disabled = false;
        chatText.focus();
    }
});

// Enter = kirim, Shift+Enter = baris baru
chatText.addEventListener('keydown', function(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        chatForm.requestSubmit();
    }
});

</script>

</body>
</html>