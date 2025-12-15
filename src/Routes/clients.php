<?php
declare(strict_types=1);

use App\Db;
use App\Helpers\BackHelper;
use App\Helpers\DateHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Twig\Environment;

return static function (App $app, Environment $twig): void {

    $containsCi = static function (?string $haystack, string $needle): bool {
        $haystack = (string)($haystack ?? '');
        if ($needle === '') return true;

        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }

        return stripos($haystack, $needle) !== false;
    };

    /**
     * Клиенты: список + поиск (поиск делаем в PHP, чтобы работало и для русских букв)
     */
    $app->get('/clients', function (Request $request, Response $response) use ($twig, $containsCi) {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));

        $pdo = Db::pdo();
        $clients = $pdo->query('SELECT * FROM clients ORDER BY id DESC')->fetchAll();

        if ($q !== '') {
            $clients = array_values(array_filter($clients, static function (array $c) use ($q, $containsCi): bool {
                return
                    $containsCi($c['full_name'] ?? '', $q) ||
                    $containsCi($c['phone'] ?? '', $q) ||
                    $containsCi($c['address'] ?? '', $q) ||
                    $containsCi($c['notes'] ?? '', $q);
            }));
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
        $back = BackHelper::sanitizeBack($query['back'] ?? null);
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

        $stmt = $pdo->prepare('SELECT id FROM clients WHERE id = :id');
        $stmt->execute([':id' => $clientId]);
        $client = $stmt->fetch();

        if (!$client) {
            return $response->withHeader('Location', '/clients')->withStatus(302);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM pets WHERE client_id = :cid');
            $stmt->execute([':cid' => $clientId]);
            $petIds = array_map(static fn($r) => (int)$r['id'], $stmt->fetchAll());

            if (!empty($petIds)) {
                $placeholders = implode(',', array_fill(0, count($petIds), '?'));

                $stmt = $pdo->prepare("DELETE FROM visits WHERE pet_id IN ($placeholders)");
                $stmt->execute($petIds);

                $stmt = $pdo->prepare("DELETE FROM pets WHERE id IN ($placeholders)");
                $stmt->execute($petIds);
            }

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
     * Клиент: карточка + питомцы + быстрый визит
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
            $p['birth_date_view'] = DateHelper::formatIsoToDdMmYyyy($p['birth_date'] ?? null);
            $pets[] = $p;
        }

        $query = $request->getQueryParams();
        $quickOpen = (string)($query['quick'] ?? '') === '1';
        $quickSaved = (string)($query['saved'] ?? '') === '1';

        $quickData = [
            'pet_id' => '',
            'visit_date' => '',
            'visit_time' => '',
            'complaint' => '',
            'diagnosis' => '',
            'procedures' => '',
            'recommendations' => '',
        ];

        $html = $twig->render('clients/view.twig', [
            'title' => 'Карточка клиента',
            'client' => $client,
            'pets' => $pets,

            'quick_open' => $quickOpen,
            'quick_saved' => $quickSaved,
            'quick_errors' => [],
            'quick' => $quickData,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    /**
     * Питомец: добавление (привязан к клиенту)
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
            try { $birthIso = DateHelper::normalizeBirthDateToIso($data['birth_date']); }
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
     * БЫСТРЫЙ ВИЗИТ: добавление прямо из карточки клиента (POST)
     */
    $app->post('/clients/{id}/visits/quick', function (Request $request, Response $response, array $args) use ($twig) {
        $clientId = (int)($args['id'] ?? 0);
        $pdo = Db::pdo();

        $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute([':id' => $clientId]);
        $client = $stmt->fetch();

        if (!$client) {
            $response->getBody()->write('Client not found');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $stmt = $pdo->prepare('SELECT * FROM pets WHERE client_id = :id ORDER BY id DESC');
        $stmt->execute([':id' => $clientId]);
        $petsRaw = $stmt->fetchAll();

        $pets = [];
        $petIds = [];
        foreach ($petsRaw as $p) {
            $petIds[] = (int)$p['id'];
            $p['birth_date_view'] = DateHelper::formatIsoToDdMmYyyy($p['birth_date'] ?? null);
            $pets[] = $p;
        }

        $parsed = (array)($request->getParsedBody() ?? []);

        $quick = [
            'pet_id' => trim((string)($parsed['pet_id'] ?? '')),
            'visit_date' => trim((string)($parsed['visit_date'] ?? '')),
            'visit_time' => trim((string)($parsed['visit_time'] ?? '')),
            'complaint' => trim((string)($parsed['complaint'] ?? '')),
            'diagnosis' => trim((string)($parsed['diagnosis'] ?? '')),
            'procedures' => trim((string)($parsed['procedures'] ?? '')),
            'recommendations' => trim((string)($parsed['recommendations'] ?? '')),
        ];

        $errors = [];

        $petId = (int)$quick['pet_id'];
        if ($petId <= 0) {
            $errors[] = 'Выберите питомца.';
        } elseif (!in_array($petId, $petIds, true)) {
            $errors[] = 'Выбранный питомец не принадлежит этому клиенту.';
        }

        $visitDate = null;
        $visitTime = null;

        if ($quick['visit_date'] === '') $errors[] = 'Дата визита обязательна.';
        if ($quick['visit_time'] === '') $errors[] = 'Время визита обязательно.';

        if (!$errors) {
            try { $visitDate = DateHelper::normalizeVisitDate($quick['visit_date']); }
            catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

            try { $visitTime = DateHelper::normalizeVisitTime($quick['visit_time']); }
            catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
        }

        if ($errors) {
            $html = $twig->render('clients/view.twig', [
                'title' => 'Карточка клиента',
                'client' => $client,
                'pets' => $pets,

                'quick_open' => true,
                'quick_saved' => false,
                'quick_errors' => $errors,
                'quick' => $quick,
            ]);

            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO visits (pet_id, visit_date, visit_time, complaint, diagnosis, procedures, recommendations, created_at, updated_at)
             VALUES (:pet_id, :visit_date, :visit_time, :complaint, :diagnosis, :procedures, :recommendations, datetime(\'now\'), datetime(\'now\'))'
        );

        $stmt->execute([
            ':pet_id' => $petId,
            ':visit_date' => $visitDate,
            ':visit_time' => $visitTime,
            ':complaint' => ($quick['complaint'] !== '' ? $quick['complaint'] : null),
            ':diagnosis' => ($quick['diagnosis'] !== '' ? $quick['diagnosis'] : null),
            ':procedures' => ($quick['procedures'] !== '' ? $quick['procedures'] : null),
            ':recommendations' => ($quick['recommendations'] !== '' ? $quick['recommendations'] : null),
        ]);

        return $response->withHeader('Location', '/clients/' . $clientId . '?quick=1&saved=1#quick-visit')->withStatus(302);
    });
};
