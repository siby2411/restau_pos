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
    sendJSON(['success' => true, 'message' => 'API fonctionne !', 'time' => date('Y-m-d H:i:s')]);
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
    $isFormData = false;
    $uploadedFile = null;
    
    if ($method === 'POST' || $method === 'PUT') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false) {
            $isFormData = true;
            foreach ($_POST as $key => $value) {
                $input[$key] = $value;
            }
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $filename = time() . '_' . uniqid() . '.' . $ext;
                $targetPath = $uploadDir . $filename;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                    $uploadedFile = '/uploads/' . $filename;
                    $input['image_url'] = $uploadedFile;
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
    
    // ============================================================
    // 1. PRODUITS
    // ============================================================
    if ($resource === 'produits') {
        $routeFound = true;
        
        if ($method === 'GET' && !$id) {
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
        
        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare("SELECT p.*, c.nom as cat_nom, c.couleur as cat_couleur 
                                   FROM produits p 
                                   LEFT JOIN categories c ON c.id = p.categorie_id 
                                   WHERE p.id = ?");
            $stmt->execute([$id]);
            $produit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$produit) {
                sendJSON(['success' => false, 'error' => 'Produit non trouvé'], 404);
            }
            sendJSON(['success' => true, 'data' => $produit]);
        }
        
        if ($method === 'POST' && !$id) {
            if (empty($input['nom']) || empty($input['prix'])) {
                sendJSON(['success' => false, 'error' => 'Le nom et le prix sont obligatoires'], 400);
            }
            
            $code = $input['code'] ?? generateProductCode($pdo, $input['categorie_id'] ?? 1);
            $image_url = $input['image_url'] ?? null;
            
            $stmt = $pdo->prepare("INSERT INTO produits (code, nom, description, prix, prix_achat, categorie_id, stock, unite, disponible, image_url) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $code,
                $input['nom'],
                $input['description'] ?? '',
                $input['prix'],
                $input['prix_achat'] ?? 0,
                $input['categorie_id'] ?? null,
                $input['stock'] ?? 0,
                $input['unite'] ?? 'portion',
                $input['disponible'] ?? 1,
                $image_url
            ]);
            $newId = $pdo->lastInsertId();
            
            $stmt2 = $pdo->prepare("SELECT p.*, c.nom as cat_nom, c.couleur as cat_couleur 
                                    FROM produits p 
                                    LEFT JOIN categories c ON c.id = p.categorie_id 
                                    WHERE p.id = ?");
            $stmt2->execute([$newId]);
            sendJSON(['success' => true, 'data' => $stmt2->fetch(), 'message' => 'Produit créé avec succès']);
        }
        
        if ($method === 'PUT' && $id) {
            $check = $pdo->prepare("SELECT id FROM produits WHERE id = ?");
            $check->execute([$id]);
            if (!$check->fetch()) {
                sendJSON(['success' => false, 'error' => 'Produit non trouvé'], 404);
            }
            
            $stmt = $pdo->prepare("SELECT image_url FROM produits WHERE id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $updateFields = [];
            $params = [];
            $hasData = false;
            
            if (isset($input['nom']) && $input['nom'] !== '') {
                $updateFields[] = "nom = ?";
                $params[] = $input['nom'];
                $hasData = true;
            }
            if (isset($input['description'])) {
                $updateFields[] = "description = ?";
                $params[] = $input['description'];
                $hasData = true;
            }
            if (isset($input['prix']) && $input['prix'] !== '') {
                $updateFields[] = "prix = ?";
                $params[] = $input['prix'];
                $hasData = true;
            }
            if (isset($input['prix_achat']) && $input['prix_achat'] !== '') {
                $updateFields[] = "prix_achat = ?";
                $params[] = $input['prix_achat'];
                $hasData = true;
            }
            if (isset($input['categorie_id']) && $input['categorie_id'] !== '') {
                $updateFields[] = "categorie_id = ?";
                $params[] = $input['categorie_id'];
                $hasData = true;
            }
            if (isset($input['stock']) && $input['stock'] !== '') {
                $updateFields[] = "stock = ?";
                $params[] = $input['stock'];
                $hasData = true;
            }
            if (isset($input['unite']) && $input['unite'] !== '') {
                $updateFields[] = "unite = ?";
                $params[] = $input['unite'];
                $hasData = true;
            }
            if (isset($input['disponible'])) {
                $updateFields[] = "disponible = ?";
                $params[] = $input['disponible'];
                $hasData = true;
            }
            
            if (isset($input['image_url']) && $input['image_url'] !== '') {
                $updateFields[] = "image_url = ?";
                $params[] = $input['image_url'];
                $hasData = true;
                if ($current && $current['image_url']) {
                    $oldImagePath = __DIR__ . '/..' . $current['image_url'];
                    if (file_exists($oldImagePath)) {
                        unlink($oldImagePath);
                    }
                }
            }
            
            if (!$hasData) {
                sendJSON(['success' => false, 'error' => 'Aucune donnée à mettre à jour'], 400);
                return;
            }
            
            $params[] = $id;
            $sql = "UPDATE produits SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $stmt2 = $pdo->prepare("SELECT p.*, c.nom as cat_nom, c.couleur as cat_couleur 
                                    FROM produits p 
                                    LEFT JOIN categories c ON c.id = p.categorie_id 
                                    WHERE p.id = ?");
            $stmt2->execute([$id]);
            $updated = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            sendJSON(['success' => true, 'data' => $updated, 'message' => 'Produit mis à jour avec succès']);
        }
        
        if ($method === 'DELETE' && $id) {
            $check = $pdo->prepare("SELECT id, image_url FROM produits WHERE id = ?");
            $check->execute([$id]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                sendJSON(['success' => false, 'error' => 'Produit non trouvé'], 404);
            }
            
            $checkLignes = $pdo->prepare("SELECT COUNT(*) FROM lignes_facture WHERE produit_id = ?");
            $checkLignes->execute([$id]);
            if ($checkLignes->fetchColumn() > 0) {
                sendJSON(['success' => false, 'error' => 'Ce produit est utilisé dans des factures, suppression impossible'], 400);
            }
            
            if ($existing['image_url']) {
                $imagePath = __DIR__ . '/..' . $existing['image_url'];
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
            }
            
            $stmt = $pdo->prepare("DELETE FROM produits WHERE id = ?");
            $stmt->execute([$id]);
            sendJSON(['success' => true, 'message' => 'Produit supprimé avec succès']);
        }
    }
    
    // ============================================================
    // 2. CATEGORIES
    // ============================================================
    if ($resource === 'categories' && !$routeFound) {
        $routeFound = true;
        if ($method === 'GET') {
            $stmt = $pdo->query("SELECT * FROM categories ORDER BY nom");
            sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        if ($method === 'POST') {
            $stmt = $pdo->prepare("INSERT INTO categories (nom, couleur, icone) VALUES (?,?,?)");
            $stmt->execute([$input['nom'], $input['couleur'] ?? '#6366f1', $input['icone'] ?? 'tag']);
            sendJSON(['success' => true, 'message' => 'Catégorie créée', 'id' => $pdo->lastInsertId()]);
        }
    }
    
    // ============================================================
    // 3. FACTURES
    // ============================================================
    if ($resource === 'factures' && !$routeFound) {
        $routeFound = true;
        
        if ($method === 'GET' && !$id) {
            $stmt = $pdo->query("SELECT f.*, t.numero as table_num, 
                                (SELECT COUNT(*) FROM lignes_facture WHERE facture_id = f.id) as nb_lignes 
                                FROM factures f 
                                LEFT JOIN tables_resto t ON t.id = f.table_id 
                                WHERE f.statut != 'ouverte' OR f.total > 0
                                ORDER BY f.created_at DESC LIMIT 50");
            $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($factures as &$facture) {
                $idFacture = $facture['id'];
                $stmtTot = $pdo->prepare("SELECT COALESCE(SUM(quantite * prix_unitaire), 0) as total FROM lignes_facture WHERE facture_id = ?");
                $stmtTot->execute([$idFacture]);
                $totalLignes = $stmtTot->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
                $taxes = $totalLignes * 0.18;
                $totalTTC = $totalLignes + $taxes;
                $pdo->prepare("UPDATE factures SET sous_total = ?, taxes = ?, total = ? WHERE id = ?")->execute([$totalLignes, $taxes, $totalTTC, $idFacture]);
                $facture['sous_total'] = number_format($totalLignes, 2, '.', '');
                $facture['taxes'] = number_format($taxes, 2, '.', '');
                $facture['total'] = number_format($totalTTC, 2, '.', '');
            }
            sendJSON(['success' => true, 'data' => $factures]);
        }
        
        if ($method === 'GET' && $id) {
            $facture_id = (int)$id;
            $stmtTot = $pdo->prepare("SELECT COALESCE(SUM(quantite * prix_unitaire), 0) as total FROM lignes_facture WHERE facture_id = ?");
            $stmtTot->execute([$facture_id]);
            $totalLignes = $stmtTot->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            $taxes = $totalLignes * 0.18;
            $totalTTC = $totalLignes + $taxes;
            $pdo->prepare("UPDATE factures SET sous_total = ?, taxes = ?, total = ? WHERE id = ?")->execute([$totalLignes, $taxes, $totalTTC, $facture_id]);
            
            $stmt = $pdo->prepare("SELECT f.*, t.numero as table_num FROM factures f LEFT JOIN tables_resto t ON t.id = f.table_id WHERE f.id = ?");
            $stmt->execute([$facture_id]);
            $facture = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$facture) {
                sendJSON(['success' => false, 'error' => 'Facture non trouvée'], 404);
            }
            
            $stmt = $pdo->prepare("SELECT lf.*, p.nom as produit_nom, p.code as produit_code FROM lignes_facture lf JOIN produits p ON p.id = lf.produit_id WHERE lf.facture_id = ?");
            $stmt->execute([$facture_id]);
            $facture['lignes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            sendJSON(['success' => true, 'data' => $facture]);
        }
        
        if ($method === 'POST' && !$id) {
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
            sendJSON(['success' => true, 'data' => $stmt->fetch(), 'message' => 'Facture créée']);
        }
        
        if ($method === 'POST' && $id) {
            $parts2 = explode('/', $uri);
            $action = $parts2[2] ?? '';
            
            if ($action === 'lignes') {
                $facture_id = (int)$id;
                $produit_id = isset($input['produit_id']) ? (int)$input['produit_id'] : 0;
                $quantite = isset($input['quantite']) ? (int)$input['quantite'] : 1;
                
                $stmt = $pdo->prepare("SELECT id, prix FROM produits WHERE id = ? AND disponible = 1");
                $stmt->execute([$produit_id]);
                $produit = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$produit) {
                    sendJSON(['success' => false, 'error' => 'Produit non disponible'], 404);
                }
                
                $stmt = $pdo->prepare("INSERT INTO lignes_facture (facture_id, produit_id, quantite, prix_unitaire) VALUES (?, ?, ?, ?)");
                $stmt->execute([$facture_id, $produit_id, $quantite, $produit['prix']]);
                
                recalculerFacture($pdo, $facture_id);
                
                $stmt = $pdo->prepare("SELECT sous_total, taxes, total FROM factures WHERE id = ?");
                $stmt->execute([$facture_id]);
                sendJSON(['success' => true, 'totaux' => $stmt->fetch(), 'message' => 'Ligne ajoutée']);
            }
        }
        
        if ($method === 'DELETE' && $id) {
            $parts2 = explode('/', $uri);
            $ligne_id = $parts2[3] ?? null;
            if ($ligne_id) {
                $stmt = $pdo->prepare("DELETE FROM lignes_facture WHERE id = ? AND facture_id = ?");
                $stmt->execute([$ligne_id, $id]);
                recalculerFacture($pdo, $id);
                sendJSON(['success' => true, 'message' => 'Ligne supprimée']);
            }
        }
        
        if ($method === 'PUT' && $id) {
            $facture_id = (int)$id;
            $statut = isset($input['statut']) ? $input['statut'] : 'payée';
            $mode_paiement = isset($input['mode_paiement']) ? $input['mode_paiement'] : 'espèces';
            $client_id = isset($input['client_id']) ? (int)$input['client_id'] : null;
            
            recalculerFacture($pdo, $facture_id);
            
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE factures SET statut = ?, mode_paiement = ? WHERE id = ?")->execute([$statut, $mode_paiement, $facture_id]);
                
                if ($mode_paiement === 'qrcode' || $mode_paiement === 'qrcode_mobile') {
                    $reference = 'QR-' . date('YmdHis') . '-' . rand(1000, 9999);
                    
                    if (!$client_id) {
                        $stmt = $pdo->prepare("INSERT INTO clients (nom, prenom, telephone, email, adresse, notes) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            'Client QR',
                            'Anonyme',
                            'N/A',
                            'qr_' . time() . '@restaurant.com',
                            'Paiement QR Code',
                            'Client créé automatiquement'
                        ]);
                        $client_id = $pdo->lastInsertId();
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO transactions_qr (facture_id, client_id, montant, mode_paiement, statut, reference, date_paiement) 
                                           VALUES (?, ?, ?, ?, 'payé', ?, NOW())");
                    $stmt->execute([$facture_id, $client_id, $facture['total'] ?? 0, $mode_paiement, $reference]);
                    
                    $stmt = $pdo->prepare("UPDATE clients SET solde = solde - ?, total_achats = total_achats + ? WHERE id = ?");
                    $stmt->execute([$facture['total'] ?? 0, $facture['total'] ?? 0, $client_id]);
                }
                
                $stmt = $pdo->prepare("SELECT table_id FROM factures WHERE id = ?");
                $stmt->execute([$facture_id]);
                $table = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($table && $table['table_id']) {
                    $pdo->prepare("UPDATE tables_resto SET statut = 'libre' WHERE id = ?")->execute([$table['table_id']]);
                }
                
                $pdo->commit();
                
                $stmt = $pdo->prepare("SELECT * FROM factures WHERE id = ?");
                $stmt->execute([$facture_id]);
                $facture = $stmt->fetch(PDO::FETCH_ASSOC);
                sendJSON(['success' => true, 'data' => $facture, 'message' => 'Paiement effectué avec succès']);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }
    
    // ============================================================
    // 4. DASHBOARD
    // ============================================================
    if ($resource === 'dashboard' && !$routeFound) {
        $routeFound = true;
        $stats = [];
        $stats['chiffre_affaires_jour'] = $pdo->query("SELECT COALESCE(SUM(total),0) FROM factures WHERE DATE(created_at)=CURDATE() AND statut='payée'")->fetchColumn() ?? 0;
        $stats['commandes_jour'] = $pdo->query("SELECT COUNT(*) FROM factures WHERE DATE(created_at)=CURDATE() AND statut='payée'")->fetchColumn() ?? 0;
        $stats['tables_occupees'] = $pdo->query("SELECT COUNT(*) FROM tables_resto WHERE statut='occupée'")->fetchColumn() ?? 0;
        $stats['tables_total'] = $pdo->query("SELECT COUNT(*) FROM tables_resto")->fetchColumn() ?? 0;
        $stats['reservations_aujourd_hui'] = $pdo->query("SELECT COUNT(*) FROM reservations WHERE date_reservation=CURDATE() AND statut!='annulée'")->fetchColumn() ?? 0;
        $stats['ca_semaine'] = $pdo->query("SELECT COALESCE(SUM(total),0) FROM factures WHERE WEEK(created_at)=WEEK(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND statut='payée'")->fetchColumn() ?? 0;
        $stats['ca_mois'] = $pdo->query("SELECT COALESCE(SUM(total),0) FROM factures WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW()) AND statut='payée'")->fetchColumn() ?? 0;
        
        $stats['top_produits_qte'] = $pdo->query("SELECT p.nom, p.code, SUM(lf.quantite) as qte, SUM(lf.total_ligne) as ca 
            FROM lignes_facture lf 
            JOIN produits p ON p.id = lf.produit_id 
            JOIN factures f ON f.id = lf.facture_id 
            WHERE f.statut='payée' AND MONTH(f.created_at)=MONTH(NOW()) 
            GROUP BY p.id 
            ORDER BY qte DESC 
            LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        
        $stats['top_produits_ca'] = $pdo->query("SELECT p.nom, p.code, SUM(lf.quantite) as qte, SUM(lf.total_ligne) as ca 
            FROM lignes_facture lf 
            JOIN produits p ON p.id = lf.produit_id 
            JOIN factures f ON f.id = lf.facture_id 
            WHERE f.statut='payée' AND MONTH(f.created_at)=MONTH(NOW()) 
            GROUP BY p.id 
            ORDER BY ca DESC 
            LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        
        sendJSON(['success' => true, 'data' => $stats]);
    }
    
    // ============================================================
    // 5. TABLES
    // ============================================================
    if ($resource === 'tables' && !$routeFound) {
        $routeFound = true;
        if ($method === 'GET') {
            $stmt = $pdo->query("SELECT * FROM tables_resto ORDER BY zone, numero");
            sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        if ($method === 'PUT' && $id) {
            $stmt = $pdo->prepare("UPDATE tables_resto SET statut = ? WHERE id = ?");
            $stmt->execute([$input['statut'], $id]);
            sendJSON(['success' => true, 'message' => 'Table mise à jour']);
        }
    }
    
    // ============================================================
    // 6. RESERVATIONS
    // ============================================================
    if ($resource === 'reservations' && !$routeFound) {
        $routeFound = true;
        
        if ($method === 'GET') {
            $date_debut = $_GET['date_debut'] ?? date('Y-m-d');
            $date_fin = $_GET['date_fin'] ?? date('Y-m-d');
            
            $stmt = $pdo->prepare("SELECT r.*, 
                                   CONCAT(c.prenom,' ',c.nom) as client_nom, 
                                   c.telephone, 
                                   t.numero as table_num, 
                                   t.capacite 
                                   FROM reservations r 
                                   LEFT JOIN clients c ON c.id = r.client_id 
                                   LEFT JOIN tables_resto t ON t.id = r.table_id 
                                   WHERE r.date_reservation BETWEEN ? AND ? 
                                   ORDER BY r.date_reservation, r.heure_debut");
            $stmt->execute([$date_debut, $date_fin]);
            sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        
        if ($method === 'POST') {
            $date_reservation = $input['date_reservation'];
            $heure_debut = $input['heure_debut'];
            $heure_fin = $input['heure_fin'] ?? null;
            $table_id = $input['table_id'] ?? null;
            
            if ($table_id) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM reservations 
                                        WHERE table_id = ? 
                                        AND date_reservation = ? 
                                        AND statut != 'annulée'
                                        AND (
                                            (heure_debut <= ? AND heure_fin >= ?) 
                                            OR (heure_debut <= ? AND heure_fin >= ?)
                                            OR (heure_debut >= ? AND heure_debut < ?)
                                        )");
                $check->execute([
                    $table_id, 
                    $date_reservation, 
                    $heure_debut, $heure_debut,
                    $heure_fin ?? $heure_debut, $heure_fin ?? $heure_debut,
                    $heure_debut, $heure_fin ?? $heure_debut
                ]);
                $conflicts = $check->fetchColumn();
                
                if ($conflicts > 0) {
                    sendJSON(['success' => false, 'error' => 'Cette table est déjà réservée à cette heure'], 409);
                }
            }
            
            $stmt = $pdo->prepare("INSERT INTO reservations (client_id, table_id, date_reservation, heure_debut, heure_fin, nb_personnes, notes, statut) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $input['client_id'] ?? null,
                $input['table_id'] ?? null,
                $date_reservation,
                $heure_debut,
                $heure_fin ?? null,
                $input['nb_personnes'] ?? 1,
                $input['notes'] ?? null,
                'confirmée'
            ]);
            sendJSON(['success' => true, 'message' => 'Réservation créée', 'id' => $pdo->lastInsertId()]);
        }
    }
    
    // ============================================================
    // 7. CLIENTS
    // ============================================================
    if ($resource === 'clients' && !$routeFound) {
        $routeFound = true;
        if ($method === 'GET' && !$id) {
            $q = $_GET['q'] ?? '';
            $stmt = $pdo->prepare("SELECT id, nom, prenom, telephone, email, adresse, notes, solde, total_achats, created_at 
                                   FROM clients 
                                   WHERE nom LIKE ? OR telephone LIKE ? OR email LIKE ? 
                                   ORDER BY nom LIMIT 50");
            $search = "%$q%";
            $stmt->execute([$search, $search, $search]);
            sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        
        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare("SELECT c.*, 
                                   (SELECT COUNT(*) FROM transactions_qr WHERE client_id = c.id AND statut = 'payé') as nb_transactions,
                                   (SELECT SUM(montant) FROM transactions_qr WHERE client_id = c.id AND statut = 'payé') as total_depense
                                   FROM clients c WHERE c.id = ?");
            $stmt->execute([$id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                sendJSON(['success' => false, 'error' => 'Client non trouvé'], 404);
            }
            sendJSON(['success' => true, 'data' => $client]);
        }
        
        if ($method === 'POST' && !$id) {
            $stmt = $pdo->prepare("INSERT INTO clients (nom, prenom, telephone, email, adresse, notes, solde) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([
                $input['nom'],
                $input['prenom'] ?? '',
                $input['telephone'] ?? '',
                $input['email'] ?? '',
                $input['adresse'] ?? '',
                $input['notes'] ?? '',
                $input['solde'] ?? 0
            ]);
            sendJSON(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Client créé']);
        }
        
        if ($method === 'PUT' && $id) {
            // ... code existant
        }
        
        if ($method === 'DELETE' && $id) {
            // ... code existant
        }
    }
    
    // ============================================================
    // 8. TRANSACTIONS QR
    // ============================================================
    if ($resource === 'transactions' && !$routeFound) {
        $routeFound = true;
        
        if ($method === 'GET' && !$id) {
            $statut = $_GET['statut'] ?? null;
            $date_debut = $_GET['date_debut'] ?? date('Y-m-01');
            $date_fin = $_GET['date_fin'] ?? date('Y-m-t');
            
            $sql = "SELECT t.*, f.numero as facture_numero, f.total as facture_total, 
                           CONCAT(c.prenom, ' ', c.nom) as client_nom, c.telephone as client_telephone
                    FROM transactions_qr t
                    LEFT JOIN factures f ON f.id = t.facture_id
                    LEFT JOIN clients c ON c.id = t.client_id
                    WHERE DATE(t.date_creation) BETWEEN ? AND ?";
            $params = [$date_debut, $date_fin];
            
            if ($statut) {
                $sql .= " AND t.statut = ?";
                $params[] = $statut;
            }
            
            $sql .= " ORDER BY t.date_creation DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            sendJSON(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        
        if ($method === 'GET' && $id) {
            $stmt = $pdo->prepare("SELECT t.*, f.numero as facture_numero, f.total as facture_total, 
                                   CONCAT(c.prenom, ' ', c.nom) as client_nom, c.telephone as client_telephone,
                                   c.solde as client_solde
                                   FROM transactions_qr t
                                   LEFT JOIN factures f ON f.id = t.facture_id
                                   LEFT JOIN clients c ON c.id = t.client_id
                                   WHERE t.id = ?");
            $stmt->execute([$id]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$transaction) {
                sendJSON(['success' => false, 'error' => 'Transaction non trouvée'], 404);
            }
            sendJSON(['success' => true, 'data' => $transaction]);
        }
        
        if ($method === 'PUT' && $id) {
            $statut = isset($input['statut']) ? $input['statut'] : 'payé';
            $stmt = $pdo->prepare("UPDATE transactions_qr SET statut = ? WHERE id = ?");
            $stmt->execute([$statut, $id]);
            sendJSON(['success' => true, 'message' => 'Transaction mise à jour']);
        }
    }
    
    // ============================================================
    // 9. QR CODE
    // ============================================================
    if ($resource === 'qrcode' && !$routeFound) {
        $routeFound = true;
        if ($method === 'GET' && $id) {
            $facture_id = (int)$id;
            $stmt = $pdo->prepare("SELECT f.*, t.numero as table_num, c.nom as client_nom, c.id as client_id 
                                   FROM factures f 
                                   LEFT JOIN tables_resto t ON t.id = f.table_id 
                                   LEFT JOIN clients c ON c.id = f.client_id 
                                   WHERE f.id = ?");
            $stmt->execute([$facture_id]);
            $facture = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$facture) {
                sendJSON(['success' => false, 'error' => 'Facture non trouvée'], 404);
            }
            
            $reference = 'QR-' . date('YmdHis') . '-' . rand(1000, 9999);
            
            $qrData = [
                't' => 'p',
                'i' => (string)$facture_id,
                'n' => $facture['numero'],
                'm' => (string)$facture['total'],
                'r' => $reference
            ];
            
            $qrJson = json_encode($qrData);
            $qrBase64 = base64_encode($qrJson);
            
            $stmt = $pdo->prepare("INSERT INTO transactions_qr (facture_id, client_id, montant, mode_paiement, statut, reference, qr_data) 
                                   VALUES (?, ?, ?, 'qrcode_mobile', 'en_attente', ?, ?)");
            $stmt->execute([
                $facture_id, 
                $facture['client_id'] ?? null, 
                $facture['total'], 
                $reference,
                $qrBase64
            ]);
            
            $transaction_id = $pdo->lastInsertId();
            $qrUrl = "http://localhost:8080/paiement.html?q=" . urlencode($qrBase64);
            
            sendJSON([
                'success' => true, 
                'data' => [
                    'qr_data' => $qrBase64,
                    'facture' => $facture,
                    'url' => $qrUrl,
                    'reference' => $reference,
                    'transaction_id' => $transaction_id
                ]
            ]);
        }
    }
    
    // ============================================================
    // 10. FINANCIER
    // ============================================================
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
        
        $stmt = $pdo->prepare("SELECT p.nom, p.code, SUM(lf.quantite) as qte, SUM(lf.total_ligne) as ca 
            FROM lignes_facture lf 
            JOIN produits p ON p.id = lf.produit_id 
            JOIN factures f ON f.id = lf.facture_id 
            WHERE f.statut='payée' AND DATE(f.created_at) BETWEEN ? AND ? 
            GROUP BY p.id 
            ORDER BY qte DESC 
            LIMIT 10");
        $stmt->execute([$debut, $fin]);
        $report['top_produits_qte'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT p.nom, p.code, SUM(lf.quantite) as qte, SUM(lf.total_ligne) as ca 
            FROM lignes_facture lf 
            JOIN produits p ON p.id = lf.produit_id 
            JOIN factures f ON f.id = lf.facture_id 
            WHERE f.statut='payée' AND DATE(f.created_at) BETWEEN ? AND ? 
            GROUP BY p.id 
            ORDER BY ca DESC 
            LIMIT 10");
        $stmt->execute([$debut, $fin]);
        $report['top_produits_ca'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT DATE(created_at) as jour, SUM(total) as ca, COUNT(*) as nb FROM factures WHERE statut='payée' AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY jour");
        $stmt->execute([$debut, $fin]);
        $report['evolution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT c.nom as categorie, c.couleur, SUM(lf.total_ligne) as ca, SUM(lf.quantite) as qte FROM lignes_facture lf JOIN produits p ON p.id=lf.produit_id JOIN categories c ON c.id=p.categorie_id JOIN factures f ON f.id=lf.facture_id WHERE f.statut='payée' AND DATE(f.created_at) BETWEEN ? AND ? GROUP BY c.id ORDER BY ca DESC");
        $stmt->execute([$debut, $fin]);
        $report['par_categorie'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJSON(['success' => true, 'data' => $report]);
    }
    
    // ============================================================
    // 11. Route par défaut
    // ============================================================
    if (!$routeFound) {
        sendJSON([
            'success' => true,
            'message' => 'API Restaurant - POS v4.0',
            'version' => '4.0 - QR Code',
            'endpoints' => [
                'GET /api/produits' => 'Liste des produits',
                'GET /api/categories' => 'Liste des catégories',
                'POST /api/factures' => 'Créer une commande',
                'GET /api/factures' => 'Toutes les factures',
                'PUT /api/factures/{id}' => 'Payer facture',
                'GET /api/qrcode/{id}' => 'Générer QR Code',
                'GET /api/transactions' => 'Liste des transactions QR',
                'GET /api/clients' => 'Liste des clients',
                'GET /api/dashboard' => 'Tableau de bord',
                'GET /api/financier' => 'État financier'
            ]
        ]);
    }
    
} catch (PDOException $e) {
    sendJSON(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    sendJSON(['success' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

// ============================================================
// FONCTIONS UTILITAIRES
// ============================================================

function recalculerFacture($pdo, $factureId) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantite * prix_unitaire), 0) as total FROM lignes_facture WHERE facture_id = ?");
    $stmt->execute([$factureId]);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    $taxes = $total * 0.18;
    $totalTTC = $total + $taxes;
    $pdo->prepare("UPDATE factures SET sous_total = ?, taxes = ?, total = ? WHERE id = ?")->execute([$total, $taxes, $totalTTC, $factureId]);
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
