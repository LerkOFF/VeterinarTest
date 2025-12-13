<?php
declare(strict_types=1);

use App\Db;
use App\Helpers\DateHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Twig\Environment;

return static function (App $app, Environment $twig): void {

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

            try { $visitDate = DateHelper::normalizeVisitDate($data['visit_date']); }
            catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

            try { $visitTime = DateHelper::normalizeVisitTime($data['visit_time']); }
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

            try { $visitDate = DateHelper::normalizeVisitDate($data['visit_date']); }
            catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

            try { $visitTime = DateHelper::normalizeVisitTime($data['visit_time']); }
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
};
