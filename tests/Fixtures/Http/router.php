<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/redirect-upload') {
    file_get_contents('php://input');
    header('Location: /upload', true, (int) ($_GET['status'] ?? 307));

    return;
}

if ($path === '/redirect-headers') {
    $target = isset($_GET['cross']) ? 'http://localhost:' . $_SERVER['SERVER_PORT'] . '/headers' : '/headers';
    header('Location: ' . $target, true, 307);

    return;
}

if ($path === '/redirect-loop') {
    header('Location: /redirect-loop', true, 307);

    return;
}

if ($path === '/redirect') {
    header('Location: /large');
    header('X-Old: stale');
    http_response_code(302);
    echo 'discard this body';

    return;
}

if ($path === '/compressed') {
    $body = gzencode('compressed payload');
    header('Content-Encoding: gzip');
    header('Content-Length: ' . strlen($body));
    echo $body;

    return;
}

if ($path === '/large') {
    header('Content-Length: ' . 32 * 1024 * 1024);
    header('X-Final: yes');

    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        for ($i = 0; $i < 512; $i++) {
            echo str_repeat('x', 65536);
        }
    }

    return;
}

if ($path === '/headers') {
    header('Content-Type: application/json');
    echo json_encode(getallheaders());

    return;
}

$input  = fopen('php://input', 'rb');
$hash   = hash_init('sha256');
$length = hash_update_stream($hash, $input);
$digest = hash_final($hash);

fclose($input);
header('Content-Type: application/json');
echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'length' => $length, 'hash' => $digest]);
