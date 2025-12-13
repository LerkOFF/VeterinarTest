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
     * Журнал визитов (расписание)
     * /visits/journal?from=YYYY-MM-DD&to=YYYY-MM-DD&q=...
     */
    $app->get('/visits/journal', function (Request $request, Response $response) use ($twig) {
        $pdo = Db::pdo();

        $qp = $request->getQueryParams();
        $q = trim((string)($qp['q'] ?? ''));

        // Диапазон дат по умолчанию: сегодня .. +14 дней
        $today = new DateTimeImmutable('today');
        $defaultFrom = $today->format('Y-m-d');
        $defaultTo = $today->modify('+14 days')->format('Y-m-d');

        $from = trim((string)($qp['from'] ?? ''));
        $to = trim((string)($qp['to'] ?? ''));

        // Принимаем только YYYY-MM-DD, иначе ставим дефолт
        $fromIso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $defaultFrom;
        $toIso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : $defaultTo;

        $params = [
            ':from' => $fromIso,
            ':to' => $toIso,
        ];

        /**
         * ВАЖНО:
         * В БД у тебя могли остаться старые записи visit_date в формате ДД-ММ-ГГГГ.
         * Поэтому делаем вычисляемое поле visit_date_iso:
         * - если уже YYYY-MM-DD -> берём как есть
         * - если ДД-ММ-ГГГГ -> превращаем в YYYY-MM-DD через substr
         */
        $visitDateIsoExpr = "
            CASE
                WHEN v.visit_date GLOB '????-??-??' THEN v.visit_date
                WHEN v.visit_date GLOB '??-??-????' THEN substr(v.visit_date, 7, 4) || '-' || substr(v.visit_date, 4, 2) || '-' || substr(v.visit_date, 1, 2)
                ELSE v.visit_date
            END
        ";

        $where = "WHERE {$visitDateIsoExpr} BETWEEN :from AND :to";

        if ($q !== '') {
            $where .= ' AND (
                c.full_name LIKE :q OR
                c.phone LIKE :q OR
                p.name LIKE :q OR
                p.species LIKE :q OR
                p.breed LIKE :q OR
                v.complaint LIKE :q
            )';
            $params[':q'] = "%{$q}%";
        }

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
             {$where}
             ORDER BY visit_date_iso ASC, v.visit_time ASC, v.id ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Группируем по ISO дате, показываем красиво ДД-ММ-ГГГГ
        $days = [];
        foreach ($rows as $r) {
            $dateIso = (string)($r['visit_date_iso'] ?? '');
            $dateView = DateHelper::formatIsoToDdMmYyyy($dateIso);

            $item = $r;
            $item['visit_date_view'] = $dateView;

            $spec = trim((string)($r['pet_species'] ?? ''));
            $breed = trim((string)($r['pet_breed'] ?? ''));
            $item['pet_extra'] = trim($spec . ($spec !== '' && $breed !== '' ? ', ' : '') . $breed);

            $days[$dateIso]['date_iso'] = $dateIso;
            $days[$dateIso]['date_view'] = $dateView;
            $days[$dateIso]['items'][] = $item;
        }

        $dayList = array_values($days);

        $html = $twig->render('visits/journal.twig', [
            'title' => 'Журнал визитов',
            'q' => $q,
            'from' => $fromIso,
            'to' => $toIso,
            'today' => $defaultFrom,
            'days' => $dayList,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });
};
