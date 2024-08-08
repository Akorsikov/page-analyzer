<?php

/**
 * Instantiate App
 *
 * In order for the factory to work you need to ensure you have installed
 * a supported PSR-7 implementation of your choice e.g.: Slim PSR-7 and a supported
 * ServerRequest creator (included with Slim PSR-7)
 */

use Slim\Http\Request;
use Slim\Http\Response;
// use Psr\Http\Message\ResponseInterface as Response;     // не знает getParsedBodyParam()
// use Psr\Http\Message\ServerRequestInterface as Request; // не знает withRedirect()
use Psr\Http\Message\UriInterface;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Valitron\Validator;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

// Add Routing Middleware
$app->addRoutingMiddleware();

/**
 * Add Error Handling Middleware
 *
 * @param bool $displayErrorDetails -> Should be set to false in production
 * @param bool $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool $logErrorDetails -> Display error details in error log
 * which can be replaced by a callable of your choice.

 * Note: This middleware should be added last. It will not handle any exceptions/errors
 * for middleware added after it.
 */
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// add DB-connection
// $databaseUrl = parse_url('postgresql://sites_db_user:vqEoglOCba3yVva54BoB93y1SYMA4q6r@dpg-cq4qdieehbks73bftqkg-a.frankfurt-postgres.render.com/sites_db');
$host = isset($databaseUrl['host']) ? $databaseUrl['host'] : 'localhost';
$port = isset($databaseUrl['port']) ? $databaseUrl['port'] : 5432;
$dbname = isset($databaseUrl['path']) ? ltrim($databaseUrl['path'], '/') : 'websites_db';
$user = isset($databaseUrl['user']) ? $databaseUrl['user'] : 'aleksandr';
$password = isset($databaseUrl['pass']) ? $databaseUrl['pass'] : 123456;
$conStr = sprintf(
    "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
    $host,
    $port,
    $dbname,
    $user,
    $password
);

$connectionDB = new \PDO($conStr);
$connectionDB->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Add router
$router = $app->getRouteCollector()->getRouteParser();
// Add renderer
$renderer = new PhpRenderer(__DIR__ . '/../templates');

// Define app routes
$app->get('/', function ($request, Response $response) use ($renderer) {

    return $renderer->render($response, 'main.phtml');
})->setName('main');

$app->post('/valid', function ($request, Response $response) use ($connectionDB, $router, $renderer) {
    // phpstan ругался при: use Psr\Http\Message\ServerRequestInterface as Request;
    $urlName = $request->getParsedBodyParam('url_name');

    $rules = ['required', 'url', ['lengthMax', 255]];
    $validation = new Validator(['urlname' => $urlName]);
    $validation->mapFieldRules('urlname', $rules);

    if ($validation->validate()) {
        $insertQuery = "INSERT INTO urls (name) VALUES (:name)";
        $stmt = $connectionDB->prepare($insertQuery);
        $stmt->bindParam(':name', $urlName);
        $stmt->execute();
        // phpstan ругался при: use Psr\Http\Message\ResponseInterface as Response;
        $id = $connectionDB->lastInsertId();

        $url = $router->urlFor('test', ['id' => (string) $id]);

        // phpstan ругался при: use Psr\Http\Message\ResponseInterface as Response;
        return $response->withRedirect($url);
    }
    $errors = $validation->errors();
    $errorMessages = is_bool($errors) ? null : $errors['urlname'];
    $params = [
        'urlName' => $urlName,
        'errors' => $errorMessages
    ];

    return $renderer->render($response, 'main.phtml', $params);
})->setName('valid');

$app->get('/urls', function ($request, Response $response) use ($connectionDB, $renderer) {

    $extractQuery = "SELECT * FROM urls ORDER BY id DESC";
    $stmt = $connectionDB->query($extractQuery);
    $arrayUrls = $stmt->fetchAll(); // phpstan ругается: Cannot call method fetchAll() on PDOStatement|false.

    $params = ['urls' => $arrayUrls];

    return $renderer->render($response, 'view.phtml', $params);
})->setName('viewUrls');

$app->get('/urls/{id}', function ($request, Response $response, array $args) use ($connectionDB, $renderer) {
    $id = $args['id'];

    $extractQuery = "SELECT * FROM urls WHERE id=:id";
    $stmt = $connectionDB->prepare($extractQuery);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    [$arrayUrls] = $stmt->fetchAll();

    return $renderer->render($response, 'test.phtml', $arrayUrls);
})->setName('test');

// Run app
$app->run();
