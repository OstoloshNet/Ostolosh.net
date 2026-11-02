<?php
/**
 * Receives the contact form from index.html and forwards it to a Telegram bot.
 * The bot token stays on the server and is never sent to visitors.
 *
 * Setup:
 *  1. Copy contact.config.example.php to contact.config.php and fill in token and chat_id.
 *  2. Keep contact.config.php out of public reach (outside the web root, or blocked by the web server).
 *  3. Put contact.php next to index.html. The page already posts to "contact.php".
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'method']);
}

$configPath = getenv('OST_CONTACT_CONFIG') ?: __DIR__ . '/contact.config.php';
$cfg = is_file($configPath) ? require $configPath : [];
$token = (string)($cfg['token'] ?? getenv('TELEGRAM_BOT_TOKEN') ?: '');
$chatId = (string)($cfg['chat_id'] ?? getenv('TELEGRAM_CHAT_ID') ?: '');
if ($token === '' || $chatId === '') {
    reply(500, ['ok' => false, 'error' => 'not configured']);
}

$raw = file_get_contents('php://input', false, null, 0, 8192);
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    reply(400, ['ok' => false, 'error' => 'bad json']);
}

function clean($v, int $max): string {
    $v = is_string($v) ? trim($v) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}

$name = clean($data['name'] ?? '', 80);
$phone = clean($data['phone'] ?? '', 20);
$village = clean($data['village'] ?? '', 40);
$message = clean($data['message'] ?? '', 1500);
$lang = ($data['lang'] ?? '') === 'en' ? 'en' : 'uk';

if (mb_strlen($name) < 2 || mb_strlen($message) < 5 || !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
    reply(400, ['ok' => false, 'error' => 'fields']);
}

// One message per visitor per minute
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$stamp = sys_get_temp_dir() . '/ost_contact_' . md5($ip);
if (is_file($stamp) && time() - (int)filemtime($stamp) < 60) {
    reply(429, ['ok' => false, 'error' => 'rate']);
}

$h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$text = "📩 <b>Нове звернення з сайту</b>\n"
    . "Ім'я: " . $h($name) . "\n"
    . "Телефон: " . $h($phone) . "\n"
    . "Село: " . $h($village !== '' ? $village : '—') . "\n"
    . "Мова сайту: " . $lang . "\n\n"
    . $h($message);

$ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
]);
$out = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$res = is_string($out) ? json_decode($out, true) : null;
if ($code !== 200 || !is_array($res) || empty($res['ok'])) {
    reply(502, ['ok' => false, 'error' => 'telegram']);
}

@touch($stamp);
reply(200, ['ok' => true]);
