#!/bin/bash
echo "=== TEST DU POS RESTAURANT ==="
echo ""

# 1. Récupérer les produits
echo "1. Produits disponibles:"
curl -s "http://localhost:8080/api/produits" | python3 -m json.tool 2>/dev/null || echo "Utilisez jq pour un meilleur affichage"
echo ""

# 2. Rechercher par "BOI"
echo "2. Recherche 'BOI':"
curl -s "http://localhost:8080/api/produits?q=BOI"
echo ""

# 3. Rechercher par "VIN"
echo "3. Recherche 'VIN':"
curl -s "http://localhost:8080/api/produits?q=VIN"
echo ""

# 4. Rechercher par "boisson"
echo "4. Recherche 'boisson':"
curl -s "http://localhost:8080/api/produits?q=boisson"
echo ""

# 5. Récupérer les catégories
echo "5. Catégories:"
curl -s "http://localhost:8080/api/categories"
echo ""

# 6. Créer une commande
echo "6. Création d'une commande:"
FACTURE=$(curl -s -X POST http://localhost:8080/api/factures \
  -H "Content-Type: application/json" \
  -d '{"table_id":1,"utilisateur_id":1}')
echo "Réponse: $FACTURE"
# Extraire l'ID avec grep (si pas jq)
FACTURE_ID=$(echo $FACTURE | grep -o '"id":[0-9]*' | cut -d: -f2 | head -1)
if [ -z "$FACTURE_ID" ]; then
    FACTURE_ID=$(echo $FACTURE | grep -o '"data":{[^}]*"id":[0-9]*' | grep -o '[0-9]*$')
fi
if [ -z "$FACTURE_ID" ]; then
    FACTURE_ID="1"
fi
echo "Facture #$FACTURE_ID créée"
echo ""

# 7. Ajouter des produits
echo "7. Ajout de produits à la facture #$FACTURE_ID:"
# Ajouter Coca-Cola (ID 11)
echo "Ajout de Coca-Cola x2:"
curl -s -X POST "http://localhost:8080/api/factures/$FACTURE_ID/lignes" \
  -H "Content-Type: application/json" \
  -d '{"produit_id":11,"quantite":2}'
echo ""

# Ajouter Poulet rôti (ID 4)
echo "Ajout de Poulet rôti x1:"
curl -s -X POST "http://localhost:8080/api/factures/$FACTURE_ID/lignes" \
  -H "Content-Type: application/json" \
  -d '{"produit_id":4,"quantite":1}'
echo ""

# 8. Afficher la facture complète
echo "8. Facture complète:"
curl -s "http://localhost:8080/api/factures/$FACTURE_ID"
echo ""

# 9. Payer la facture
echo "9. Paiement de la facture:"
curl -s -X PUT "http://localhost:8080/api/factures/$FACTURE_ID" \
  -H "Content-Type: application/json" \
  -d '{"statut":"payée","mode_paiement":"espèces"}'
echo ""

echo "=== TEST TERMINÉ ==="
