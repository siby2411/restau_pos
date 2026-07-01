#!/bin/bash
echo "========================================="
echo "   TEST CAISSIERS - RESTAUMANAGER"
echo "========================================="
echo ""

# 1. Liste des caissiers
echo "1. Liste des caissiers :"
curl -s "http://localhost:8080/api/caissiers" | python3 -m json.tool | grep -E '"id"|"nom"|"prenom"|"email"'
echo ""

# 2. Emploi du temps
echo "2. Emploi du temps hebdomadaire :"
curl -s "http://localhost:8080/api/caissiers/emploi_temps" | python3 -m json.tool | grep -E '"jour_semaine"|"periode"|"nom"|"prenom"' | head -20
echo ""

# 3. Test login
echo "3. Test login caissier1 :"
curl -s -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"caissier1@restaurant.com","password":"123"}' | python3 -m json.tool
echo ""

# 4. Ouvrir une session
echo "4. Ouverture d'une session pour caissier1 :"
curl -s -X POST http://localhost:8080/api/caissiers/session \
  -H "Content-Type: application/json" \
  -d '{"caissier_id":1,"montant_initial":5000}' | python3 -m json.tool
echo ""

# 5. Dashboard avec stats caissiers
echo "5. Dashboard avec statistiques des caissiers :"
curl -s "http://localhost:8080/api/dashboard" | python3 -m json.tool | grep -A50 '"caissiers"'
echo ""

echo "========================================="
echo "   ✅ TEST TERMINÉ"
echo "========================================="
