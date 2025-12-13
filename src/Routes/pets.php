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
            $p['birth_date_view'] = DateHelper::formatIsoToDdMmYyyy($p['birth_date'] ?? null);
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

        $pet['birth_date_view'] = DateHelper::formatIsoToDdMmYyyy($pet['birth_date'] ?? null);

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
        $back = BackHelper::sanitizeBack($query['back'] ?? null);
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
                'birth_date' => DateHelper::formatIsoToDdMmYyyy($pet['birth_date'] ?? null),
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
            try { $birthIso = DateHelper::normalizeBirthDateToIso($data['birth_date']); }
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
};
