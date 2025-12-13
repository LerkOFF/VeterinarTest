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
            $p['birth_date_view'] = DateHelper::formatIsoToDdMmYyyy($p['birth_date'] ?? null);
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
     * Питомец: добавление (привязан к клиенту, пока здесь)
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
};
