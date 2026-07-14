#!/bin/sh
set -eu

php -l nat-share-buttons.php
php -l includes/popular-posts.php
node --check assets/nsb.js
php tests/integration.php integrated
php tests/integration.php standalone
git diff --check
