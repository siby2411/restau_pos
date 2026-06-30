#!/bin/bash
echo "========================================="
echo "   🍽️ DEMO RESTAUMANAGER COMPLETE"
echo "========================================="
echo ""

# 1. Créer une commande
echo "1️⃣ Création d'une commande..."
FACTURE=$(curl -s -X POST http://localhost:8080/api/factures \
  -H "Content-Type: application/json" \
  -d '{"table_id":1,"utilisateur_id":1}')
FACTURE_ID=$(echo $FACTURE | jq -r '.data.id')
NUMERO=$(echo $FACTURE | jq -r '.data.numero')
echo "✅ Commande $NUMERO créée (ID: $FACTURE_ID)"
echo ""

# 2. Ajouter des produits
echo "2️⃣ Ajout de produits..."
echo "   - Ajout de Coca-Cola x2..."
curl -s -X POST "http://localhost:8080/api/factures/$FACTURE_ID/lignes" \
  -H "Content-Type: application/json" \
  -d '{"produit_id":11,"quantite":2}' | jq '.totaux'
echo ""

echo "   - Ajout de Vin rouge x1..."
curl -s -X POST "http://localhost:8080/api/factures/$FACTURE_ID/lignes" \
  -H "Content-Type: application/json" \
  -d '{"produit_id":16,"quantite":1}' | jq '.totaux'
echo ""

echo "   - Ajout de Poulet rôti x1..."
curl -s -X POST "http://localhost:8080/api/factures/$FACTURE_ID/lignes" \
  -H "Content-Type: application/json" \
  -d '{"produit_id":4,"quantite":1}' | jq '.totaux'
echo ""

# 3. Afficher le récapitulatif
echo "3️⃣ Récapitulatif de la commande :"
curl -s "http://localhost:8080/api/factures/$FACTURE_ID" | jq '.data | {
  commande: .numero,
  table: .table_num,
  statut: .statut,
  "sous-total": .sous_total,
  tva: .taxes,
  total: .total,
  produits: [.lignes[] | {nom: .produit_nom, quantite: .quantite, prix_unitaire: .prix_unitaire}]
}'
echo ""

# 4. Payer
echo "4️⃣ Paiement de la commande..."
curl -s -X PUT "http://localhost:8080/api/factures/$FACTURE_ID" \
  -H "Content-Type: application/json" \
  -d '{"statut":"payée","mode_paiement":"espèces"}' | jq '.message'
echo ""

# 5. Confirmation
echo "5️⃣ Confirmation :"
curl -s "http://localhost:8080/api/factures/$FACTURE_ID" | jq '.data | {commande: .numero, statut: .statut, total: .total, mode: .mode_paiement}'
echo ""

echo "========================================="
echo "   ✅ DÉMO RÉUSSIE !"
echo "========================================="
echo ""
echo "📱 Interface : http://localhost:8080/"
echo "📊 API : http://localhost:8080/api/"
