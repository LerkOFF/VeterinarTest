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
     * Превращает Referer (полный URL) в относительный путь + query (без домена).
     * Возвращает null, если не получилось.
     */
    $refererToPath = static function (?string $referer): ?string {
        $referer = trim((string)$referer);
        if ($referer === '') return null;

        $parts = @parse_url($referer);
        if (!is_array($parts)) return null;

        $path = (string)($parts['path'] ?? '');
        if ($path === '') return null;

        $qs = (string)($parts['query'] ?? '');
        if ($qs !== '') $path .= '?' . $qs;

        return $path;
    };

    /**
     * Журнал визитов (пагинация по дням)
     * /visits/journal?from=YYYY-MM-DD&to=YYYY-MM-DD&q=...&page=1
     */
    $app->get('/visits/journal', function (Request $request, Response $response) use ($twig, $containsCi) {
        $pdo = Db::pdo();

        $qp = $request->getQueryParams();
        $q = trim((string)($qp['q'] ?? ''));

        // Диапазон по умолчанию: сегодня -> +14 дней
        $today = new DateTimeImmutable('today');
        $defaultFrom = $today->format('Y-m-d');
        $defaultTo = $today->modify('+14 days')->format('Y-m-d');

        $from = trim((string)($qp['from'] ?? ''));
        $to = trim((string)($qp['to'] ?? ''));

        $fromIso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $defaultFrom;
        $toIso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : $defaultTo;

        // Поддержка visit_date и в YYYY-MM-DD, и в ДД-ММ-ГГГГ
        $visitDateIsoExpr = "
            CASE
                WHEN v.visit_date GLOB '????-??-??' THEN v.visit_date
                WHEN v.visit_date GLOB '??-??-????' THEN substr(v.visit_date, 7, 4) || '-' || substr(v.visit_date, 4, 2) || '-' || substr(v.visit_date, 1, 2)
                ELSE v.visit_date
            END
        ";

        $stmt = $pdo->prepare(
            "SELECT
                v.id,
                v.pet_id,
                v.visit_date,
                v.visit_time,
                v.complaint,
                v.diagnosis,
                v.procedures,
                v.recommendations,
                {$visitDateIsoExpr} AS visit_date_iso,

                p.name AS pet_name,
                p.species AS pet_species,
                p.breed AS pet_breed,
                p.client_id AS client_id,

                c.full_name AS client_full_name,
                c.phone AS client_phone
             FROM visits v
             JOIN pets p ON p.id = v.pet_id
             JOIN clients c ON c.id = p.client_id
             WHERE {$visitDateIsoExpr} BETWEEN :from AND :to
             ORDER BY visit_date_iso ASC, v.visit_time ASC, v.id ASC"
        );
        $stmt->execute([':from' => $fromIso, ':to' => $toIso]);
        $rows = $stmt->fetchAll();

        // Поиск
        if ($q !== '') {
            $rows = array_values(array_filter($rows, static function (array $r) use ($q, $containsCi): bool {
                return
                    $containsCi($r['client_full_name'] ?? '', $q) ||
                    $containsCi($r['client_phone'] ?? '', $q) ||
                    $containsCi($r['pet_name'] ?? '', $q) ||
                    $containsCi($r['pet_species'] ?? '', $q) ||
                    $containsCi($r['pet_breed'] ?? '', $q) ||
                    $containsCi($r['complaint'] ?? '', $q);
            }));
        }

        // Группировка по дням
        $daysMap = [];
        foreach ($rows as $r) {
            $dateIso = (string)($r['visit_date_iso'] ?? '');
            if ($dateIso === '') continue;

            $dateView = DateHelper::formatIsoToDdMmYyyy($dateIso);

            $item = $r;
            $item['visit_date_view'] = $dateView;

            $spec = trim((string)($r['pet_species'] ?? ''));
            $breed = trim((string)($r['pet_breed'] ?? ''));
            $item['pet_extra'] = trim($spec . ($spec !== '' && $breed !== '' ? ', ' : '') . $breed);

            if (!isset($daysMap[$dateIso])) {
                $daysMap[$dateIso] = [
                    'date_iso' => $dateIso,
                    'date_view' => $dateView,
                    'items' => [],
                ];
            }

            $daysMap[$dateIso]['items'][] = $item;
        }

        $dayKeys = array_keys($daysMap);
        sort($dayKeys);

        // --- Pagination by days ---
        $page = max(1, (int)($qp['page'] ?? 1));
        $perPageDays = 7;

        $totalDays = count($dayKeys);
        $totalPages = max(1, (int)ceil($totalDays / $perPageDays));
        if ($page > $totalPages) $page = $totalPages;

        $offsetDays = ($page - 1) * $perPageDays;
        $pageDayKeys = array_slice($dayKeys, $offsetDays, $perPageDays);

        $days = [];
        foreach ($pageDayKeys as $k) {
            $days[] = $daysMap[$k];
        }

        // Параметры запроса для ссылок (сохраняем фильтры)
        $baseQuery = [
            'from' => $fromIso,
            'to' => $toIso,
        ];
        if ($q !== '') $baseQuery['q'] = $q;

        $prevUrl = $page > 1
            ? '/visits/journal?' . http_build_query(array_merge($baseQuery, ['page' => $page - 1]))
            : null;

        $nextUrl = $page < $totalPages
            ? '/visits/journal?' . http_build_query(array_merge($baseQuery, ['page' => $page + 1]))
            : null;

        $pages = [];
        $start = max(1, $page - 3);
        $end = min($totalPages, $page + 3);
        for ($p = $start; $p <= $end; $p++) {
            $pages[] = [
                'num' => $p,
                'url' => '/visits/journal?' . http_build_query(array_merge($baseQuery, ['page' => $p])),
            ];
        }

        $fromDay = $totalDays > 0 ? $offsetDays + 1 : 0;
        $toDay = $totalDays > 0 ? min($offsetDays + count($pageDayKeys), $totalDays) : 0;

        $pagination = [
            'page' => $page,
            'perPage' => $perPageDays,
            'total' => $totalDays,
            'totalPages' => $totalPages,
            'from' => $fromDay,
            'to' => $toDay,
            'pages' => $pages,
            'prevUrl' => $prevUrl,
            'nextUrl' => $nextUrl,
        ];

        // ✅ Текущий URL журнала (нужен для back из редактирования визита)
        $currentUrl = '/visits/journal';
        $currentQs = http_build_query(array_merge($baseQuery, ['page' => $page]));
        if ($currentQs !== '') $currentUrl .= '?' . $currentQs;

        $html = $twig->render('visits/journal.twig', [
            'title' => 'Журнал визитов',
            'q' => $q,
            'from' => $fromIso,
            'to' => $toIso,
            'days' => $days,
            'pagination' => $pagination,
            'current_url' => $currentUrl,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });

    /**
     * Редактирование визита
     * GET/POST /visits/{id}/edit?back=...
     *
     * ✅ ФИКС: если back не передали — берём Referer (если это журнал), иначе fallback на питомца.
     */
    $app->map(['GET', 'POST'], '/visits/{id}/edit', function (Request $request, Response $response, array $args) use ($twig, $refererToPath) {
        $visitId = (int)($args['id'] ?? 0);
        $pdo = Db::pdo();

        // Визит + питомец + клиент (для шапки)
        $stmt = $pdo->prepare(
            "SELECT
                v.*,
                p.name AS pet_name,
                p.client_id AS client_id,
                c.full_name AS client_full_name
             FROM visits v
             JOIN pets p ON p.id = v.pet_id
             JOIN clients c ON c.id = p.client_id
             WHERE v.id = :id"
        );
        $stmt->execute([':id' => $visitId]);
        $visit = $stmt->fetch();

        if (!$visit) {
            $response->getBody()->write('Visit not found');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $petId = (int)($visit['pet_id'] ?? 0);

        // 1) back из query
        $query = $request->getQueryParams();
        $back = BackHelper::sanitizeBack($query['back'] ?? null);

        // 2) если back не передали — пробуем Referer
        if ($back === null) {
            $ref = $request->getHeaderLine('Referer');
            $refPath = $refererToPath($ref);
            $refBack = BackHelper::sanitizeBack($refPath);

            // ✅ Если реферер — журнал, то используем его как back
            if ($refBack !== null && str_starts_with($refBack, '/visits/journal')) {
                $back = $refBack;
            }
        }

        // 3) fallback
        $defaultBack = $petId > 0 ? ('/pets/' . $petId . '#visits') : '/visits/journal';
        $backUrl = $back ?? $defaultBack;

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

            if ($data['visit_date'] === '') $errors[] = 'Дата визита обязательна.';
            if ($data['visit_time'] === '') $errors[] = 'Время визита обязательно.';

            $visitDate = null;
            $visitTime = null;

            if (!$errors) {
                try { $visitDate = DateHelper::normalizeVisitDate($data['visit_date']); }
                catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }

                try { $visitTime = DateHelper::normalizeVisitTime($data['visit_time']); }
                catch (\InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
            }

            if (!$errors) {
                $stmt = $pdo->prepare(
                    "UPDATE visits
                     SET visit_date = :visit_date,
                         visit_time = :visit_time,
                         complaint = :complaint,
                         diagnosis = :diagnosis,
                         procedures = :procedures,
                         recommendations = :recommendations,
                         updated_at = datetime('now')
                     WHERE id = :id"
                );

                $stmt->execute([
                    ':visit_date' => $visitDate,
                    ':visit_time' => $visitTime,
                    ':complaint' => ($data['complaint'] !== '' ? $data['complaint'] : null),
                    ':diagnosis' => ($data['diagnosis'] !== '' ? $data['diagnosis'] : null),
                    ':procedures' => ($data['procedures'] !== '' ? $data['procedures'] : null),
                    ':recommendations' => ($data['recommendations'] !== '' ? $data['recommendations'] : null),
                    ':id' => $visitId,
                ]);

                return $response->withHeader('Location', $backUrl)->withStatus(302);
            }
        }

        $html = $twig->render('visits/edit.twig', [
            'title' => 'Редактировать визит',
            'visit' => $visit,
            'errors' => $errors,
            'data' => $data,
            'back_url' => $backUrl,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });
};
