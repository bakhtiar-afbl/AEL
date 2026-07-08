<?php
/**
 * Same-origin / cross-origin reverse proxy for the AEL Performance Console.
 *
 * Works two ways:
 *  1) Served next to index.html on cPanel (ael.bakhtiar360.com) — same-origin,
 *     no CORS involved at all.
 *  2) Called cross-origin by the GitHub Pages copy of the app. For that case it
 *     emits CORS headers for the allow-listed origins below, so the GitHub page
 *     can use this proxy without any change to the API server.
 *
 * It forwards /api/ calls to the real API server-side and streams the response
 * back. Only /api/ paths to the fixed host are allowed (not an open relay).
 * Requires PHP + cURL (standard on cPanel).
 */

$API = "https://arlapi.ibos.io";            // the real API host (fixed)

// Origins allowed to call this proxy from a browser on a DIFFERENT domain.
// Add any other front-end origins here (scheme + host, no trailing slash).
$ALLOWED_ORIGINS = [
    "https://bakhtiar-afbl.github.io",
    "https://ael.bakhtiar360.com",
];

// ---- CORS: reflect the Origin if it is allow-listed ----
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin !== '' && in_array($origin, $ALLOWED_ORIGINS, true)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept");
    header("Access-Control-Max-Age: 86400");
}

// ---- Answer the CORS preflight immediately ----
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- resolve the requested API path (?p=/api/v1/...) ----
$path = isset($_GET['p']) ? $_GET['p'] : '';
if ($path === '' || strpos($path, '/api/') !== 0 || strpos($path, '..') !== false) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(["detail" => "Invalid proxy path"]);
    exit;
}

$url    = $API . $path;
$method = $_SERVER['REQUEST_METHOD'];

// ---- collect the Authorization header (cPanel sometimes hides it; .htaccess
//      re-exposes it as HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION) ----
$auth = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif (function_exists('apache_request_headers')) {
    $h = apache_request_headers();
    if (isset($h['Authorization'])) $auth = $h['Authorization'];
    elseif (isset($h['authorization'])) $auth = $h['authorization'];
}

$headers = ["Accept: application/json"];
if ($auth) $headers[] = "Authorization: " . $auth;

$body = file_get_contents('php://input');
if ($method !== 'GET' && $method !== 'HEAD') {
    $headers[] = "Content-Type: application/json";
}

// ---- forward with cURL ----
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
if ($method !== 'GET' && $method !== 'HEAD') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);

$resp  = curl_exec($ch);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$cerr  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(["detail" => "Upstream request failed: " . $cerr]);
    exit;
}

http_response_code($code ?: 502);
header('Content-Type: ' . ($ctype ? $ctype : 'application/json'));
echo $resp;
