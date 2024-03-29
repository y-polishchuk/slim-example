<?php

session_start();

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

$router = $app->getRouteCollector()->getRouteParser();
$vali = new Slim\Example\Validator();

const EMAIL = 'admin@gmail.com';
const PASS = 'qwerty123';

function getUsers($request) {
    return json_decode($request->getCookieParam('users') ?? '', true);
}

function filterByTerm($users, $term) {
    return array_filter($users, fn($user) => str_contains($user['nickname'], $term) !== false);
}

$app->get('/', function ($request, $response) use ($router) {
    if(isset($_SESSION['isAdmin'])) {
        return $response->withRedirect($router->urlFor('users'));
    }

    $messages = $this->get('flash')->getMessages();
    $params = [
        'email' => '',
        'flash' => $messages
    ];

    return $this->get('renderer')->render($response, 'home.phtml', $params);
})->setName('home');

$app->post('/', function ($request, $response) use ($router) {

    $user = $request->getParsedBodyParam('user');

    $hash = password_hash(PASS, PASSWORD_DEFAULT);
    $verify = password_verify($user['pass'], $hash);
    
        if ($user['email'] === EMAIL and $verify === true) {
        $_SESSION['isAdmin'] = true;
        $this->get('flash')->addMessage('success', 'Hey, Admin! You logged in successfully.');
        return $response->withRedirect($router->urlFor('users'));
    }
    $this->get('flash')->addMessage('error', 'Access Denied!');
    $params = [
        'email' => $email,
        'errors' => $errors
    ];
    $response = $response->withStatus(422);
    return $response->withRedirect($router->urlFor('home'));
});

$app->delete('/logout', function ($request, $response) use ($router) {
    session_destroy();
    
    return $response->withRedirect($router->urlFor('home'));
});

$app->get('/users', function ($request, $response) use ($router) {

    if (!isset($_SESSION['isAdmin'])) {
        $this->get('flash')->addMessage('error', 'Access Denied! Please login!');

        return $response->withRedirect($router->urlFor('home'));
    }

    $users = getUsers($request);
    $term = $request->getQueryParam('term');

    $usersList = $term ? filterByTerm($users, $term) : $users;

    $messages = $this->get('flash')->getMessages();
    $params = [
        'term' => $term,
        'flash' => $messages,
        'users' => $usersList
    ];
    return $this->get('renderer')->render($response, 'users/index.phtml', $params);
})->setName('users');

$app->post('/users', function ($request, $response) use ($vali, $router) {

    $users = getUsers($request);
    $user = $request->getParsedBodyParam('user');
    $errors = $vali->validate($user);

    if (count($errors) === 0) {
        $id = uniqid();
        $users[$id] = $user;

        $encodedUsers = json_encode($users);
        $this->get('flash')->addMessage('success', 'New user is created successfully');
        return $response->withHeader('Set-Cookie', "users={$encodedUsers};Path=/")
        ->withRedirect($router->urlFor('users'));
    }
    $params = [
        'user' => $user,
        'errors' => $errors
    ];
    $response = $response->withStatus(422);
    return $this->get('renderer')->render($response, "users/new.phtml", $params);
});

$app->get('/users/new', function ($request, $response) use ($router) {

    if (!isset($_SESSION['isAdmin'])) {
        $this->get('flash')->addMessage('error', 'Access Denied! Please login!');

        return $response->withRedirect($router->urlFor('home'));
    }

    $params = [
        'user' => ['id' => '', 'nickname' => '', 'email' => ''],
        'errors' => []
    ];
    return $this->get('renderer')->render($response, "users/new.phtml", $params);
})->setName('createUser');

$app->get('/users/{id}', function ($request, $response, $args) use ($router) {

    if (!isset($_SESSION['isAdmin'])) {
        $this->get('flash')->addMessage('error', 'Access Denied! Please login!');

        return $response->withRedirect($router->urlFor('home'));
    }
    
    $users = getUsers($request);
    $id = $args['id'];

    $user = $users[$id];

    $params = ['id' => $id, 'nickname' => $user['nickname'], 'email' => $user['email']];
    if($user) {
        return $this->get('renderer')->render($response, 'users/show.phtml', $params);
    } else {
        return $response->withStatus(401)->write("Page not found");
    }
})->setName('user');

$app->get('/users/{id}/edit', function ($request, $response, $args) use ($router) {

    if (!isset($_SESSION['isAdmin'])) {
        $this->get('flash')->addMessage('error', 'Access Denied! Please login!');

        return $response->withRedirect($router->urlFor('home'));
    }
    
    $id = $args['id'];
    $users = getUsers($request);
    $user = $users[$id];

    $messages = $this->get('flash')->getMessages();
    $params = [
        'id' => $id,
        'flash' => $messages,
        'user' => $user,
        'errors' => []
            ];
    if($user) {
        return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
    } else {
        return $response->withStatus(401)->write("Page not found");
    }
})->setName('editUser');

$app->patch('/users/{id}', function ($request, $response, array $args) use ($router, $vali)  {
    $id = $args['id'];
    $users = getUsers($request);
    $user = $users[$id];
    $data = $request->getParsedBodyParam('user');

    $errors = $vali->validate($data);

    if (count($errors) === 0) {
        $user['nickname'] = $data['nickname'];
        $user['email'] = $data['email'];
        $users[$id] = $user;

        $encodedUsers = json_encode($users);
        $url = $router->urlFor('editUser', ['id' => $id]);
        return $response->withHeader('Set-Cookie', "users={$encodedUsers};Path=/")
        ->withRedirect($url);
    }

    $params = [
        'userData' => $data,
        'user' => $user,
        'errors' => $errors
    ];

    $response = $response->withStatus(422);
    return $this->get('renderer')->render($response, 'users/edit.phtml', $params);
});

$app->delete('/users/{id}', function ($request, $response, array $args) use ($router) {
    $users = getUsers($request);
    $id = $args['id'];
    unset($users[$id]);
    $encodedUsers = json_encode($users);
    
    $this->get('flash')->addMessage('success', 'User has been deleted');
    return $response->withHeader('Set-Cookie', "users={$encodedUsers};Path=/")->withRedirect($router->urlFor('users'));
});

$app->run();
