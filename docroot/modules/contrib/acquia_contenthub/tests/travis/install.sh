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
  # @TODO: Make sure to update beta version of webform to a released version once it is released.
  # @TODO: Stop forcing Composer path. Doing it for now to prevent errors.
  /home/travis/.phpenv/versions/7.3/bin/composer -d"$ORCA_FIXTURE_DIR" require --dev \
    drupal/webform \
    drupal/paragraphs \
    drupal/focal_point \
    drupal/redirect \
    drupal/metatag
  # Determining PHPUnit version.
  PHPUNIT_VERSION=`phpunit --version | cut -d ' ' -f 2`
  if [[ $PHPUNIT_VERSION  =~ ^[8] ]]; then
    /home/travis/.phpenv/versions/7.3/bin/composer -d"$ORCA_FIXTURE_DIR" require --dev dms/phpunit-arraysubset-asserts:0.1.1
  else
    /home/travis/.phpenv/versions/7.3/bin/composer -d"$ORCA_FIXTURE_DIR" require --dev dms/phpunit-arraysubset-asserts
  fi
else
  echo "Adding modules for Drupal 8.x..."
  # Eliminating Warnings to avoid failing tests on deprecated functions:
  export SYMFONY_DEPRECATIONS_HELPER=disabled codecept run
  /home/travis/.phpenv/versions/7.3/bin/composer -d"$ORCA_FIXTURE_DIR" require --dev \
    drupal/webform \
    drupal/paragraphs \
    drupal/focal_point \
    drupal/redirect \
    drupal/metatag \
    drupal/s3fs:^3
fi