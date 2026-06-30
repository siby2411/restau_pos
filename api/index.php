<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

function sendJSON($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(['error' => 'JSON encoding error']);
    }
    echo $json;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (strpos($_SERVER['REQUEST_URI'], '/api/test') !== false) {
    sendJSON(['success' => true, 'message' => 'API avec trigger', 'time' => date('Y-m-d H:i:s')]);
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=restaurant_db;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri = str_replace('/api', '', $uri);
    $uri = trim($uri, '/');
    $parts = explode('/', $uri);
    $resource = $parts[0] ?? '';
    $id = isset($parts[1]) ? $parts[1] : null;
    $method = $_SERVER['REQUEST_METHOD'];
    
    $input = [];
    if ($method === 'POST' || $method === 'PUT') {
        $body = file_get_contents('php://input');
        if ($body) {
            $input = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $input = [];
            }
        }
    }
    
    $routeFound = false;
    
    // 1. GET /produits
    if ($resource === 'produits' && $method === 'GET' && !$routeFound) {
        $routeFound = true;
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $sql = "SELECT p.*, c.nom as categorie_nom 
                FROM produits p 
                LEFT JOIN categories c ON c.id = p.categorie_id";
        $params = [];
        
        if (!empty($q)) {
            $sql .= " WHERE p.code LIKE ? OR p.nom LIKE ? OR p.description LIKE ?";
            $search = "%$q%";
            $params = [$search, $search, $search];
        }
        
        $sql .= " ORDER BY p.code LIMIT 100";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendJSON(['success' => true, 'data' => $produits, 'count' => count($produits)]);
    }
    
    // 2. GET /categories
    if ($resource === 'categories' && $method === 'GET' && !$routeFound) {
        $routeFound = true;
        $stmt = $pdo->query("SELECT * FROM categories ORDER BY nom");
        sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    
    // 3. POST /factures - Le trigger gère le numéro
    if ($resource === 'factures' && $method === 'POST' && $id === null && !$routeFound) {
        $routeFound = true;
        $table_id = isset($input['table_id']) ? (int)$input['table_id'] : 1;
        $utilisateur_id = isset($input['utilisateur_id']) ? (int)$input['utilisateur_id'] : 1;
        
        // Le trigger va générer le numéro automatiquement
        $stmt = $pdo->prepare("INSERT INTO factures (table_id, utilisateur_id, statut) VALUES (?, ?, ?)");
        $stmt->execute([$table_id, $utilisateur_id, 'ouverte']);
        $newId = $pdo->lastInsertId();
        
        if ($table_id) {
            $pdo->prepare("UPDATE tables_resto SET statut='occupée' WHERE id=?")->execute([$table_id]);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM factures WHERE id = ?");
        $stmt->execute([$newId]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        
        sendJSON(['success' => true, 'data' => $facture, 'message' => 'Facture créée']);
    }
    
    // 4. POST /factures/{id}/lignes
    if ($resource === 'factures' && $method === 'POST' && $id !== null && !$routeFound) {
        $parts2 = explode('/', $uri);
        $action = $parts2[2] ?? '';
        
        if ($action === 'lignes') {
            $routeFound = true;
            $facture_id = (int)$id;
            $produit_id = isset($input['produit_id']) ? (int)$input['produit_id'] : 0;
            $quantite = isset($input['quantite']) ? (int)$input['quantite'] : 1;
            
            $check = $pdo->prepare("SELECT id FROM factures WHERE id = ?");
            $check->execute([$facture_id]);
            if (!$check->fetch()) {
                sendJSON(['success' => false, 'error' => 'Facture non trouvée'], 404);
                exit;
            }
            
            $stmt = $pdo->prepare("SELECT id, prix FROM produits WHERE id = ? AND disponible = 1");
            $stmt->execute([$produit_id]);
            $produit = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$produit) {
                sendJSON(['success' => false, 'error' => 'Produit non disponible'], 404);
                exit;
            }
            
            $stmt = $pdo->prepare("INSERT INTO lignes_facture (facture_id, produit_id, quantite, prix_unitaire) VALUES (?, ?, ?, ?)");
            $stmt->execute([$facture_id, $produit_id, $quantite, $produit['prix']]);
            
            $stmt = $pdo->prepare("SELECT SUM(quantite * prix_unitaire) as total FROM lignes_facture WHERE facture_id = ?");
            $stmt->execute([$facture_id]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            $taxes = $total * 0.18;
            $totalTTC = $total + $taxes;
            
            $pdo->prepare("UPDATE factures SET sous_total = ?, taxes = ?, total = ? WHERE id = ?")->execute([$total, $taxes, $totalTTC, $facture_id]);
            
            sendJSON([
                'success' => true,
                'totaux' => [
                    'sous_total' => number_format($total, 2, '.', ''),
                    'taxes' => number_format($taxes, 2, '.', ''),
                    'total' => number_format($totalTTC, 2, '.', '')
                ],
                'message' => 'Ligne ajoutée'
            ]);
        }
    }
    
    // 5. GET /factures
    if ($resource === 'factures' && $method === 'GET' && $id === null && !$routeFound) {
        $routeFound = true;
        $stmt = $pdo->query("SELECT f.*, t.numero as table_num FROM factures f LEFT JOIN tables_resto t ON t.id = f.table_id ORDER BY f.created_at DESC LIMIT 50");
        sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    
    // 6. GET /factures/{id}
    if ($resource === 'factures' && $method === 'GET' && $id !== null && !$routeFound) {
        $routeFound = true;
        $facture_id = (int)$id;
        $stmt = $pdo->prepare("SELECT f.*, t.numero as table_num FROM factures f LEFT JOIN tables_resto t ON t.id = f.table_id WHERE f.id = ?");
        $stmt->execute([$facture_id]);
        $facture = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$facture) {
            sendJSON(['success' => false, 'error' => 'Facture non trouvée'], 404);
            exit;
        }
        
        $stmt = $pdo->prepare("SELECT lf.*, p.nom as produit_nom, p.code as produit_code FROM lignes_facture lf JOIN produits p ON p.id = lf.produit_id WHERE lf.facture_id = ?");
        $stmt->execute([$facture_id]);
        $facture['lignes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJSON(['success' => true, 'data' => $facture]);
    }
    
    // 7. PUT /factures/{id}
    if ($resource === 'factures' && $method === 'PUT' && $id !== null && !$routeFound) {
        $routeFound = true;
        $facture_id = (int)$id;
        $statut = isset($input['statut']) ? $input['statut'] : 'payée';
        $mode_paiement = isset($input['mode_paiement']) ? $input['mode_paiement'] : 'espèces';
        
        $pdo->prepare("UPDATE factures SET statut = ?, mode_paiement = ? WHERE id = ?")->execute([$statut, $mode_paiement, $facture_id]);
        
        if ($statut === 'payée') {
            $stmt = $pdo->prepare("SELECT table_id FROM factures WHERE id = ?");
            $stmt->execute([$facture_id]);
            $table = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($table && $table['table_id']) {
                $pdo->prepare("UPDATE tables_resto SET statut = 'libre' WHERE id = ?")->execute([$table['table_id']]);
            }
        }
        
        sendJSON(['success' => true, 'message' => 'Facture mise à jour']);
    }
    
    // 8. Route par défaut
    if (!$routeFound) {
        sendJSON([
            'success' => true,
            'message' => 'API Restaurant - POS v3.0 (avec trigger)',
            'version' => '3.0 - Trigger MySQL',
            'endpoints' => [
                'GET /api/produits?q=BOI' => 'Rechercher des produits',
                'GET /api/categories' => 'Liste des catégories',
                'POST /api/factures' => 'Créer une commande (numéro généré par trigger)',
                'POST /api/factures/{id}/lignes' => 'Ajouter un produit',
                'GET /api/factures' => 'Toutes les factures',
                'GET /api/factures/{id}' => 'Détail facture',
                'PUT /api/factures/{id}' => 'Payer facture',
                'GET /api/test' => 'Tester l\'API'
            ]
        ]);
    }
    
} catch (PDOException $e) {
    sendJSON(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    sendJSON(['success' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}
