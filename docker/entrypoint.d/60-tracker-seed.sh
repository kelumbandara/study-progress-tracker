#!/bin/sh
# Runs on every container start, after the image's Laravel automation (migrations + caches).
# DatabaseSeeder only creates what is missing (one student, three subjects, the default study period,
# unset planned hours), so it is safe to repeat and never overwrites your settings or records.
set -e

if [ "${AUTORUN_ENABLED:-false}" = "true" ]; then
    echo "Ensuring the starting data exists (safe to repeat)..."
    php /var/www/html/artisan db:seed --force --no-interaction
fi
