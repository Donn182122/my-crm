<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
service('auth')->routes($routes, ['only' => ['login'], 'namespace' => 'App\Controllers\Auth']);
$routes->post('logout', '\CodeIgniter\Shield\Controllers\LoginController::logoutAction', [
    'as' => 'logout', 'filter' => 'session',
]);
$routes->get('/', 'Dashboard::index', ['filter' => ['session', 'permission:foundation.access']]);
$routes->get('dashboard', 'Dashboard::index', ['as' => 'dashboard', 'filter' => ['session', 'permission:foundation.access']]);
$routes->get('modules/(:segment)', 'Dashboard::comingSoon/$1', ['filter' => ['session', 'permission:foundation.access']]);
$routes->get('account', 'Dashboard::account', ['filter' => ['session', 'permission:foundation.access']]);
$routes->get('admin', 'Dashboard::admin', ['filter' => ['session', 'group:admin', 'permission:admin.access']]);
