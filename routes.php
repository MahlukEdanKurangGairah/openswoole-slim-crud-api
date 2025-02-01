<?php
declare(strict_types=1);

use App\Application\Actions\User\ListUsersAction;
use App\Application\Actions\User\ViewUserAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

use Tqdev\PhpCrudApi\Config\Config as CrudApiConfig;
use Tqdev\PhpCrudApi\Api as CrudApi;

return function (App $app) {
    /*$app->options('/{routes:.*}', function (Request $request, Response $response) {
        // CORS Pre-Flight OPTIONS Request Handler
        return $response;
    });*/

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('OPENSWOOLE-SLIM-PHP-CRUD-API');
        return $response;
    });
	
	$app->any('/api[/{params:.*}]', function (Request $request,Response $response,array $args){
		$config = new CrudApiConfig([
			'driver' => 'mysql',
			'address' => getenv('MYSQL_HOST'),
			'port' =>(int) getenv('MYSQL_PORT'),
			'username' => getenv('MYSQL_USER'),
			'password' => getenv('MYSQL_PASSWORD'),
			'database' => getenv('MYSQL_DATABASE'),
			'basePath' => '/api',
		]);
        $api = new CrudApi($config);
		$response = $api->handle($request);
		//echo "===================START RESPONSE===================\n";
		//var_dump($response);
		//echo "===================END RESPONSE===================\n";
        return $response;
    });
};
