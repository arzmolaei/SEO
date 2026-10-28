<?php

// Disposable fixture site for crawler transport tests (php -S router).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$port = $_SERVER['SERVER_PORT'];
switch ($path) {
    case '/ok':
        header('Content-Type: text/html; charset=UTF-8');
        echo '<html lang="fa" dir="rtl"><head><title>سلام</title></head><body><h1>صفحه</h1></body></html>';
        break;
    case '/redirect':
        header('Location: /ok', true, 301);
        break;
    case '/redirect-external':
        header('Location: https://elsewhere.test/', true, 302);
        break;
    case '/redirect-loop':
        header('Location: /redirect-loop', true, 302);
        break;
    case '/redirect-chain':
        $n = (int) ($_GET['n'] ?? 0);
        header('Location: /redirect-chain?n='.($n + 1), true, 302);
        break;
    case '/big':
        header('Content-Type: text/html');
        echo str_repeat('a', 3 * 1024 * 1024);
        break;
    case '/slow':
        sleep(4);
        echo 'late';
        break;
    case '/agent':
        header('Content-Type: text/plain');
        echo ($_SERVER['HTTP_USER_AGENT'] ?? '').'|'.($_SERVER['HTTP_COOKIE'] ?? '');
        break;
    case '/error':
        http_response_code(503);
        echo 'down';
        break;
    default:
        http_response_code(404);
        echo 'missing';
}
