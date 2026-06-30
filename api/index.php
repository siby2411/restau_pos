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
    sendJSON(['success' => true, 'message' => 'API fonctionne ! Version stable', 'time' => date('Y-m-d H:i:s')]);
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
    
    // Gérer les données POST (JSON ou FormData)
    $input = [];
    $isFormData = false;
    
    if ($method === 'POST' || $method === 'PUT') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false) {
            $isFormData = true;
            // Pour FormData, on récupère les données normales
            foreach ($_POST as $key => $value) {
                $input[$key] = $value;
            }
            // Gérer le fichier uploadé
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $filename = time() . '_' . uniqid() . '.' . $ext;
                $targetPath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                    $input['image_url'] = '/uploads/' . $filename;
                }
            }
        } else {
            $body = file_get_contents('php://input');
            if ($body) {
                $input = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $input = [];
                }
            }
        }
    }
    
    $routeFound = false;
    
    // 1. GET /produits
    if ($resource === 'produits' && $method === 'GET' && !$routeFound) {
        $routeFound = true;
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $cat = isset($_GET['categorie']) ? $_GET['categorie'] : null;
        $sql = "SELECT p.*, c.nom as cat_nom, c.couleur as cat_couleur 
                FROM produits p 
                LEFT JOIN categories c ON c.id = p.categorie_id";
        $params = [];
        $conditions = [];
        
        if (!empty($q)) {
            $conditions[] = "(p.code LIKE ? OR p.nom LIKE ? OR p.description LIKE ?)";
            $search = "%$q%";
            $params = array_merge($params, [$search, $search, $search]);
        }
        
        if ($cat && $cat !== 'all') {
            $conditions[] = "p.categorie_id = ?";
            $params[] = $cat;
        }
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
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
    
    // 3. POST /produits - avec gestion d'image
    if ($resource === 'produits' && $method === 'POST' && !$routeFound) {
        $routeFound = true;
        $code = $input['code'] ?? generateProductCode($pdo, $input['categorie_id'] ?? 1);
        $image_url = $input['image_url'] ?? null;
        
        $stmt = $pdo->prepare("INSERT INTO produits (code, nom, description, prix, prix_achat, categorie_id, stock, unite, disponible, image_url) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $code,
            $input['nom'],
            $input['description'] ?? '',
            $input['prix'] ?? 0,
            $input['prix_achat'] ?? 0,
            $input['categorie_id'] ?? null,
            $input['stock'] ?? 0,
            $input['unite'] ?? 'portion',
            $input['disponible'] ?? 1,
            $image_url
        ]);
        $newId = $pdo->lastInsertId();
        $stmt2 = $pdo->prepare("SELECT * FROM produits WHERE id=?");
        $stmt2->execute([$newId]);
        sendJSON(['success' => true, 'data' => $stmt2->fetch(), 'message' => 'Produit créé']);
    }
    
    // 4. PUT /produits/{id} - avec gestion d'image
    if ($resource === 'produits' && $method === 'PUT' && $id !== null && !$routeFound) {
        $routeFound = true;
        $image_url = $input['image_url'] ?? null;
        
        $stmt = $pdo->prepare("UPDATE produits SET nom=?, description=?, prix=?, prix_achat=?, categorie_id=?, stock=?, unite=?, disponible=?, image_url=? WHERE id=?");
        $stmt->execute([
            $input['nom'],
            $input['description'] ?? '',
            $input['prix'] ?? 0,
            $input['prix_achat'] ?? 0,
            $input['categorie_id'] ?? null,
            $input['stock'] ?? 0,
            $input['unite'] ?? 'portion',
            $input['disponible'] ?? 1,
            $image_url,
            $id
        ]);
        sendJSON(['success' => true, 'message' => 'Produit mis à jour']);
    }
    
    // 5. DELETE /produits/{id}
    if ($resource === 'produits' && $method === 'DELETE' && $id !== null && !$routeFound) {
        $routeFound = true;
        $pdo->prepare("DELETE FROM produits WHERE id=?")->execute([$id]);
        sendJSON(['success' => true, 'message' => 'Produit supprimé']);
    }
    
    // 6. POST /factures
    if ($resource === 'factures' && $method === 'POST' && $id === null && !$routeFound) {
        $routeFound = true;
        $table_id = isset($input['table_id']) ? (int)$input['table_id'] : 1;
        $utilisateur_id = isset($input['utilisateur_id']) ? (int)$input['utilisateur_id'] : 1;
        
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
    
    // 7. POST /factures/{id}/lignes
    if ($resource === 'factures' && $method === 'POST' && $id !== null && !$routeFound) {
        $parts2 = explode('/', $uri);
        $action = $parts2[2] ?? '';
        
        if ($action === 'lignes') {
            $routeFound = true;
            $facture_id = (int)$id;
            $produit_id = isset($input['produit_id']) ? (int)$input['produit_id'] : 0;
            $quantite = isset($input['quantite']) ? (int)$input['quantite'] : 1;
            
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
    
    // 8. DELETE /factures/{id}/lignes/{ligne_id}
    if ($resource === 'factures' && $method === 'DELETE' && $id !== null && !$routeFound) {
        $parts2 = explode('/', $uri);
        $ligne_id = $parts2[3] ?? null;
        if ($ligne_id) {
            $routeFound = true;
            $pdo->prepare("DELETE FROM lignes_facture WHERE id = ? AND facture_id = ?")->execute([$ligne_id, $id]);
            
            // Recalculer les totaux
            $stmt = $pdo->prepare("SELECT SUM(quantite * prix_unitaire) as total FROM lignes_facture WHERE facture_id = ?");
            $stmt->execute([$id]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            $taxes = $total * 0.18;
            $totalTTC = $total + $taxes;
            $pdo->prepare("UPDATE factures SET sous_total = ?, taxes = ?, total = ? WHERE id = ?")->execute([$total, $taxes, $totalTTC, $id]);
            
            sendJSON(['success' => true, 'message' => 'Ligne supprimée']);
        }
    }
    
    // 9. GET /factures
    if ($resource === 'factures' && $method === 'GET' && $id === null && !$routeFound) {
        $routeFound = true;
        $stmt = $pdo->query("SELECT f.*, t.numero as table_num FROM factures f LEFT JOIN tables_resto t ON t.id = f.table_id ORDER BY f.created_at DESC LIMIT 50");
        sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    
    // 10. GET /factures/{id}
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
    
    // 11. PUT /factures/{id}
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
    
    // 12. GET /dashboard
    if ($resource === 'dashboard' && !$routeFound) {
        $routeFound = true;
        $stats = [];
        $stats['chiffre_affaires_jour'] = $pdo->query("SELECT COALESCE(SUM(total),0) FROM factures WHERE DATE(created_at)=CURDATE() AND statut='payée'")->fetchColumn() ?? 0;
        $stats['commandes_jour'] = $pdo->query("SELECT COUNT(*) FROM factures WHERE DATE(created_at)=CURDATE()")->fetchColumn() ?? 0;
        $stats['tables_occupees'] = $pdo->query("SELECT COUNT(*) FROM tables_resto WHERE statut='occupée'")->fetchColumn() ?? 0;
        $stats['tables_total'] = $pdo->query("SELECT COUNT(*) FROM tables_resto")->fetchColumn() ?? 0;
        $stats['reservations_aujourd_hui'] = $pdo->query("SELECT COUNT(*) FROM reservations WHERE date_reservation=CURDATE() AND statut!='annulée'")->fetchColumn() ?? 0;
        
        $stats['ca_semaine'] = $pdo->query("SELECT COALESCE(SUM(total),0) FROM factures WHERE WEEK(created_at)=WEEK(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND statut='payée'")->fetchColumn() ?? 0;
        $stats['ca_mois'] = $pdo->query("SELECT COALESCE(SUM(total),0) FROM factures WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND statut='payée'")->fetchColumn() ?? 0;
        
        $stats['top_produits'] = $pdo->query("SELECT p.nom, SUM(lf.quantite) as qte, SUM(lf.total_ligne) as ca FROM lignes_facture lf JOIN produits p ON p.id=lf.produit_id JOIN factures f ON f.id=lf.facture_id WHERE f.statut='payée' AND MONTH(f.created_at)=MONTH(NOW()) GROUP BY p.id ORDER BY qte DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        
        sendJSON(['success' => true, 'data' => $stats]);
    }
    
    // 13. GET /tables
    if ($resource === 'tables' && !$routeFound) {
        $routeFound = true;
        $stmt = $pdo->query("SELECT * FROM tables_resto ORDER BY zone, numero");
        sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    
    // 14. GET /reservations
    if ($resource === 'reservations' && $method === 'GET' && !$routeFound) {
        $routeFound = true;
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $pdo->prepare("SELECT r.*, CONCAT(c.prenom,' ',c.nom) as client_nom, c.telephone, t.numero as table_num, t.capacite FROM reservations r LEFT JOIN clients c ON c.id=r.client_id LEFT JOIN tables_resto t ON t.id=r.table_id WHERE r.date_reservation=? ORDER BY r.heure_debut");
        $stmt->execute([$date]);
        sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    
    // 15. POST /reservations
    if ($resource === 'reservations' && $method === 'POST' && !$routeFound) {
        $routeFound = true;
        $stmt = $pdo->prepare("INSERT INTO reservations (client_id, table_id, date_reservation, heure_debut, heure_fin, nb_personnes, notes, statut) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $input['client_id'] ?? null,
            $input['table_id'] ?? null,
            $input['date_reservation'],
            $input['heure_debut'],
            $input['heure_fin'] ?? null,
            $input['nb_personnes'] ?? 1,
            $input['notes'] ?? null,
            'confirmée'
        ]);
        sendJSON(['success' => true, 'message' => 'Réservation créée', 'id' => $pdo->lastInsertId()]);
    }
    
    // 16. GET /clients
    if ($resource === 'clients' && $method === 'GET' && !$routeFound) {
        $routeFound = true;
        $q = $_GET['q'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE nom LIKE ? OR telephone LIKE ? ORDER BY nom LIMIT 50");
        $stmt->execute(["%$q%", "%$q%"]);
        sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    
    // 17. POST /clients
    if ($resource === 'clients' && $method === 'POST' && !$routeFound) {
        $routeFound = true;
        $stmt = $pdo->prepare("INSERT INTO clients (nom, prenom, telephone, email, adresse, notes) VALUES (?,?,?,?,?,?)");
        $stmt->execute([
            $input['nom'],
            $input['prenom'] ?? '',
            $input['telephone'] ?? '',
            $input['email'] ?? '',
            $input['adresse'] ?? '',
            $input['notes'] ?? ''
        ]);
        sendJSON(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Client créé']);
    }
    
    // 18. GET /financier
    if ($resource === 'financier' && !$routeFound) {
        $routeFound = true;
        $debut = $_GET['debut'] ?? date('Y-m-01');
        $fin = $_GET['fin'] ?? date('Y-m-t');
        
        $report = [];
        $report['periode'] = ['debut' => $debut, 'fin' => $fin];
        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) as total, COALESCE(SUM(sous_total),0) as ht, COALESCE(SUM(taxes),0) as tva, COUNT(*) as nb_factures FROM factures WHERE statut='payée' AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$debut, $fin]);
        $report['revenus'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT mode_paiement, COUNT(*) as nb, COALESCE(SUM(total),0) as montant FROM factures WHERE statut='payée' AND DATE(created_at) BETWEEN ? AND ? GROUP BY mode_paiement");
        $stmt->execute([$debut, $fin]);
        $report['par_mode'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT p.nom, p.code, SUM(lf.quantite) as qte, SUM(lf.total_ligne) as ca FROM lignes_facture lf JOIN produits p ON p.id=lf.produit_id JOIN factures f ON f.id=lf.facture_id WHERE f.statut='payée' AND DATE(f.created_at) BETWEEN ? AND ? GROUP BY p.id ORDER BY ca DESC LIMIT 10");
        $stmt->execute([$debut, $fin]);
        $report['top_produits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJSON(['success' => true, 'data' => $report]);
    }
    
    // 19. Route par défaut
    if (!$routeFound) {
        sendJSON([
            'success' => true,
            'message' => 'API Restaurant - POS v3.0',
            'version' => '3.0 - Stable',
            'endpoints' => [
                'GET /api/produits' => 'Liste des produits',
                'POST /api/produits' => 'Créer un produit (multipart/form-data pour image)',
                'PUT /api/produits/{id}' => 'Modifier un produit',
                'DELETE /api/produits/{id}' => 'Supprimer un produit',
                'GET /api/categories' => 'Liste des catégories',
                'POST /api/factures' => 'Créer une commande',
                'POST /api/factures/{id}/lignes' => 'Ajouter un produit',
                'GET /api/factures' => 'Toutes les factures',
                'GET /api/factures/{id}' => 'Détail facture',
                'PUT /api/factures/{id}' => 'Payer facture',
                'GET /api/dashboard' => 'Tableau de bord',
                'GET /api/tables' => 'Liste des tables',
                'GET /api/reservations' => 'Réservations',
                'POST /api/reservations' => 'Créer réservation',
                'GET /api/clients' => 'Liste des clients',
                'POST /api/clients' => 'Créer client',
                'GET /api/financier' => 'État financier'
            ]
        ]);
    }
    
} catch (PDOException $e) {
    sendJSON(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    sendJSON(['success' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

function generateProductCode($pdo, $categorieId) {
    $cat = $pdo->prepare("SELECT nom FROM categories WHERE id=?");
    $cat->execute([$categorieId]);
    $catNom = $cat->fetch(PDO::FETCH_ASSOC)['nom'] ?? 'PROD';
    $prefix = strtoupper(substr($catNom, 0, 3));
    
    $stmt = $pdo->prepare("SELECT COUNT(*) + 1 as nb FROM produits WHERE categorie_id=?");
    $stmt->execute([$categorieId]);
    $nb = $stmt->fetch(PDO::FETCH_ASSOC)['nb'] ?? 1;
    
    return $prefix . '-' . str_pad($nb, 4, '0', STR_PAD_LEFT);
}
