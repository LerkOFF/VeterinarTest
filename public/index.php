<?php
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

use App\Schema;
use Slim\Factory\AppFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require __DIR__ . '/../vendor/autoload.php';

Schema::ensure();

$app = AppFactory::create();

$twig = new Environment(
    new FilesystemLoader(__DIR__ . '/../templates'),
    ['cache' => false]
);

$loadRoutes = static function (string $file) use ($app, $twig): void {
    if (!is_file($file)) {
        throw new RuntimeException("Routes file not found: {$file}");
    }

    $fn = require $file;

    if (!is_callable($fn)) {
        $type = gettype($fn);
        throw new RuntimeException("Routes file must return callable, got: {$type} ({$file})");
    }

    $fn($app, $twig);
};

$loadRoutes(__DIR__ . '/../src/Routes/home.php');
$loadRoutes(__DIR__ . '/../src/Routes/clients.php');
$loadRoutes(__DIR__ . '/../src/Routes/pets.php');
$loadRoutes(__DIR__ . '/../src/Routes/visits.php');
$loadRoutes(__DIR__ . '/../src/Routes/journal.php');

$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->run();
