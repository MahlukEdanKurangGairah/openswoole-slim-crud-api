<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';

use OpenSwoole\Http\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

use App\Application\Handlers\HttpErrorHandler;
use App\Application\Handlers\ShutdownHandler;
use App\Application\ResponseEmitter\ResponseEmitter;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;

ini_set('memory_limit', '512M');
date_default_timezone_set('Asia/Jakarta');

$host_api = getenv('SERVER_ACCESS_IP');
$host_port =(int) getenv('SERVER_ACCESS_PORT');

$containerBuilder = new ContainerBuilder();

if (false) { // Should be set to true in production
	$containerBuilder->enableCompilation(__DIR__ . '/var/cache');
}

// Set up settings
$settings = require __DIR__ . '/app/settings.php';
$settings($containerBuilder);

// Set up dependencies
$dependencies = require __DIR__ . '/app/dependencies.php';
$dependencies($containerBuilder);

// Set up repositories
$repositories = require __DIR__ . '/app/repositories.php';
$repositories($containerBuilder);

// Build PHP-DI Container instance
$container = $containerBuilder->build();

// Instantiate the app
global $app;
AppFactory::setContainer($container);
$app = AppFactory::create();

global $serverRequestFactory;
global $streamFactory;
global $uriFactory;
$serverRequestFactory = new ServerRequestFactory();
$streamFactory = new StreamFactory();
$uriFactory = new UriFactory();

$callableResolver = $app->getCallableResolver();

// Register middleware
$middleware = require __DIR__ . '/app/middleware.php';
$middleware($app);

// Register routes
$routes = require __DIR__ . '/app/routes.php';
$routes($app);

/** @var SettingsInterface $settings */
$settings = $container->get(SettingsInterface::class);

$displayErrorDetails = $settings->get('displayErrorDetails');
$logError = $settings->get('logError');
$logErrorDetails = $settings->get('logErrorDetails');

// Create Request object from globals
$serverRequestCreator = ServerRequestCreatorFactory::create();
$requestx = $serverRequestCreator->createServerRequestFromGlobals();

// Create Error Handler
$responseFactory = $app->getResponseFactory();
$errorHandler = new HttpErrorHandler($callableResolver, $responseFactory);

// Create Shutdown Handler
$shutdownHandler = new ShutdownHandler($requestx, $errorHandler, $displayErrorDetails);
register_shutdown_function($shutdownHandler);

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Body Parsing Middleware
$app->addBodyParsingMiddleware();

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, $logError, $logErrorDetails);
$errorMiddleware->setDefaultErrorHandler($errorHandler);

$server = new Server($host_api,$host_port,SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
//$server = new Server($host_api,$host_port);
$server->set([
    'worker_num' =>(int) getenv('NUM_PROSES'), // Sesuaikan dengan jumlah CPU
    'daemonize' => false,
    'max_request' => 1000,
    'max_conn' => 1024,
    'enable_coroutine' => true,
    'max_coroutine' => 3000,
    'hook_flags' => SWOOLE_HOOK_ALL,
    'buffer_output_size' => 32 * 1024 * 1024, // 32MB
    'socket_buffer_size' => 128 * 1024 * 1024, // 128MB
]);

$server->on('ManagerStart', function(Server $server)use($host_api,$host_port){
	echo "Swoole server manager started at http://$host_api:".getenv('SERVER_ACCESS_PORT')."\n";
});
$server->on("WorkerStart", function($server, $workerId){
	echo "Worker Started: $workerId\n";
	if (function_exists('opcache_reset')) {
        opcache_reset();
    }
});
$server->on("Shutdown", function($server, $workerId){
	echo "Server shutting down...\n";
});
$server->on("WorkerStop", function($server, $workerId){
	echo "Worker Stopped: $workerId\n";
});

$server->on('workerExit', function($server, $workerId) {
	clearStaticProperties();
});

$server->on('Request', function(Request $request, Response $response){
	handle2($request,$response);
});

function clearStaticProperties() {
    $classes = get_declared_classes();
    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);
        $statics = $reflection->getProperties(ReflectionProperty::IS_STATIC);
        foreach ($statics as $static) {
            if ($static->isPublic()) {
                $static->setValue(null);
            }
        }
    }
}

function handle2($request,$response){
    global $app;
    global $serverRequestFactory;
    global $streamFactory;
    global $uriFactory;
	//echo "===================START REQUEST===================\n";
	//var_dump($request);
	//echo "===================END REQUEST===================\n";
	try {
		$queryString = $request->server['query_string'] ?? '';
		$baseUri = 'http://localhost:'.getenv('SERVER_ACCESS_PORT'). $request->server['request_uri'];
		if (!empty($queryString)) {
            // Cek apakah request_uri sudah mengandung query string
            if (strpos($baseUri, '?') === false) {
                $baseUri .= '?' . $queryString;
            }
        }
        // Buat URI
        $uri = $uriFactory->createUri($baseUri);

        // Buat headers
        $headers = [];
        foreach ($request->header as $key => $value) {
            $headers[$key] = $value;
        }

        // Buat body stream dengan content yang dibatasi
        $rawContent = $request->rawContent() ?? '';
        if (strlen($rawContent) > 5 * 1024 * 1024) { // Batasi 5MB
            throw new Exception('Request payload too large');
        }
        $body = $streamFactory->createStream($rawContent);

        // Buat server params
        $serverParams = [
            'REQUEST_METHOD' => $request->server['request_method'],
            'REQUEST_URI' => $request->server['request_uri'],
            'QUERY_STRING' => $request->server['query_string'] ?? '',
            'SERVER_PROTOCOL' => $request->server['server_protocol'],
            'SERVER_NAME' => $request->header['host'] ?? 'localhost',
            'SERVER_PORT' => 9501,
            'REMOTE_ADDR' => $request->server['remote_addr'],
        ];

        // Buat PSR-7 request
        $psr7Request = $serverRequestFactory->createServerRequest(
            $request->server['request_method'],
            $uri,
            $serverParams
        )
        ->withBody($body)
        ->withCookieParams($request->cookie ?? [])
        ->withQueryParams($request->get ?? [])
        ->withParsedBody($request->post ?? []);

        // Tambahkan headers
        foreach ($headers as $name => $value) {
            $psr7Request = $psr7Request->withHeader($name, $value);
        }

        // Handle request dengan Slim
        $psr7Response = $app->handle($psr7Request);

        // Kirim response ke client
        $response->status($psr7Response->getStatusCode());
        
        foreach ($psr7Response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $response->header($name, $value);
            }
        }
        
        $response->end((string)$psr7Response->getBody());

    } catch (Throwable $e) {
        // Handle error
		$errorString = "Internal Server Error: " . $e->getMessage();
		echo $errorString."\n";
        $response->status(500);
        $response->end($errorString);
    } finally {
        // Bersihkan resources
        if (isset($body)) {
            $body->close();
        }
        
        // Bersihkan garbage collection
        if (gc_enabled()) {
            gc_collect_cycles();
        }
    }
}
$server->start();
