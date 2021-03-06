<?php

namespace Drupal\acquia_contenthub_server_test;

use Drupal\acquia_contenthub_server_test\Client\ProjectVersionClientMock;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Replace Content Hub Client Factory service for testing purposes.
 */
class AcquiaContenthubServerTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $client_factory_def = $container->getDefinition('acquia_contenthub.client.factory');
    $client_factory_def->setClass('Drupal\acquia_contenthub_server_test\Client\ClientFactoryMock');
    $pv_client_def = $container->getDefinition('acquia_contenthub.project_version_client');
    $pv_client_def->setClass(ProjectVersionClientMock::class);
  }

}
