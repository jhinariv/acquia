<?php

namespace Drupal\acquia_telemetry;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;

/**
 * Telemetry service.
 */
class Telemetry {

  /**
   * Amplitude API URL.
   *
   * @var string
   * @see https://developers.amplitude.com/#http-api
   */
  private $apiUrl = 'https://api.amplitude.com/httpapi';

  /**
   * The extension.list.module service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  private $moduleList;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private $httpClient;

  /**
   * The config.factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $configFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private $state;

  /**
   * The application root directory.
   *
   * @var string
   */
  private $root;

  /**
   * Constructs a telemetry object.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The extension.list.module service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config.factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param string $app_root
   *   The Drupal application root.
   */
  public function __construct(ModuleExtensionList $module_list, ClientInterface $http_client, ConfigFactoryInterface $config_factory, StateInterface $state, $app_root) {
    $this->moduleList = $module_list;
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->root = $app_root;
  }

  /**
   * Returns the Amplitude API key.
   *
   * This is not intended to be private. It is typically included in client
   * side code. Fetching data requires an additional API secret.

   * @see https://developers.amplitude.com/#http-api
   *
   * @return string
   *   The Amplitude API key.
   */
  private function getApiKey() {
    $key = $this->configFactory->get('acquia_telemetry.settings')
      ->get('api_key');

    return $key ?: 'f32aacddde42ad34f5a3078a621f37a9';
  }

  /**
   * Sends an event to Amplitude.
   *
   * @param array $event
   *   The Amplitude event.
   *
   * @return bool
   *   TRUE if the request to Amplitude was successful, FALSE otherwise.
   *
   * @see https://developers.amplitude.com/#http-api
   */
  private function sendEvent(array $event) {
    $response = $this->httpClient->request('POST', $this->apiUrl, [
      'form_params' => [
        'api_key' => $this->getApiKey(),
        'event' => Json::encode($event),
      ],
    ]);

    return $response->getStatusCode() === 200;
  }

  /**
   * Creates and sends an event to Amplitude.
   *
   * @param string $event_type
   *   The event type. This accepts any string that is not reserved. Reserved
   *   event types include: "[Amplitude] Start Session", "[Amplitude] End
   *   Session", "[Amplitude] Revenue", "[Amplitude] Revenue (Verified)",
   *   "[Amplitude] Revenue (Unverified)", and "[Amplitude] Merged User".
   * @param array $event_properties
   *   (optional) Event properties.
   *
   * @return bool
   *   TRUE if event was successfully sent, otherwise FALSE.
   *
   * @throws \Exception
   *   Thrown if state key acquia_telemetry.loud is TRUE and request fails.
   *
   * @see https://amplitude.zendesk.com/hc/en-us/articles/204771828#keys-for-the-event-argument
   */
  public function sendTelemetry($event_type, array $event_properties = []) {
    $event = $this->createEvent($event_type, $event_properties);

    // Failure to send Telemetry should never cause a user facing error or
    // interrupt a process. Telemetry failure should be graceful and quiet.
    try {
      return $this->sendEvent($event);
    }
    catch (\Exception $e) {
      if ($this->state->get('acquia_telemetry.loud')) {
        throw new \Exception($e->getMessage(), $e->getCode(), $e);
      }
      return FALSE;
    }
  }

  /**
   * Get an array of information about Lightning extensions.
   *
   * @return array
   *   An array of extension info keyed by the extensions machine name. E.g.,
   *   ['lightning_layout' => ['version' => '8.2.0', 'status' => 'enabled']].
   */
  private function getExtensionInfo() {
    $all_modules = $this->moduleList->getAllAvailableInfo();
    $acquia_extensions = array_intersect_key($all_modules, array_flip($this->getAcquiaExtensionNames()));
    $extension_info = [];

    foreach ($acquia_extensions as $name => $extension) {
      // Version is unset for dev versions. In order to generate reports, we
      // need some value for version, even if it is just the major version.
      $extension_info[$name]['version'] = static::getExtensionVersion($extension);
    }

    $installed_modules = $this->moduleList->getAllInstalledInfo();
    foreach ($acquia_extensions as $name => $extension) {
      $extension_info[$name]['status'] = array_key_exists($name, $installed_modules) ? 'enabled' : 'disabled';
    }

    return $extension_info;
  }

  public static function getExtensionVersion(array $info) : string {
    return $info['version'] ?? $info['core_version_requirement'] ?? $info['core'];
  }

  /**
   * Creates an Amplitude event.
   *
   * @param string $type
   *   The event type.
   * @param array $properties
   *   The event properties.
   *
   * @return array
   *   An Amplitude event with basic info already populated.
   */
  private function createEvent($type, array $properties) {
    $default_properties = [
      'extensions' => $this->getExtensionInfo(),
      'php' => [
        'version' => phpversion(),
      ],
      'drupal' => [
        'version' => \Drupal::VERSION,
      ],
    ];

    return [
      'event_type' => $type,
      'user_id' => $this->getUserId(),
      'event_properties' => NestedArray::mergeDeep($default_properties, $properties),
    ];
  }

  /**
   * Gets a unique ID for this application. "User ID" is an Amplitude term.
   *
   * @return string
   *   Returns a hashed site uuid.
   */
  private function getUserId() {
    return Crypt::hashBase64($this->configFactory->get('system.site')->get('uuid'));
  }

  /**
   * Gets an array of all Acquia Drupal extensions.
   *
   * @return array
   *   A flat array of all Acquia Drupal extensions.
   */
  public function getAcquiaExtensionNames() {
    $module_names = array_keys($this->moduleList->getAllAvailableInfo());

    return array_filter($module_names, function ($name) {
      return strpos($name, 'acquia_') === 0 || strpos($name, 'lightning_') === 0;
    });
  }

}
