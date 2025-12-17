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
     * Питомцы: список + поиск + пагинация
     */
    $app->get('/pets', function (Request $request, Response $response) use ($twig, $containsCi) {
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));

        $pdo = Db::pdo();
        $petsRaw = $pdo->query(
            'SELECT p.*, c.full_name AS client_full_name
             FROM pets p
             LEFT JOIN clients c ON c.id = p.client_id
             ORDER BY p.id DESC'
        )->fetchAll();

        $pets = [];
        foreach ($petsRaw as $p) {
            $p['birth_date_view'] = DateHelper::formatIsoToDdMmYyyy($p['birth_date'] ?? null);
            $pets[] = $p;
        }

        // Поиск (регистронезависимый, работает с русским)
        if ($q !== '') {
            $pets = array_values(array_filter($pets, static function (array $p) use ($q, $containsCi): bool {
                return
                    $containsCi($p['name'] ?? '', $q) ||
                    $containsCi($p['species'] ?? '', $q) ||
                    $containsCi($p['breed'] ?? '', $q) ||
                    $containsCi($p['medications'] ?? '', $q) ||
                    $containsCi($p['notes'] ?? '', $q) ||
                    $containsCi($p['client_full_name'] ?? '', $q);
            }));
        }

        // --- Pagination ---
        $qp = $request->getQueryParams();
        $page = max(1, (int)($qp['page'] ?? 1));
        $perPage = 20;

        $total = count($pets);
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;

        $offset = ($page - 1) * $perPage;
        $petsPage = array_slice($pets, $offset, $perPage);

        // base query сохраняет поиск между страницами
        $baseQuery = [];
        if ($q !== '') $baseQuery['q'] = $q;

        $prevUrl = $page > 1
            ? '/pets?' . http_build_query(array_merge($baseQuery, ['page' => $page - 1]))
            : null;

        $nextUrl = $page < $totalPages
            ? '/pets?' . http_build_query(array_merge($baseQuery, ['page' => $page + 1]))
            : null;

        // окно страниц вокруг текущей
        $pages = [];
        $start = max(1, $page - 3);
        $end = min($totalPages, $page + 3);

        for ($p = $start; $p <= $end; $p++) {
            $pages[] = [
                'num' => $p,
                'url' => '/pets?' . http_build_query(array_merge($baseQuery, ['page' => $p])),
            ];
        }

        // текущий URL списка — нужен для "back" из edit
        $currentListUrl = '/pets';
        $currentQs = http_build_query(array_merge($baseQuery, ['page' => $page]));
        if ($currentQs !== '') $currentListUrl .= '?' . $currentQs;

        $pagination = [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => $total > 0 ? min($offset + count($petsPage), $total) : 0,
            'pages' => $pages,
            'prevUrl' => $prevUrl,
            'nextUrl' => $nextUrl,
            'currentListUrl' => $currentListUrl,
        ];

        $html = $twig->render('pets/index.twig', [
            'title' => 'Питомцы',
            'pets' => $petsPage,
            'q' => $q,
            'pagination' => $pagination,
            'back_to_list' => $currentListUrl,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    /**
     * Питомец: карточка
     */
    $app->get('/pets/{id}', function (Request $request, Response $response, array $args) use ($twig) {
        $petId = (int)($args['id'] ?? 0);
        $pdo = Db::pdo();

        $stmt = $pdo->prepare(
            'SELECT p.*, c.full_name AS client_full_name
             FROM pets p
             LEFT JOIN clients c ON c.id = p.client_id
             WHERE p.id = :id'
        );
        $stmt->execute([':id' => $petId]);
        $pet = $stmt->fetch();

        if (!$pet) {
            $response->getBody()->write('Pet not found');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $pet['birth_date_view'] = DateHelper::formatIsoToDdMmYyyy($pet['birth_date'] ?? null);

        $stmt = $pdo->prepare('SELECT * FROM visits WHERE pet_id = :pid ORDER BY id DESC');
        $stmt->execute([':pid' => $petId]);
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

        $stmt = $pdo->prepare('SELECT * FROM pets WHERE id = :id');
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
            'birth_date' => DateHelper::formatIsoToDdMmYyyy($pet['birth_date'] ?? null) ?? '',
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
     * Питомец: удаление (POST) — каскад: visits -> pet
     */
    $app->post('/pets/{id}/delete', function (Request $request, Response $response, array $args) {
        $petId = (int)($args['id'] ?? 0);
        $pdo = Db::pdo();

        $stmt = $pdo->prepare('SELECT client_id FROM pets WHERE id = :id');
        $stmt->execute([':id' => $petId]);
        $pet = $stmt->fetch();

        if (!$pet) {
            return $response->withHeader('Location', '/pets')->withStatus(302);
        }

        $clientId = (int)($pet['client_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM visits WHERE pet_id = :pid');
            $stmt->execute([':pid' => $petId]);

            $stmt = $pdo->prepare('DELETE FROM pets WHERE id = :id');
            $stmt->execute([':id' => $petId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($clientId > 0) {
            return $response->withHeader('Location', '/clients/' . $clientId)->withStatus(302);
        }

        return $response->withHeader('Location', '/pets')->withStatus(302);
    });
};
