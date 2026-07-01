#!/bin/bash
echo "=== TEST COMPLET DU FLUX POS ==="
echo ""

# 1. Voir les produits
echo "1. Liste des produits disponibles:"
curl -s "http://localhost:8080/api/produits" | python3 -m json.tool | grep -E '"id"|"nom"|"prix"' | head -20
echo ""

# 2. Créer une facture
echo "2. Création d'une facture..."
FACTURE=$(curl -s -X POST http://localhost:8080/api/factures \
  -H "Content-Type: application/json" \
  -d '{"table_id":1,"utilisateur_id":1}')
FACTURE_ID=$(echo $FACTURE | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
NUMERO=$(echo $FACTURE | grep -o '"numero":"[^"]*"' | head -1 | cut -d: -f2 | tr -d '"')
echo "✅ Facture #$NUMERO créée (ID: $FACTURE_ID)"
echo ""

# 3. Ajouter des produits (en utilisant des IDs qui existent)
echo "3. Ajout de produits..."
# Récupérer les IDs des produits disponibles
PRODUCT_IDS=$(curl -s "http://localhost:8080/api/produits" | python3 -c "import sys, json; data=json.load(sys.stdin); print(' '.join([str(p['id']) for p in data['data'][:3]]))")
echo "IDs disponibles: $PRODUCT_IDS"

# Ajouter le premier produit
FIRST_ID=$(echo $PRODUCT_IDS | cut -d' ' -f1)
if [ -n "$FIRST_ID" ]; then
    echo "Ajout du produit ID $FIRST_ID x2..."
    curl -s -X POST "http://localhost:8080/api/factures/$FACTURE_ID/lignes" \
      -H "Content-Type: application/json" \
      -d "{\"produit_id\":$FIRST_ID,\"quantite\":2}" | python3 -m json.tool
fi

# Ajouter le deuxième produit
SECOND_ID=$(echo $PRODUCT_IDS | cut -d' ' -f2)
if [ -n "$SECOND_ID" ]; then
    echo "Ajout du produit ID $SECOND_ID x1..."
    curl -s -X POST "http://localhost:8080/api/factures/$FACTURE_ID/lignes" \
      -H "Content-Type: application/json" \
      -d "{\"produit_id\":$SECOND_ID,\"quantite\":1}" | python3 -m json.tool
fi
echo ""

# 4. Voir la facture
echo "4. Détail de la facture:"
curl -s "http://localhost:8080/api/factures/$FACTURE_ID" | python3 -m json.tool | grep -E '"id"|"numero"|"statut"|"sous_total"|"taxes"|"total"|"produit_nom"|"quantite"'
echo ""

# 5. Payer
echo "5. Paiement..."
curl -s -X PUT "http://localhost:8080/api/factures/$FACTURE_ID" \
  -H "Content-Type: application/json" \
  -d '{"statut":"payée","mode_paiement":"espèces"}' | python3 -m json.tool | grep -E '"message"|"statut"'
echo ""

# 6. Voir la facture payée
echo "6. Facture après paiement:"
curl -s "http://localhost:8080/api/factures/$FACTURE_ID" | python3 -m json.tool | grep -E '"id"|"numero"|"statut"|"sous_total"|"taxes"|"total"'
echo ""

echo "=== TEST TERMINÉ ==="
