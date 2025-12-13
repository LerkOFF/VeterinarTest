<?php
declare(strict_types=1);

// На PHP 8.5 могут сыпаться Deprecated из зависимостей.
// Для разработки скрываем, чтобы не мешало.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

use App\Db;
use App\Schema;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require __DIR__ . '/../vendor/autoload.php';

// Инициализация БД и схемы
Schema::ensure();

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

// Twig
$twig = new Environment(
    new FilesystemLoader(__DIR__ . '/../templates'),
    ['cache' => false]
);

/**
 * Главная
 */
$app->get('/', function (Request $request, Response $response) use ($twig) {
    $html = $twig->render('home.twig', [
        'title' => 'ВетКлиника — локальная система',
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Клиенты: список + поиск
 */
$app->get('/clients', function (Request $request, Response $response) use ($twig) {
    $q = trim((string)($request->getQueryParams()['q'] ?? ''));

    $pdo = Db::pdo();
    if ($q !== '') {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE full_name LIKE :q OR phone LIKE :q ORDER BY id DESC');
        $stmt->execute([':q' => "%$q%"]);
        $clients = $stmt->fetchAll();
    } else {
        $clients = $pdo->query('SELECT * FROM clients ORDER BY id DESC')->fetchAll();
    }

    $html = $twig->render('clients/index.twig', [
        'title' => 'Клиенты',
        'clients' => $clients,
        'q' => $q,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Клиенты: создание (GET форма + POST сохранение)
 */
$app->map(['GET', 'POST'], '/clients/create', function (Request $request, Response $response) use ($twig) {
    $errors = [];
    $data = [
        'full_name' => '',
        'address' => '',
        'phone' => '',
        'notes' => '',
    ];

    if ($request->getMethod() === 'POST') {
        $parsed = (array)($request->getParsedBody() ?? []);

        $data['full_name'] = trim((string)($parsed['full_name'] ?? ''));
        $data['address']   = trim((string)($parsed['address'] ?? ''));
        $data['phone']     = trim((string)($parsed['phone'] ?? ''));
        $data['notes']     = trim((string)($parsed['notes'] ?? ''));

        if ($data['full_name'] === '') $errors[] = 'ФИО клиента обязательно.';
        if ($data['address'] === '')   $errors[] = 'Адрес обязателен.';

        if (!$errors) {
            $pdo = Db::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO clients (full_name, address, phone, notes, created_at, updated_at)
                 VALUES (:full_name, :address, :phone, :notes, datetime(\'now\'), datetime(\'now\'))'
            );
            $stmt->execute([
                ':full_name' => $data['full_name'],
                ':address'   => $data['address'],
                ':phone'     => ($data['phone'] !== '' ? $data['phone'] : null),
                ':notes'     => ($data['notes'] !== '' ? $data['notes'] : null),
            ]);

            return $response->withHeader('Location', '/clients')->withStatus(302);
        }
    }

    $html = $twig->render('clients/create.twig', [
        'title' => 'Добавить клиента',
        'errors' => $errors,
        'data' => $data,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->run();
