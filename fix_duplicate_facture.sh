#!/bin/bash
echo "🔧 RÉPARATION DES DOUBLONS DE FACTURES"

# 1. Voir les numéros existants
echo ""
echo "📋 Numéros de factures existants :"
mysql -u root -p restaurant_db -e "SELECT id, numero, created_at FROM factures ORDER BY id;"

# 2. Supprimer les doublons (garder le plus récent)
echo ""
echo "🗑️ Suppression des doublons..."
mysql -u root -p restaurant_db << 'SQL'
DELETE f1 FROM factures f1
INNER JOIN factures f2 
WHERE f1.id > f2.id AND f1.numero = f2.numero;
SQL

# 3. Réinitialiser AUTO_INCREMENT
echo ""
echo "🔄 Réinitialisation AUTO_INCREMENT..."
mysql -u root -p restaurant_db -e "ALTER TABLE factures AUTO_INCREMENT = 1;"

# 4. Vérifier
echo ""
echo "✅ Factures après nettoyage :"
mysql -u root -p restaurant_db -e "SELECT id, numero, created_at FROM factures ORDER BY id;"

echo ""
echo "✅ Réparation terminée !"
