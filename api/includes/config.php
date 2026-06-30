<?php
// Configuration de la base de données
function getDB() {
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=restaurant_db;charset=utf8mb4', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
        exit;
    }
}

// Fonction pour récupérer les données JSON
function getInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    return $input;
}

// Fonction de réponse JSON
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
