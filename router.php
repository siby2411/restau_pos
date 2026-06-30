<?php
// Router pour le serveur PHP intégré
$uri = $_SERVER['REQUEST_URI'];

// Si la requête commence par /api/, rediriger vers api/index.php
if (strpos($uri, '/api/') === 0 || strpos($uri, '/api') === 0) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/api/index.php';
    require_once __DIR__ . '/api/index.php';
    exit;
}

// Servir les fichiers statiques
$file = __DIR__ . parse_url($uri, PHP_URL_PATH);
if (is_file($file)) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $mime = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'html' => 'text/html',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'json' => 'application/json'
    ];
    header('Content-Type: ' . ($mime[$ext] ?? 'text/plain'));
    readfile($file);
    exit;
}

// Rediriger tout vers index.html (si vous avez un frontend)
if (is_file(__DIR__ . '/index.html')) {
    readfile(__DIR__ . '/index.html');
    exit;
}

// Sinon, afficher les informations API
echo json_encode([
    'message' => 'API Restaurant',
    'endpoints' => [
        'GET /api/produits' => 'Liste des produits (paramètres: q=recherche, categorie=id)',
        'GET /api/categories' => 'Liste des catégories',
        'POST /api/factures' => 'Créer une facture',
        'POST /api/factures/{id}/lignes' => 'Ajouter un produit',
        'PUT /api/factures/{id}' => 'Mettre à jour une facture',
    ],
    'docs' => 'http://localhost:8080/api/produits?q=BOI'
], JSON_PRETTY_PRINT);
