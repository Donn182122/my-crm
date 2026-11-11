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

// Phase 4A: explicit Clients endpoints; all writes are POST and globally CSRF protected.
$routes->group('clients', ['filter'=>['session','permission:clients.view']], static function ($routes) {
    $routes->get('', 'Clients::index');
    $routes->get('new', 'Clients::create');
    $routes->post('', 'Clients::store');
    $routes->get('lookups', 'Clients::lookups');
    $routes->post('lookups/(groups|statuses)', 'Clients::lookupStore/$1');
    $routes->post('lookups/(groups|statuses)/(:num)', 'Clients::lookupUpdate/$1/$2');
    $routes->get('(:num)', 'Clients::show/$1');
    $routes->get('(:num)/edit', 'Clients::edit/$1');
    $routes->post('(:num)', 'Clients::update/$1');
    $routes->post('(:num)/archive', 'Clients::archive/$1');
    $routes->post('(:num)/restore', 'Clients::restore/$1');
    $routes->get('(:num)/(contacts|addresses)/new', 'Clients::childCreate/$1/$2');
    $routes->post('(:num)/(contacts|addresses)', 'Clients::childStore/$1/$2');
    $routes->get('(:num)/(contacts|addresses)/(:num)/edit', 'Clients::childEdit/$1/$2/$3');
    $routes->post('(:num)/(contacts|addresses)/(:num)', 'Clients::childUpdate/$1/$2/$3');
    $routes->post('(:num)/(contacts|addresses)/(:num)/remove', 'Clients::childRemove/$1/$2/$3');
});

// Leads and pipeline: server policies independently protect every operation.
$routes->group('leads', ['filter'=>['session','permission:leads.view']], static function ($routes) {
    $routes->get('', 'Leads::index');
    $routes->get('new', 'Leads::create');
    $routes->get('kanban', 'LeadPipeline::board');
    $routes->post('(:num)/stage', 'LeadPipeline::stage/$1');
    $routes->get('(:num)/convert', 'LeadPipeline::start/$1');
    $routes->post('(:num)/convert/preview', 'LeadPipeline::preview/$1');
    $routes->post('(:num)/convert/confirm', 'LeadPipeline::confirm/$1');
    $routes->get('(:num)/conversion', 'LeadPipeline::result/$1');
    $routes->post('', 'Leads::store');
    $routes->get('(:num)', 'Leads::show/$1');
    $routes->get('(:num)/edit', 'Leads::edit/$1');
    $routes->post('(:num)', 'Leads::update/$1');
    $routes->post('(:num)/archive', 'Leads::archive/$1');
    $routes->post('(:num)/restore', 'Leads::restore/$1');
    $routes->post('(:num)/reopen', 'Leads::reopen/$1');
    $routes->get('(:num)/(contacts|addresses)/new', 'Leads::childCreate/$1/$2');
    $routes->post('(:num)/(contacts|addresses)', 'Leads::childStore/$1/$2');
    $routes->get('(:num)/(contacts|addresses)/(:num)/edit', 'Leads::childEdit/$1/$2/$3');
    $routes->post('(:num)/(contacts|addresses)/(:num)', 'Leads::childUpdate/$1/$2/$3');
    $routes->post('(:num)/(contacts|addresses)/(:num)/remove', 'Leads::childRemove/$1/$2/$3');
});
// Projects: independent record policies and global CSRF protect all writes.
$routes->group('projects',['filter'=>['session','permission:projects.view']],static function($routes){
    $routes->get('','Projects::index');$routes->get('new','Projects::create');$routes->post('','Projects::store');
    $routes->get('(:num)','Projects::show/$1');$routes->get('(:num)/edit','Projects::edit/$1');$routes->post('(:num)','Projects::update/$1');
    $routes->post('(:num)/archive','Projects::archive/$1');$routes->post('(:num)/restore','Projects::restore/$1');
    $routes->post('(:num)/members','Projects::addMember/$1');$routes->post('(:num)/members/(:num)/remove','Projects::removeMember/$1/$2');
});
