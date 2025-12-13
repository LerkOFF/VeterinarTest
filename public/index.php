<?php
declare(strict_types=1);

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

Schema::ensure();

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$twig = new Environment(
    new FilesystemLoader(__DIR__ . '/../templates'),
    ['cache' => false]
);

function normalizeBirthDateToIso(?string $ddmmyyyy): ?string
{
    $s = trim((string)$ddmmyyyy);
    if ($s === '') return null;

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

function formatIsoToDdMmYyyy(?string $iso): string
{
    $s = trim((string)$iso);
    if ($s === '') return '';

    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return $s;
    }

    return $m[3] . '-' . $m[2] . '-' . $m[1];
}

function normalizeVisitDate(?string $ddmmyyyy): ?string
{
    $s = trim((string)$ddmmyyyy);
    if ($s === '') return null;

    if (!preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $s, $m)) {
        throw new \InvalidArgumentException('Дата визита должна быть в формате ДД-ММ-ГГГГ.');
    }

    $day = (int)$m[1];
    $month = (int)$m[2];
    $year = (int)$m[3];

    if (!checkdate($month, $day, $year)) {
        throw new \InvalidArgumentException('Дата визита некорректна.');
    }

    return $s;
}

function normalizeVisitTime(?string $hhmm): ?string
{
    $s = trim((string)$hhmm);
    if ($s === '') return null;

    if (!preg_match('/^(\d{2}):(\d{2})$/', $s, $m)) {
        throw new \InvalidArgumentException('Время визита должно быть в формате ЧЧ:ММ.');
    }

    $h = (int)$m[1];
    $min = (int)$m[2];

    if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
        throw new \InvalidArgumentException('Время визита некорректно.');
    }

    return $s;
}

/**
 * Разрешаем back только на внутренние пути "/..."
 */
function sanitizeBack(?string $back): ?string
{
    $b = trim((string)$back);
    if ($b === '') return null;
    if (!str_starts_with($b, '/')) return null;
    if (str_starts_with($b, '//')) return null;
    return $b;
}

$app->get('/', function (Request $request, Response $response) use ($twig) {
    $html = $twig->render('home.twig', ['title' => 'ВетКлиника — локальная система']);
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
 * Клиенты: создание
 */
$app->map(['GET', 'POST'], '/clients/create', function (Request $request, Response $response) use ($twig) {
    $errors = [];
    $data = ['full_name' => '', 'address' => '', 'phone' => '', 'notes' => ''];

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
 * Клиент: редактирование (умный back)
 */
$app->map(['GET', 'POST'], '/clients/{id}/edit', function (Request $request, Response $response, array $args) use ($twig) {
    $clientId = (int)($args['id'] ?? 0);
    $pdo = Db::pdo();

    $query = $request->getQueryParams();
    $back = sanitizeBack($query['back'] ?? null);
    $defaultBack = '/clients/' . $clientId;
    $backUrl = $back ?? $defaultBack;

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        $response->getBody()->write('Client not found');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    $errors = [];
    $data = [
        'full_name' => (string)($client['full_name'] ?? ''),
        'address' => (string)($client['address'] ?? ''),
        'phone' => (string)($client['phone'] ?? ''),
        'notes' => (string)($client['notes'] ?? ''),
    ];

    if ($request->getMethod() === 'POST') {
        $parsed = (array)($request->getParsedBody() ?? []);

        $data['full_name'] = trim((string)($parsed['full_name'] ?? ''));
        $data['address']   = trim((string)($parsed['address'] ?? ''));
        $data['phone']     = trim((string)($parsed['phone'] ?? ''));
        $data['notes']     = trim((string)($parsed['notes'] ?? ''));

        if ($data['full_name'] === '') $errors[] = 'ФИО клиента обязательно.';

        if (!$errors) {
            $stmt = $pdo->prepare(
                'UPDATE clients
                 SET full_name = :full_name,
                     address = :address,
                     phone = :phone,
                     notes = :notes,
                     updated_at = datetime(\'now\')
                 WHERE id = :id'
            );
            $stmt->execute([
                ':full_name' => $data['full_name'],
                ':address'   => ($data['address'] !== '' ? $data['address'] : null),
                ':phone'     => ($data['phone'] !== '' ? $data['phone'] : null),
                ':notes'     => ($data['notes'] !== '' ? $data['notes'] : null),
                ':id'        => $clientId,
            ]);

            return $response->withHeader('Location', $backUrl)->withStatus(302);
        }
    }

    $html = $twig->render('clients/edit.twig', [
        'title' => 'Редактировать клиента',
        'client' => $client,
        'errors' => $errors,
        'data' => $data,
        'back_url' => $backUrl,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Клиент: удаление (POST) — каскад: visits -> pets -> client
 */
$app->post('/clients/{id}/delete', function (Request $request, Response $response, array $args) {
    $clientId = (int)($args['id'] ?? 0);
    $pdo = Db::pdo();

    // проверим, что клиент существует
    $stmt = $pdo->prepare('SELECT id FROM clients WHERE id = :id');
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        return $response->withHeader('Location', '/clients')->withStatus(302);
    }

    // транзакция, чтобы удаление было атомарным
    $pdo->beginTransaction();
    try {
        // 1) найти питомцев клиента
        $stmt = $pdo->prepare('SELECT id FROM pets WHERE client_id = :cid');
        $stmt->execute([':cid' => $clientId]);
        $petIds = array_map(static fn($r) => (int)$r['id'], $stmt->fetchAll());

        // 2) удалить визиты всех питомцев
        if (!empty($petIds)) {
            $placeholders = implode(',', array_fill(0, count($petIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM visits WHERE pet_id IN ($placeholders)");
            $stmt->execute($petIds);

            // 3) удалить питомцев
            $stmt = $pdo->prepare("DELETE FROM pets WHERE id IN ($placeholders)");
            $stmt->execute($petIds);
        }

        // 4) удалить клиента
        $stmt = $pdo->prepare('DELETE FROM clients WHERE id = :id');
        $stmt->execute([':id' => $clientId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $response->withHeader('Location', '/clients')->withStatus(302);
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
 * Питомец: добавление
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
        'birth_date' => '',
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
        try { $birthIso = normalizeBirthDateToIso($data['birth_date']); }
        catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

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
 * Питомцы: список
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
 * Питомец: карточка + визиты
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

    $stmt = $pdo->prepare('SELECT * FROM visits WHERE pet_id = :id ORDER BY id DESC');
    $stmt->execute([':id' => $id]);
    $visits = $stmt->fetchAll();

    $html = $twig->render('pets/view.twig', [
        'title' => 'Карточка питомца',
        'pet' => $pet,
        'visits' => $visits,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Питомец: редактирование (умный back)
 */
$app->map(['GET', 'POST'], '/pets/{id}/edit', function (Request $request, Response $response, array $args) use ($twig) {
    $petId = (int)($args['id'] ?? 0);
    $pdo = Db::pdo();

    $query = $request->getQueryParams();
    $back = sanitizeBack($query['back'] ?? null);
    $defaultBack = '/pets/' . $petId;
    $backUrl = $back ?? $defaultBack;

    $stmt = $pdo->prepare(
        'SELECT p.*, c.full_name AS client_full_name
         FROM pets p
         JOIN clients c ON c.id = p.client_id
         WHERE p.id = :id'
    );
    $stmt->execute([':id' => $petId]);
    $pet = $stmt->fetch();

    if (!$pet) {
        $response->getBody()->write('Pet not found');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    $errors = [];
    $data = [
        'name' => (string)($pet['name'] ?? ''),
        'species' => (string)($pet['species'] ?? ''),
        'breed' => (string)($pet['breed'] ?? ''),
        'birth_date' => formatIsoToDdMmYyyy($pet['birth_date'] ?? null),
        'medications' => (string)($pet['medications'] ?? ''),
        'notes' => (string)($pet['notes'] ?? ''),
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
        try { $birthIso = normalizeBirthDateToIso($data['birth_date']); }
        catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'UPDATE pets
                 SET name = :name,
                     species = :species,
                     breed = :breed,
                     birth_date = :birth_date,
                     medications = :medications,
                     notes = :notes,
                     updated_at = datetime(\'now\')
                 WHERE id = :id'
            );

            $stmt->execute([
                ':name' => $data['name'],
                ':species' => ($data['species'] !== '' ? $data['species'] : null),
                ':breed' => ($data['breed'] !== '' ? $data['breed'] : null),
                ':birth_date' => $birthIso,
                ':medications' => ($data['medications'] !== '' ? $data['medications'] : null),
                ':notes' => ($data['notes'] !== '' ? $data['notes'] : null),
                ':id' => $petId,
            ]);

            return $response->withHeader('Location', $backUrl)->withStatus(302);
        }
    }

    $html = $twig->render('pets/edit.twig', [
        'title' => 'Редактировать питомца',
        'pet' => $pet,
        'errors' => $errors,
        'data' => $data,
        'back_url' => $backUrl,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Питомец: удаление
 */
$app->post('/pets/{id}/delete', function (Request $request, Response $response, array $args) {
    $petId = (int)($args['id'] ?? 0);
    $pdo = Db::pdo();

    $stmt = $pdo->prepare('SELECT id, client_id FROM pets WHERE id = :id');
    $stmt->execute([':id' => $petId]);
    $pet = $stmt->fetch();

    if (!$pet) {
        return $response->withHeader('Location', '/pets')->withStatus(302);
    }

    $stmt = $pdo->prepare('DELETE FROM visits WHERE pet_id = :id');
    $stmt->execute([':id' => $petId]);

    $stmt = $pdo->prepare('DELETE FROM pets WHERE id = :id');
    $stmt->execute([':id' => $petId]);

    return $response->withHeader('Location', '/clients/' . (int)$pet['client_id'])->withStatus(302);
});

/**
 * Визит: добавление
 */
$app->map(['GET', 'POST'], '/pets/{id}/visits/create', function (Request $request, Response $response, array $args) use ($twig) {
    $petId = (int)($args['id'] ?? 0);
    $pdo = Db::pdo();

    $stmt = $pdo->prepare(
        'SELECT p.*, c.full_name AS client_full_name
         FROM pets p
         JOIN clients c ON c.id = p.client_id
         WHERE p.id = :id'
    );
    $stmt->execute([':id' => $petId]);
    $pet = $stmt->fetch();

    if (!$pet) {
        $response->getBody()->write('Pet not found');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    $errors = [];
    $data = [
        'visit_date' => '',
        'visit_time' => '',
        'complaint' => '',
        'diagnosis' => '',
        'procedures' => '',
        'recommendations' => '',
    ];

    if ($request->getMethod() === 'POST') {
        $parsed = (array)($request->getParsedBody() ?? []);

        $data['visit_date'] = trim((string)($parsed['visit_date'] ?? ''));
        $data['visit_time'] = trim((string)($parsed['visit_time'] ?? ''));
        $data['complaint'] = trim((string)($parsed['complaint'] ?? ''));
        $data['diagnosis'] = trim((string)($parsed['diagnosis'] ?? ''));
        $data['procedures'] = trim((string)($parsed['procedures'] ?? ''));
        $data['recommendations'] = trim((string)($parsed['recommendations'] ?? ''));

        $visitDate = null;
        $visitTime = null;

        try { $visitDate = normalizeVisitDate($data['visit_date']); }
        catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

        try { $visitTime = normalizeVisitTime($data['visit_time']); }
        catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'INSERT INTO visits (pet_id, visit_date, visit_time, complaint, diagnosis, procedures, recommendations, created_at, updated_at)
                 VALUES (:pet_id, :visit_date, :visit_time, :complaint, :diagnosis, :procedures, :recommendations, datetime(\'now\'), datetime(\'now\'))'
            );

            $stmt->execute([
                ':pet_id' => $petId,
                ':visit_date' => $visitDate,
                ':visit_time' => $visitTime,
                ':complaint' => ($data['complaint'] !== '' ? $data['complaint'] : null),
                ':diagnosis' => ($data['diagnosis'] !== '' ? $data['diagnosis'] : null),
                ':procedures' => ($data['procedures'] !== '' ? $data['procedures'] : null),
                ':recommendations' => ($data['recommendations'] !== '' ? $data['recommendations'] : null),
            ]);

            return $response->withHeader('Location', '/pets/' . $petId)->withStatus(302);
        }
    }

    $html = $twig->render('visits/create.twig', [
        'title' => 'Добавить визит',
        'pet' => $pet,
        'errors' => $errors,
        'data' => $data,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Визит: редактирование
 */
$app->map(['GET', 'POST'], '/pets/{petId}/visits/{visitId}/edit', function (Request $request, Response $response, array $args) use ($twig) {
    $petId = (int)($args['petId'] ?? 0);
    $visitId = (int)($args['visitId'] ?? 0);
    $pdo = Db::pdo();

    $stmt = $pdo->prepare(
        'SELECT p.*, c.full_name AS client_full_name
         FROM pets p
         JOIN clients c ON c.id = p.client_id
         WHERE p.id = :id'
    );
    $stmt->execute([':id' => $petId]);
    $pet = $stmt->fetch();

    if (!$pet) {
        $response->getBody()->write('Pet not found');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    $stmt = $pdo->prepare('SELECT * FROM visits WHERE id = :vid AND pet_id = :pid');
    $stmt->execute([':vid' => $visitId, ':pid' => $petId]);
    $visit = $stmt->fetch();

    if (!$visit) {
        $response->getBody()->write('Visit not found');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    $errors = [];
    $data = [
        'visit_date' => (string)($visit['visit_date'] ?? ''),
        'visit_time' => (string)($visit['visit_time'] ?? ''),
        'complaint' => (string)($visit['complaint'] ?? ''),
        'diagnosis' => (string)($visit['diagnosis'] ?? ''),
        'procedures' => (string)($visit['procedures'] ?? ''),
        'recommendations' => (string)($visit['recommendations'] ?? ''),
    ];

    if ($request->getMethod() === 'POST') {
        $parsed = (array)($request->getParsedBody() ?? []);

        $data['visit_date'] = trim((string)($parsed['visit_date'] ?? ''));
        $data['visit_time'] = trim((string)($parsed['visit_time'] ?? ''));
        $data['complaint'] = trim((string)($parsed['complaint'] ?? ''));
        $data['diagnosis'] = trim((string)($parsed['diagnosis'] ?? ''));
        $data['procedures'] = trim((string)($parsed['procedures'] ?? ''));
        $data['recommendations'] = trim((string)($parsed['recommendations'] ?? ''));

        $visitDate = null;
        $visitTime = null;

        try { $visitDate = normalizeVisitDate($data['visit_date']); }
        catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

        try { $visitTime = normalizeVisitTime($data['visit_time']); }
        catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

        if (!$errors) {
            $stmt = $pdo->prepare(
                'UPDATE visits
                 SET visit_date = :visit_date,
                     visit_time = :visit_time,
                     complaint = :complaint,
                     diagnosis = :diagnosis,
                     procedures = :procedures,
                     recommendations = :recommendations,
                     updated_at = datetime(\'now\')
                 WHERE id = :id AND pet_id = :pet_id'
            );

            $stmt->execute([
                ':visit_date' => $visitDate,
                ':visit_time' => $visitTime,
                ':complaint' => ($data['complaint'] !== '' ? $data['complaint'] : null),
                ':diagnosis' => ($data['diagnosis'] !== '' ? $data['diagnosis'] : null),
                ':procedures' => ($data['procedures'] !== '' ? $data['procedures'] : null),
                ':recommendations' => ($data['recommendations'] !== '' ? $data['recommendations'] : null),
                ':id' => $visitId,
                ':pet_id' => $petId,
            ]);

            return $response->withHeader('Location', '/pets/' . $petId)->withStatus(302);
        }
    }

    $html = $twig->render('visits/edit.twig', [
        'title' => 'Редактировать визит',
        'pet' => $pet,
        'visit' => $visit,
        'errors' => $errors,
        'data' => $data,
    ]);

    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

/**
 * Визит: удаление
 */
$app->post('/pets/{petId}/visits/{visitId}/delete', function (Request $request, Response $response, array $args) {
    $petId = (int)($args['petId'] ?? 0);
    $visitId = (int)($args['visitId'] ?? 0);
    $pdo = Db::pdo();

    $stmt = $pdo->prepare('DELETE FROM visits WHERE id = :vid AND pet_id = :pid');
    $stmt->execute([':vid' => $visitId, ':pid' => $petId]);

    return $response->withHeader('Location', '/pets/' . $petId)->withStatus(302);
});

$app->run();
