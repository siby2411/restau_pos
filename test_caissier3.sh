#!/bin/bash
echo "========================================="
echo "   🧪 TEST AVEC CAISSIER 3"
echo "========================================="
echo ""

# 1. Login caissier3
echo "1. Connexion caissier3..."
LOGIN=$(curl -s -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"caissier3@restaurant.com","password":"123"}')
echo $LOGIN | python3 -m json.tool
CAISSIER_ID=$(echo $LOGIN | python3 -c "import sys, json; print(json.load(sys.stdin)['data']['id'])")
echo "Caissier ID: $CAISSIER_ID"
echo ""

# 2. Ouvrir une session
echo "2. Ouverture d'une session..."
curl -s -X POST http://localhost:8080/api/caissiers/session \
  -H "Content-Type: application/json" \
  -d "{\"caissier_id\":$CAISSIER_ID,\"montant_initial\":0}" | python3 -m json.tool
echo ""

# 3. Créer une facture
echo "3. Création d'une facture..."
FACTURE=$(curl -s -X POST http://localhost:8080/api/factures \
  -H "Content-Type: application/json" \
  -d "{\"table_id\":1,\"utilisateur_id\":$CAISSIER_ID}")
FACTURE_ID=$(echo $FACTURE | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
echo "Facture ID: $FACTURE_ID"
echo ""

# 4. Ajouter un produit (Poulet rôti ID 4)
echo "4. Ajout de Poulet rôti x2..."
curl -s -X POST "http://localhost:8080/api/factures/$FACTURE_ID/lignes" \
  -H "Content-Type: application/json" \
  -d '{"produit_id":4,"quantite":2}' | python3 -m json.tool
echo ""

# 5. Ajouter un produit (Coca-Cola ID 22)
echo "5. Ajout de Coca-Cola x3..."
curl -s -X POST "http://localhost:8080/api/factures/$FACTURE_ID/lignes" \
  -H "Content-Type: application/json" \
  -d '{"produit_id":22,"quantite":3}' | python3 -m json.tool
echo ""

# 6. Payer la facture
echo "6. Paiement..."
curl -s -X PUT "http://localhost:8080/api/factures/$FACTURE_ID" \
  -H "Content-Type: application/json" \
  -d '{"statut":"payée","mode_paiement":"espèces"}' | python3 -m json.tool
echo ""

# 7. Voir le Dashboard
echo "7. Dashboard - Statistiques des caissiers..."
curl -s "http://localhost:8080/api/dashboard" | python3 -m json.tool | grep -A30 '"caissiers"'
echo ""

# 8. Voir les factures
echo "8. Liste des factures..."
curl -s "http://localhost:8080/api/factures" | python3 -m json.tool | grep -E '"id"|"numero"|"total"|"statut"'
echo ""

echo "========================================="
echo "   ✅ TEST TERMINÉ"
echo "========================================="
