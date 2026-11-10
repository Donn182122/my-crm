<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
service('auth')->routes($routes, ['only' => ['login'], 'namespace' => 'App\Controllers\Auth']);
$routes->post('logout', '\CodeIgniter\Shield\Controllers\LoginController::logoutAction', [
    'as' => 'logout', 'filter' => 'session',
]);
$routes->get('/', 'Home::index', ['filter' => ['session', 'permission:foundation.access']]);
$routes->get('admin', 'Home::admin', ['filter' => ['session', 'group:admin', 'permission:admin.access']]);
