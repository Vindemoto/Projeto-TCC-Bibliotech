<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Support/JsonResponse.php';
require_once __DIR__ . '/../src/Support/Request.php';
require_once __DIR__ . '/../src/Support/Router.php';
require_once __DIR__ . '/../src/Support/CurrentUser.php';
require_once __DIR__ . '/../src/Controllers/WishlistController.php';
require_once __DIR__ . '/../src/Controllers/NotificationController.php';
require_once __DIR__ . '/../src/Controllers/ReportController.php';
require_once __DIR__ . '/../src/Controllers/BookController.php';
require_once __DIR__ . '/../src/Controllers/MyLoansController.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-User-Id');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    CurrentUser::id();
    $router = new Router();
    $wishlist = new WishlistController(database());
    $notifications = new NotificationController(database());
    $reports = new ReportController(database());
    $books = new BookController(database());
    $myLoans = new MyLoansController(database());

    $router->add('GET', '/api/books', [$books, 'index']);
    $router->add('GET', '/api/my-loans', [$myLoans, 'index']);
    $router->add('GET', '/api/wishlist', [$wishlist, 'index']);
    $router->add('POST', '/api/wishlist/{bookId}', [$wishlist, 'store']);
    $router->add('DELETE', '/api/wishlist/{bookId}', [$wishlist, 'destroy']);
    $router->add('GET', '/api/notifications', [$notifications, 'index']);
    $router->add('PUT', '/api/notifications/{notificationId}/read', [$notifications, 'read']);
    $router->add('PUT', '/api/notifications/read-all', [$notifications, 'readAll']);
    $router->add('GET', '/api/reports/popular-books', [$reports, 'popularBooks']);
    $router->add('GET', '/api/reports/loans', [$reports, 'loans']);
    $router->add('GET', '/api/reports/active-users', [$reports, 'activeUsers']);

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    // Como o projeto inteiro fica dentro da pasta "bibliotech-api" no
    // Apache, o endereço completo vem como algo tipo
    // "/bibliotech-api/api/wishlist" — mas as rotas cadastradas acima
    // são só "/api/wishlist". Por isso a gente tira o pedaço
    // "/bibliotech-api" antes de comparar com as rotas.
    $pastaDoProjeto = '/bibliotech-api';
    if (str_starts_with($path, $pastaDoProjeto)) {
        $path = substr($path, strlen($pastaDoProjeto));
    }

    $router->dispatch($_SERVER['REQUEST_METHOD'], rtrim($path, '/') ?: '/');
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    JsonResponse::error('Erro interno do servidor.', 500, 'internal_error');
}
