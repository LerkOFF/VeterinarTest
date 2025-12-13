<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Twig\Environment;

return static function (App $app, Environment $twig): void {
    $app->get('/', function (Request $request, Response $response) use ($twig) {
        $html = $twig->render('home.twig', ['title' => 'ВетКлиника']);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    });
};
