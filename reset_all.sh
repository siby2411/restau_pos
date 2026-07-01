#!/bin/bash
echo "========================================="
echo "   🔄 RÉINITIALISATION COMPLÈTE"
echo "========================================="
echo ""

# 1. Vider les tables
echo "1. Vidage des tables..."
mysql -u root -p restaurant_db << 'SQL'
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE lignes_facture;
TRUNCATE TABLE factures;
TRUNCATE TABLE sessions_caisse;
TRUNCATE TABLE ruptures_stock;
TRUNCATE TABLE paiements_qr;
SET FOREIGN_KEY_CHECKS = 1;
ALTER TABLE factures AUTO_INCREMENT = 1;
ALTER TABLE sessions_caisse AUTO_INCREMENT = 1;
ALTER TABLE ruptures_stock AUTO_INCREMENT = 1;
ALTER TABLE paiements_qr AUTO_INCREMENT = 1;
SQL

# 2. Vérifier
echo ""
echo "2. Vérification des tables..."
mysql -u root -p restaurant_db -e "
SELECT 'Factures' as Table, COUNT(*) as Total FROM factures
UNION ALL
SELECT 'Lignes facture', COUNT(*) FROM lignes_facture
UNION ALL
SELECT 'Sessions caisse', COUNT(*) FROM sessions_caisse
UNION ALL
SELECT 'Ruptures stock', COUNT(*) FROM ruptures_stock
UNION ALL
SELECT 'Paiements QR', COUNT(*) FROM paiements_qr;
"

# 3. Afficher les caissiers
echo ""
echo "3. Caissiers disponibles :"
mysql -u root -p restaurant_db -e "SELECT id, nom, prenom, email FROM caissiers;"

# 4. Afficher les produits
echo ""
echo "4. Produits disponibles :"
mysql -u root -p restaurant_db -e "SELECT id, code, nom, prix FROM produits LIMIT 10;"

# 5. Afficher les clients
echo ""
echo "5. Clients disponibles :"
mysql -u root -p restaurant_db -e "SELECT id, nom, prenom, telephone FROM clients;"

echo ""
echo "========================================="
echo "   ✅ RÉINITIALISATION TERMINÉE"
echo "========================================="
echo ""
echo "📋 Identifiants des caissiers :"
echo "   caissier1@restaurant.com / 123"
echo "   caissier2@restaurant.com / 123"
echo "   caissier3@restaurant.com / 123"
echo "   caissier4@restaurant.com / 123"
echo ""
echo "🌐 Accès : http://localhost:8080/login.html"
