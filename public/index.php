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
 * Хелпер: преобразование даты из ДД-ММ-ГГГГ в YYYY-MM-DD для хранения в БД.
 * Возвращает null, если пусто. Бросает исключение, если формат неверный.
 */
function normalizeBirthDateToIso(?string $ddmmyyyy): ?string
{
    $s = trim((string)$ddmmyyyy);
    if ($s === '') {
        return null;
    }

    if (!preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m)) {
        throw new \InvalidArgumentException('Дата рождения должна быть в формате ДД-ММ-ГГГГ.');
    }

    $day = (int)$m[1];
    $month = (int)$m[2];
    $year = (int)$m[3];

    if (!checkdate($month, $day, $year)) {
        throw new \InvalidArgumentException('Дата рождения некорректна.');
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * Хелпер: отображение даты из YYYY-MM-DD в ДД-ММ-ГГГГ
 */
function formatIsoToDdMmYyyy(?string $iso): string
{
    $s = trim((string)$iso);
    if ($s === '') {
        return '';
    }

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return $s;
    }

    return $m[3] . '-' . $m[2] . '-' . $m[1];
}

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
 * Обязательное: только ФИО
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

        if (!$errors) {
            $pdo = Db::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO clients (full_name, address, phone, notes, created_at, updated_at)
                 VALUES (:full_name, :address, :phone, :notes, datetime(\'now\'), datetime(\'now\'))'
            );
            $stmt->execute([
                ':full_name' => $data['full_name'],
                ':address'   => ($data['address'] !== '' ? $data['address'] : null),
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

/**
 * Клиент: карточка + питомцы
 */
$app->get('/clients/{id}', function (Request $request, Response $response, array $args) use ($twig) {
    $id = (int)($args['id'] ?? 0);

    $pdo = Db::pdo();

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $client = $stmt->fetch();

    if (!$client) {
        $response->getBody()->write('Client not found');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    $stmt = $pdo->prepare('SELECT * FROM pets WHERE client_id = :id ORDER BY id DESC');
    $stmt->execute([':id' => $id]);
    $petsRaw = $stmt->fetchAll();

    $pets = [];
    foreach ($petsRaw as $p) {
        $p['birth_date_view'] = formatIsoToDdMmYyyy($p['birth_date'] ?? null);
        $pets[] = $p;
    }

    $html = $twig->render('clients/view.twig', [
        'title' => 'Карточка клиента',
        'client' => $client,
        'pets' => $pets,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Питомец: добавление для конкретного клиента (GET форма + POST сохранение)
 * Обязательное: только кличка
 * Дата рождения: ввод ДД-ММ-ГГГГ, хранение YYYY-MM-DD
 */
$app->map(['GET', 'POST'], '/clients/{id}/pets/create', function (Request $request, Response $response, array $args) use ($twig) {
    $clientId = (int)($args['id'] ?? 0);
    $pdo = Db::pdo();

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        $response->getBody()->write('Client not found');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    $errors = [];
    $data = [
        'name' => '',
        'species' => '',
        'breed' => '',
        'birth_date' => '', // ДД-ММ-ГГГГ
        'medications' => '',
        'notes' => '',
    ];

    if ($request->getMethod() === 'POST') {
        $parsed = (array)($request->getParsedBody() ?? []);

        $data['name'] = trim((string)($parsed['name'] ?? ''));
        $data['species'] = trim((string)($parsed['species'] ?? ''));
        $data['breed'] = trim((string)($parsed['breed'] ?? ''));
        $data['birth_date'] = trim((string)($parsed['birth_date'] ?? ''));
        $data['medications'] = trim((string)($parsed['medications'] ?? ''));
        $data['notes'] = trim((string)($parsed['notes'] ?? ''));

        if ($data['name'] === '') $errors[] = 'Кличка питомца обязательна.';

        $birthIso = null;
        try {
            $birthIso = normalizeBirthDateToIso($data['birth_date']);
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO pets (client_id, name, species, breed, birth_date, medications, notes, created_at, updated_at)
                 VALUES (:client_id, :name, :species, :breed, :birth_date, :medications, :notes, datetime(\'now\'), datetime(\'now\'))'
            );

            $stmt->execute([
                ':client_id' => $clientId,
                ':name' => $data['name'],
                ':species' => ($data['species'] !== '' ? $data['species'] : null),
                ':breed' => ($data['breed'] !== '' ? $data['breed'] : null),
                ':birth_date' => $birthIso,
                ':medications' => ($data['medications'] !== '' ? $data['medications'] : null),
                ':notes' => ($data['notes'] !== '' ? $data['notes'] : null),
            ]);

            return $response->withHeader('Location', '/clients/' . $clientId)->withStatus(302);
        }
    }

    $html = $twig->render('pets/create.twig', [
        'title' => 'Добавить питомца',
        'client' => $client,
        'errors' => $errors,
        'data' => $data,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Питомцы: общий список + поиск по кличке/виду/породе/ФИО клиента
 */
$app->get('/pets', function (Request $request, Response $response) use ($twig) {
    $q = trim((string)($request->getQueryParams()['q'] ?? ''));

    $pdo = Db::pdo();

    if ($q !== '') {
        $stmt = $pdo->prepare(
            'SELECT p.*, c.full_name AS client_full_name
             FROM pets p
             JOIN clients c ON c.id = p.client_id
             WHERE p.name LIKE :q OR p.species LIKE :q OR p.breed LIKE :q OR c.full_name LIKE :q
             ORDER BY p.id DESC'
        );
        $stmt->execute([':q' => "%$q%"]);
        $petsRaw = $stmt->fetchAll();
    } else {
        $petsRaw = $pdo->query(
            'SELECT p.*, c.full_name AS client_full_name
             FROM pets p
             JOIN clients c ON c.id = p.client_id
             ORDER BY p.id DESC'
        )->fetchAll();
    }

    $pets = [];
    foreach ($petsRaw as $p) {
        $p['birth_date_view'] = formatIsoToDdMmYyyy($p['birth_date'] ?? null);
        $pets[] = $p;
    }

    $html = $twig->render('pets/index.twig', [
        'title' => 'Питомцы',
        'pets' => $pets,
        'q' => $q,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Питомец: карточка
 */
$app->get('/pets/{id}', function (Request $request, Response $response, array $args) use ($twig) {
    $id = (int)($args['id'] ?? 0);
    $pdo = Db::pdo();

    $stmt = $pdo->prepare(
        'SELECT p.*, c.full_name AS client_full_name
         FROM pets p
         JOIN clients c ON c.id = p.client_id
         WHERE p.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $pet = $stmt->fetch();

    if (!$pet) {
        $response->getBody()->write('Pet not found');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    $pet['birth_date_view'] = formatIsoToDdMmYyyy($pet['birth_date'] ?? null);

    $html = $twig->render('pets/view.twig', [
        'title' => 'Карточка питомца',
        'pet' => $pet,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->run();
