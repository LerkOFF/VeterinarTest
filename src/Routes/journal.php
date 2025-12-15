<?php
declare(strict_types=1);

use App\Db;
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
     * Журнал визитов
     * /visits/journal?from=YYYY-MM-DD&to=YYYY-MM-DD&q=...
     */
    $app->get('/visits/journal', function (Request $request, Response $response) use ($twig, $containsCi) {
        $pdo = Db::pdo();

        $qp = $request->getQueryParams();
        $q = trim((string)($qp['q'] ?? ''));

        $today = new DateTimeImmutable('today');
        $defaultFrom = $today->format('Y-m-d');
        $defaultTo = $today->modify('+14 days')->format('Y-m-d');

        $from = trim((string)($qp['from'] ?? ''));
        $to = trim((string)($qp['to'] ?? ''));

        $fromIso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $defaultFrom;
        $toIso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : $defaultTo;

        // поддержка и YYYY-MM-DD и ДД-ММ-ГГГГ
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

        // Поиск делаем в PHP (и русские буквы ищутся в любом регистре)
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

        $html = $twig->render('visits/journal.twig', [
            'title' => 'Журнал визитов',
            'q' => $q,
            'from' => $fromIso,
            'to' => $toIso,
            'today' => $defaultFrom,
            'days' => array_values($days),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });
};
