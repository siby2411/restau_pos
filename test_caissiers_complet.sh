#!/bin/bash
echo "========================================="
echo "   TEST COMPLET CAISSIERS"
echo "========================================="
echo ""

# 1. Login
echo "1. Login caissier1 :"
LOGIN=$(curl -s -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"caissier1@restaurant.com","password":"123"}')
echo $LOGIN | python3 -m json.tool
CAISSIER_ID=$(echo $LOGIN | python3 -c "import sys, json; print(json.load(sys.stdin)['data']['id'])")
echo "Caissier ID: $CAISSIER_ID"
echo ""

# 2. Ouvrir une session
echo "2. Ouverture d'une session :"
curl -s -X POST http://localhost:8080/api/caissiers/session \
  -H "Content-Type: application/json" \
  -d "{\"caissier_id\":$CAISSIER_ID,\"montant_initial\":5000}" | python3 -m json.tool
echo ""

# 3. Voir les sessions
echo "3. Sessions du caissier :"
curl -s "http://localhost:8080/api/caissiers/$CAISSIER_ID/sessions" | python3 -m json.tool
echo ""

# 4. Dashboard
echo "4. Dashboard avec stats caissiers :"
curl -s "http://localhost:8080/api/dashboard" | python3 -m json.tool | grep -A20 '"caissiers"'
echo ""

echo "========================================="
echo "   ✅ TEST TERMINÉ"
echo "========================================="
