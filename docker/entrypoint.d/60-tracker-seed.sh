#!/bin/sh
# Runs on every container start, after the image's Laravel automation (migrations + caches).
# DatabaseSeeder only creates what is missing (one student, three subjects, the default study period,
# unset planned hours), so it is safe to repeat and never overwrites your settings or records.
set -e

if [ "${AUTORUN_ENABLED:-false}" = "true" ]; then
    echo "Ensuring the starting data exists (safe to repeat)..."
    php /var/www/html/artisan db:seed --force --no-interaction
    # First deploy only: set TRACKER_LOAD_LIBRARY=true to load the syllabus, past-paper list and 2024 questions.
    # --if-empty means it never runs again once the syllabus has topics, so later edits are never repeated over.
    if [ "${TRACKER_LOAD_LIBRARY:-false}" = "true" ]; then
        php /var/www/html/artisan tracker:load-library --if-empty --no-interaction
    fi
fi
