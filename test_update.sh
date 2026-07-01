#!/bin/bash
echo "=== TEST UPDATE PRODUITS ==="
echo ""

# 1. Afficher les produits disponibles
echo "1. Produits disponibles :"
curl -s "http://localhost:8080/api/produits" | python3 -m json.tool | grep -E '"id"|"nom"|"prix"' | head -30
echo ""

# 2. Trouver un produit existant (le premier de la liste)
PRODUCT_ID=$(curl -s "http://localhost:8080/api/produits" | python3 -c "import sys, json; data=json.load(sys.stdin); print(data['data'][0]['id'] if data['data'] else '')")
echo "2. Produit sélectionné : ID $PRODUCT_ID"
echo ""

# 3. Modifier le prix
echo "3. Modification du prix..."
curl -X PUT "http://localhost:8080/api/produits/$PRODUCT_ID" \
  -H "Content-Type: application/json" \
  -d '{"prix":"999"}' \
  | python3 -m json.tool | grep -E '"success"|"message"|"prix"'
echo ""

# 4. Modifier l'image
echo "4. Modification de l'image..."
curl -X PUT "http://localhost:8080/api/produits/$PRODUCT_ID" \
  -H "Content-Type: application/json" \
  -d '{"image_url":"/uploads/test_image.jpg"}' \
  | python3 -m json.tool | grep -E '"success"|"message"|"image_url"'
echo ""

# 5. Modifier le nom
echo "5. Modification du nom..."
curl -X PUT "http://localhost:8080/api/produits/$PRODUCT_ID" \
  -H "Content-Type: application/json" \
  -d '{"nom":"Produit Test Update"}' \
  | python3 -m json.tool | grep -E '"success"|"message"|"nom"'
echo ""

echo "=== TEST TERMINÉ ==="
