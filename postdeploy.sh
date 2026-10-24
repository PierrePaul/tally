#!/bin/bash
# Runs after each deploy, in the new container, before traffic moves to it.
set -euo pipefail

drush=./vendor/bin/drush

# The persistent storage is mounted root-owned; Apache runs as www-data.
chown -R www-data:www-data /private web/sites/default/files

# An empty database (first deploy) gets the site installed from the exported
# configuration; INITIAL_ADMIN_* are only read then. The tables are counted
# with PHP on purpose: if the database cannot be reached this fails and the
# script stops, where a drush/mysql check that failed quietly would look like
# an empty database, and site:install would wipe a real one.
tables=$(php -r '
  $u = parse_url(getenv("DATABASE_URL"));
  $pdo = new PDO("mysql:host=" . $u["host"] . ";port=" . ($u["port"] ?? 3306) . ";dbname=" . ltrim($u["path"], "/"), urldecode($u["user"]), urldecode($u["pass"]), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  echo count($pdo->query("SHOW TABLES")->fetchAll());
')
if [ "$tables" = "0" ]; then
  $drush site:install minimal --existing-config -y \
    --account-name="${INITIAL_ADMIN_NAME:?set INITIAL_ADMIN_NAME for the first deploy}" \
    --account-mail="${INITIAL_ADMIN_MAIL:?set INITIAL_ADMIN_MAIL for the first deploy}" \
    --account-pass="${INITIAL_ADMIN_PASS:?set INITIAL_ADMIN_PASS for the first deploy}"
fi

$drush cache:rebuild
$drush updatedb -y
$drush config:import -y
# The French wording of the invoices lives in translations/fr.po (stored in the database).
$drush locale:update
$drush cache:rebuild
