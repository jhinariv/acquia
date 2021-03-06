#!/usr/bin/env bash

# NAME
#     install.sh - Install Travis CI dependencies
#
# SYNOPSIS
#     install.sh
#
# DESCRIPTION
#     Creates the test fixture.

set -ev

cd "$(dirname "$0")" || exit; source _includes.sh

# Create a fixture for the DEPLOY job.
if [[ "$DEPLOY" ]]; then
  mysql -e 'CREATE DATABASE drupal;'
  orca fixture:init \
    -f \
    --sut=drupal/acquia_contenthub \
    --sut-only \
    --core="$CORE" \
    --no-sqlite \
    --no-site-install
  cd "$ORCA_FIXTURE_DIR/docroot/sites/default" || exit 1
  cp default.settings.php settings.php
  chmod 775 settings.php
  drush site:install \
    minimal \
    --db-url=mysql://root:@127.0.0.1/drupal \
    --site-name=ORCA \
    --account-name=admin \
    --account-pass=admin \
    --no-interaction \
    --verbose \
    --ansi
fi

# Create a fixture for the DEPLOY job.
if [[ "$DO_DEV" ]]; then
  mysql -e 'CREATE DATABASE drupal;'
  orca fixture:init \
    -f \
    --sut=drupal/acquia_contenthub \
    --sut-only \
    --core="$CORE" \
    --dev \
    --no-sqlite \
    --no-site-install
  cd "$ORCA_FIXTURE_DIR/docroot/sites/default" || exit 1
  cp default.settings.php settings.php
  chmod 775 settings.php
  drush site:install \
    minimal \
    --db-url=mysql://root:@127.0.0.1/drupal \
    --site-name=ORCA \
    --account-name=admin \
    --account-pass=admin \
    --no-interaction \
    --verbose \
    --ansi
fi

# Exit early in the absence of a fixture.
[[ -d "$ORCA_FIXTURE_DIR" ]] || exit 0

DRUPAL_CORE=9;
if [[ "$ORCA_JOB" == "INTEGRATED_TEST_ON_OLDEST_SUPPORTED" || "$ORCA_JOB" == "INTEGRATED_TEST_ON_LATEST_LTS" || $DEPLOY || $DO_DEV ]]; then
  DRUPAL_CORE=8;
fi

if [[ "$DRUPAL_CORE" == "9" ]]; then
  echo "Adding modules for Drupal 9.x..."
  composer -d"$ORCA_FIXTURE_DIR" require --dev --with-all-dependencies \
    drupal/webform \
    drupal/paragraphs \
    drupal/focal_point \
    drupal/redirect \
    drupal/metatag \
    drupal/entityqueue \
    dms/phpunit-arraysubset-asserts
else
  echo "Adding modules for Drupal 8.x..."
  # Eliminating Warnings to avoid failing tests on deprecated functions:
  export SYMFONY_DEPRECATIONS_HELPER=disabled codecept run
  composer -d"$ORCA_FIXTURE_DIR" require --dev --with-all-dependencies \
    drupal/webform \
    drupal/paragraphs \
    drupal/focal_point \
    drupal/redirect \
    drupal/metatag \
    drupal/entityqueue \
    drupal/s3fs:^3
fi
