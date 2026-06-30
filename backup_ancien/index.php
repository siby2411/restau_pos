<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$input = getInput();

// Gestion universelle de l'action : ?action=xxx ou index.php/xxx
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('/', trim($uri, '/'));
$action = $_GET['action'] ?? (end($parts) !== 'index.php' ? end($parts) : '');

switch ($action) {
    case 'dashboard':
        $stats = [];
        $stats['chiffre_affaires_jour'] = $db->query("SELECT COALESCE(SUM(total),0) as val FROM factures WHERE DATE(created_at)=CURDATE() AND statut='payée'")->fetch()['val'];
        $stats['commandes_jour'] = $db->query("SELECT COUNT(*) as val FROM factures WHERE DATE(created_at)=CURDATE()")->fetch()['val'];
        jsonResponse(['success' => true, 'data' => $stats]);
        break;

    case 'produits':
        $sql = "SELECT p.*, c.nom as cat_nom, c.couleur as cat_couleur 
                FROM produits p 
                LEFT JOIN categories c ON c.id=p.categorie_id 
                ORDER BY p.nom";
        $data = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['success' => true, 'data' => $data]);
        break;

    case 'categories':
        jsonResponse(['success' => true, 'data' => $db->query("SELECT * FROM categories ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    default:
        jsonResponse(['error' => 'Action non reconnue', 'action' => $action], 404);
}
?>
