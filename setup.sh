#!/bin/bash
# ============================================================
# SETUP COMPLET – RestauManager Pro sous Termux/Proot-Distro
# Usage: bash setup.sh
# ============================================================

set -e
echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║        RESTAUMANAGER PRO – INSTALLATION              ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""

# ── 1. Vérification PHP ──────────────────────────────────────
PHP_VER=$(php --version 2>/dev/null | head -1 | awk '{print $2}' | cut -d. -f1,2)
echo "✅ PHP détecté: $PHP_VER"

# ── 2. Restart MariaDB ───────────────────────────────────────
echo "🔄 Démarrage MariaDB..."
service mariadb restart 2>/dev/null || mysqld_safe --user=root &
sleep 2
echo "✅ MariaDB opérationnel"

# ── 3. Import base de données ─────────────────────────────────
echo "🗄️  Import de la base de données..."
mysql -u root < /root/restau_design/setup.sql 2>/dev/null \
  && echo "✅ Base de données créée avec succès" \
  || echo "⚠️  Base peut-être déjà existante, tentative de mise à jour..."

# Si erreur, juste les données sans DROP
mysql -u root restaurant_db < /root/restau_design/setup.sql 2>/dev/null || true

# ── 4. Configuration Apache ───────────────────────────────────
DOCROOT="/root/restau_design"
APACHE_CONF="/etc/apache2/sites-available/restau.conf"
PORT=8080

cat > "$APACHE_CONF" <<'CONFEOF'
<VirtualHost *:8080>
    ServerName localhost
    DocumentRoot /root/restau_design
    DirectoryIndex index.html index.php

    <Directory /root/restau_design>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <Directory /root/restau_design/api>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/restau_error.log
    CustomLog ${APACHE_LOG_DIR}/restau_access.log combined
</VirtualHost>
CONFEOF

# ── Si pas Apache, utiliser PHP built-in server ──────────────
echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║  Lancement du serveur PHP intégré sur port 8080      ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""
echo "📌 Accès depuis Termux/Android:"
echo "   http://localhost:8080"
echo ""
echo "📌 L'API est disponible sur:"
echo "   http://localhost:8080/api/index.php/dashboard"
echo ""
echo "⏹  CTRL+C pour arrêter"
echo ""

cd /root/restau_design
php -S 0.0.0.0:8080 router.php
