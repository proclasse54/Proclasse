#!/bin/bash
# install.sh – installe la base de données ProClasse
# Usage : bash install.sh

echo "=== Installation ProClasse ==="
read -p "Utilisateur MySQL root (défaut: root) : " DBROOT
DBROOT=${DBROOT:-root}

read -p "Mot de passe pour l'utilisateur applicatif 'proclasse_user' : " -s DBPASS
echo ""

# Injecter le mot de passe dans le schema
TMPFILE=$(mktemp)
sed "s/CHANGE_ME/$DBPASS/g" schema.sql \
  | sed "s/-- CREATE USER/CREATE USER/g" \
  | sed "s/-- GRANT/GRANT/g" \
  | sed "s/-- FLUSH/FLUSH/g" > "$TMPFILE"

mysql -u "$DBROOT" -p < "$TMPFILE"
rm "$TMPFILE"

echo ""
echo "✅ Base de données créée."
echo "👉 Pensez à mettre '$DBPASS' dans config/database.php"
