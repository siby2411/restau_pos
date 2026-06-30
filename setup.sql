-- ============================================================
-- RESTAURANT MANAGEMENT SYSTEM - DATABASE SETUP
-- ============================================================

CREATE DATABASE IF NOT EXISTS restaurant_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE restaurant_db;

-- Categories de produits
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    couleur VARCHAR(7) DEFAULT '#6366f1',
    icone VARCHAR(50) DEFAULT 'utensils',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Produits / Menu
CREATE TABLE IF NOT EXISTS produits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) UNIQUE,
    nom VARCHAR(150) NOT NULL,
    description TEXT,
    prix DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    prix_achat DECIMAL(10,2) DEFAULT 0.00,
    categorie_id INT,
    stock INT DEFAULT 0,
    unite VARCHAR(20) DEFAULT 'portion',
    disponible TINYINT(1) DEFAULT 1,
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (categorie_id) REFERENCES categories(id)
);

-- Trigger: génération automatique du code produit
DELIMITER $$
DROP TRIGGER IF EXISTS before_insert_produit$$
CREATE TRIGGER before_insert_produit
BEFORE INSERT ON produits
FOR EACH ROW
BEGIN
    DECLARE prefix VARCHAR(5);
    DECLARE seq INT;
    DECLARE cat_nom VARCHAR(100);
    
    -- Obtenir le préfixe basé sur la catégorie
    IF NEW.categorie_id IS NOT NULL THEN
        SELECT UPPER(LEFT(nom, 3)) INTO cat_nom FROM categories WHERE id = NEW.categorie_id;
        SET prefix = COALESCE(cat_nom, 'PRD');
    ELSE
        SET prefix = 'PRD';
    END IF;
    
    -- Séquence auto
    SELECT COALESCE(MAX(CAST(SUBSTRING(code, LENGTH(prefix)+2) AS UNSIGNED)), 0) + 1
    INTO seq
    FROM produits
    WHERE code LIKE CONCAT(prefix, '-%');
    
    SET NEW.code = CONCAT(prefix, '-', LPAD(seq, 4, '0'));
END$$
DELIMITER ;

-- Tables du restaurant
CREATE TABLE IF NOT EXISTS tables_resto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(10) NOT NULL UNIQUE,
    capacite INT DEFAULT 4,
    zone VARCHAR(50) DEFAULT 'Salle',
    statut ENUM('libre','occupée','réservée','nettoyage') DEFAULT 'libre',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Clients
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100),
    telephone VARCHAR(20),
    email VARCHAR(150),
    adresse TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Réservations
CREATE TABLE IF NOT EXISTS reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT,
    table_id INT,
    date_reservation DATE NOT NULL,
    heure_debut TIME NOT NULL,
    heure_fin TIME,
    nb_personnes INT DEFAULT 1,
    statut ENUM('confirmée','en_attente','annulée','terminée') DEFAULT 'en_attente',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (table_id) REFERENCES tables_resto(id)
);

-- Utilisateurs / Personnel
CREATE TABLE IF NOT EXISTS utilisateurs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100),
    email VARCHAR(150) UNIQUE NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    role ENUM('admin','gerant','serveur','cuisinier') DEFAULT 'serveur',
    actif TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Factures / Commandes
CREATE TABLE IF NOT EXISTS factures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(20) UNIQUE,
    table_id INT,
    client_id INT,
    utilisateur_id INT,
    statut ENUM('ouverte','en_cours','payée','annulée') DEFAULT 'ouverte',
    mode_paiement ENUM('espèces','carte','chèque','virement','mobile') DEFAULT 'espèces',
    sous_total DECIMAL(10,2) DEFAULT 0.00,
    remise DECIMAL(10,2) DEFAULT 0.00,
    taxes DECIMAL(10,2) DEFAULT 0.00,
    total DECIMAL(10,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES tables_resto(id),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id)
);

-- Trigger: numéro de facture automatique
DELIMITER $$
DROP TRIGGER IF EXISTS before_insert_facture$$
CREATE TRIGGER before_insert_facture
BEFORE INSERT ON factures
FOR EACH ROW
BEGIN
    DECLARE seq INT;
    DECLARE yr VARCHAR(4);
    SET yr = YEAR(NOW());
    SELECT COALESCE(MAX(CAST(SUBSTRING(numero, 7) AS UNSIGNED)), 0) + 1
    INTO seq
    FROM factures
    WHERE numero LIKE CONCAT('FAC-', yr, '-%');
    SET NEW.numero = CONCAT('FAC-', yr, '-', LPAD(seq, 5, '0'));
END$$
DELIMITER ;

-- Lignes de facture (plusieurs produits par facture)
CREATE TABLE IF NOT EXISTS lignes_facture (
    id INT AUTO_INCREMENT PRIMARY KEY,
    facture_id INT NOT NULL,
    produit_id INT NOT NULL,
    quantite INT DEFAULT 1,
    prix_unitaire DECIMAL(10,2) NOT NULL,
    remise_ligne DECIMAL(10,2) DEFAULT 0.00,
    total_ligne DECIMAL(10,2) GENERATED ALWAYS AS (
        (prix_unitaire * quantite) - remise_ligne
    ) STORED,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE CASCADE,
    FOREIGN KEY (produit_id) REFERENCES produits(id)
);

-- Trigger: recalcul automatique du total facture
DELIMITER $$
DROP TRIGGER IF EXISTS after_insert_ligne$$
CREATE TRIGGER after_insert_ligne
AFTER INSERT ON lignes_facture
FOR EACH ROW
BEGIN
    DECLARE taux_taxe DECIMAL(5,2) DEFAULT 0.18;
    DECLARE new_sous_total DECIMAL(10,2);
    
    SELECT COALESCE(SUM(total_ligne), 0) INTO new_sous_total
    FROM lignes_facture WHERE facture_id = NEW.facture_id;
    
    UPDATE factures SET
        sous_total = new_sous_total,
        taxes = ROUND(new_sous_total * taux_taxe, 2),
        total = ROUND(new_sous_total * (1 + taux_taxe), 2),
        updated_at = NOW()
    WHERE id = NEW.facture_id;
END$$

DROP TRIGGER IF EXISTS after_update_ligne$$
CREATE TRIGGER after_update_ligne
AFTER UPDATE ON lignes_facture
FOR EACH ROW
BEGIN
    DECLARE taux_taxe DECIMAL(5,2) DEFAULT 0.18;
    DECLARE new_sous_total DECIMAL(10,2);
    
    SELECT COALESCE(SUM(total_ligne), 0) INTO new_sous_total
    FROM lignes_facture WHERE facture_id = NEW.facture_id;
    
    UPDATE factures SET
        sous_total = new_sous_total,
        taxes = ROUND(new_sous_total * taux_taxe, 2),
        total = ROUND(new_sous_total * (1 + taux_taxe), 2),
        updated_at = NOW()
    WHERE id = NEW.facture_id;
END$$

DROP TRIGGER IF EXISTS after_delete_ligne$$
CREATE TRIGGER after_delete_ligne
AFTER DELETE ON lignes_facture
FOR EACH ROW
BEGIN
    DECLARE taux_taxe DECIMAL(5,2) DEFAULT 0.18;
    DECLARE new_sous_total DECIMAL(10,2);
    
    SELECT COALESCE(SUM(total_ligne), 0) INTO new_sous_total
    FROM lignes_facture WHERE facture_id = OLD.facture_id;
    
    UPDATE factures SET
        sous_total = new_sous_total,
        taxes = ROUND(new_sous_total * taux_taxe, 2),
        total = ROUND(new_sous_total * (1 + taux_taxe), 2),
        updated_at = NOW()
    WHERE id = OLD.facture_id;
END$$
DELIMITER ;

-- ============================================================
-- DONNÉES DE DÉMARRAGE
-- ============================================================

INSERT INTO categories (nom, couleur, icone) VALUES
('Entrées', '#f59e0b', 'soup'),
('Plats principaux', '#ef4444', 'beef'),
('Desserts', '#ec4899', 'cake'),
('Boissons', '#3b82f6', 'coffee'),
('Vins & Spiritueux', '#8b5cf6', 'wine'),
('Snacks', '#10b981', 'sandwich')
ON DUPLICATE KEY UPDATE nom=nom;

INSERT INTO produits (nom, description, prix, prix_achat, categorie_id, stock) VALUES
('Soupe du jour', 'Soupe maison selon saison', 2500, 800, 1, 50),
('Salade César', 'Laitue, parmesan, croûtons, sauce César', 3500, 1200, 1, 30),
('Crudités variées', 'Assortiment de légumes frais', 2000, 600, 1, 40),
('Poulet rôti', 'Poulet fermier rôti aux herbes', 8500, 3000, 2, 20),
('Thiéboudienne', 'Riz au poisson sénégalais traditionnel', 6000, 2000, 2, 25),
('Steak grillé', 'Entrecôte 300g, pommes sautées', 12000, 4500, 2, 15),
('Poisson braisé', 'Poisson frais grillé au charbon', 7500, 2500, 2, 20),
('Fondant chocolat', 'Cœur coulant chocolat noir', 3000, 900, 3, 30),
('Crème brûlée', 'Crème vanille, caramel croustillant', 2500, 800, 3, 25),
('Salade de fruits', 'Fruits frais de saison', 2000, 700, 3, 20),
('Coca-Cola', 'Canette 33cl', 1000, 400, 4, 100),
('Eau minérale', 'Bouteille 50cl', 500, 150, 4, 200),
('Jus d''orange', 'Jus frais pressé', 1500, 500, 4, 60),
('Café espresso', 'Café arabica torréfié', 1000, 300, 4, 150),
('Bière Flag', 'Bière locale 65cl', 1500, 600, 4, 80),
('Vin rouge maison', 'Verre 15cl', 2500, 800, 5, 50),
('Whisky J&B', 'Verre 4cl', 4000, 1500, 5, 30)
ON DUPLICATE KEY UPDATE nom=nom;

INSERT INTO tables_resto (numero, capacite, zone) VALUES
('T01', 2, 'Terrasse'), ('T02', 2, 'Terrasse'), ('T03', 4, 'Terrasse'),
('T04', 4, 'Salle'), ('T05', 4, 'Salle'), ('T06', 6, 'Salle'),
('T07', 6, 'Salle'), ('T08', 8, 'Salle'), ('T09', 2, 'Bar'),
('T10', 4, 'VIP'), ('T11', 8, 'VIP'), ('T12', 12, 'Banquet')
ON DUPLICATE KEY UPDATE numero=numero;

INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role) VALUES
('Admin', 'Système', 'admin@restaurant.com', MD5('admin123'), 'admin'),
('Diallo', 'Mamadou', 'manager@restaurant.com', MD5('pass123'), 'gerant'),
('Sow', 'Aminata', 'serveur1@restaurant.com', MD5('pass123'), 'serveur')
ON DUPLICATE KEY UPDATE email=email;

INSERT INTO clients (nom, prenom, telephone, email) VALUES
('Ndiaye', 'Ibrahima', '+221 77 123 45 67', 'indiaye@email.com'),
('Fall', 'Fatou', '+221 70 987 65 43', 'ffall@email.com'),
('Mbaye', 'Cheikh', '+221 76 555 44 33', 'cmbaye@email.com')
ON DUPLICATE KEY UPDATE nom=nom;
