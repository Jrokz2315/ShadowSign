<?php
/**
 * ShadowSign Web3 Delivery — Store Endpoint
 * POST /d/store.php
 * Body: JSON { payload: <string>, ttl: <int seconds, optional, max 86400> }
 * Returns: JSON { ok: true, token: "<id>", url: "<full url>", expires: <unix ts> }
 */

header('Content-Type: application/json');
// UPDATED: Restrict CORS to your domain only
header('Access-Control-Allow-Origin: https://YOUR_DOMAIN'); // ← SET YOUR DOMAIN
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }

// --- SECURITY METHOD 1: ORIGIN/REFERER CHECK ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

// Check if the request claims to come from your domain
if (strpos($origin, 'https://YOUR_DOMAIN') !== 0 && strpos($referer, 'https://YOUR_DOMAIN') !== 0) {
    http_response_code(403); 
    echo json_encode(['ok' => false, 'error' => 'Forbidden: Invalid Origin']); 
    exit;
}
// -----------------------------------------------

// Config
define('STORE_DIR', __DIR__ . '/payloads/');
define('MAX_PAYLOAD_BYTES', 10 * 1024 * 1024); // 10 MB
define('DEFAULT_TTL', 86400);   // 24 hours
define('MAX_TTL',     86400);   // cap at 24 hours
define('BASE_URL', 'https://YOUR_DOMAIN/d/'); // ← SET YOUR DOMAIN

// Ensure storage dir exists
if (!is_dir(STORE_DIR)) {
    mkdir(STORE_DIR, 0700, true);
    file_put_contents(STORE_DIR . '.htaccess', "Deny from all\n");
}

// Read body
$raw = file_get_contents('php://input');
if (strlen($raw) > MAX_PAYLOAD_BYTES) {
    http_response_code(413); echo json_encode(['ok'=>false,'error'=>'Payload too large (max 10 MB)']); exit;
}

$body = json_decode($raw, true);
if (!$body || !isset($body['payload'])) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing payload field']); exit;
}

$ttl = isset($body['ttl']) ? min((int)$body['ttl'], MAX_TTL) : DEFAULT_TTL;
$expires = time() + $ttl;

// Generate token — 24 random bytes = 32 char base64url
$token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

// Store: JSON envelope with metadata + payload
$envelope = json_encode([
    'token'    => $token,
    'created'  => time(),
    'expires'  => $expires,
    'opened'   => false,
    'payload'  => $body['payload'],   // already encrypted ciphertext from client
]);

$file = STORE_DIR . $token . '.json';
if (file_put_contents($file, $envelope, LOCK_EX) === false) {
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Storage failure']); exit;
}

// Run lightweight cleanup (delete expired files) — probabilistic, 5% chance
if (mt_rand(1, 20) === 1) {
    foreach (glob(STORE_DIR . '*.json') as $f) {
        $e = json_decode(file_get_contents($f), true);
        if ($e && ($e['expires'] < time() || $e['opened'])) {
            @unlink($f);
        }
    }
}

echo json_encode([
    'ok'      => true,
    'token'   => $token,
    'url'     => BASE_URL . '?t=' . $token,
    'expires' => $expires,
]);
?>