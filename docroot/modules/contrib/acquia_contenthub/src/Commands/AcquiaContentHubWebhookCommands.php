<?php

namespace Drupal\acquia_contenthub\Commands;

use Drupal\acquia_contenthub\AcquiaContentHubEvents;
use Drupal\acquia_contenthub\Client\ClientFactory;
use Drupal\acquia_contenthub\Event\AcquiaContentHubUnregisterEvent;
use Drupal\Core\Url;
use Drush\Commands\DrushCommands;
use Drush\Log\LogLevel;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Drush commands for interacting with Acquia Content Hub webhooks.
 *
 * @package Drupal\acquia_contenthub\Commands
 */
class AcquiaContentHubWebhookCommands extends DrushCommands {

  /**
   * The client factory.
   *
   * @var \Drupal\acquia_contenthub\Client\ClientFactory
   */
  protected $clientFactory;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * AcquiaContentHubWebhookCommands constructor.
   *
   * @param \Drupal\acquia_contenthub\Client\ClientFactory $client_factory
   *   ACH client factory.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   Symfony event dispatcher.
   */
  public function __construct(ClientFactory $client_factory, EventDispatcherInterface $dispatcher) {
    $this->clientFactory = $client_factory;
    $this->eventDispatcher = $dispatcher;
  }

  /**
   * Perform a webhook management operation.
   *
   * @param string $op
   *   The operation to use. Options are: register, unregister, list.
   *
   * @command acquia:contenthub-webhooks
   * @aliases ach-wh,acquia-contenthub-webhooks
   *
   * @option webhook_url
   *   The webhook URL to register or unregister.
   * @default webhook_url null
   *
   * @usage acquia:contenthub-webhooks list
   *   Displays list of registered  webhooks.
   * @usage acquia:contenthub-webhooks register
   *   Registers new webhook. Current site url will be used.
   * @usage acquia:contenthub-webhooks register --webhook_url=http://example.com/acquia-contenthub/webhook
   *   Registers new webhook.
   * @usage acquia:contenthub-webhooks unregister
   *   Unregisters specified webhook. Current site url will be used.
   * @usage acquia:contenthub-webhooks unregister --webhook_url=http://example.com/acquia-contenthub/webhook
   *   Unregisters specified webhook.
   *
   * @throws \Exception
   */
  public function contenthubWebhooks($op) {
    $options = $this->input()->getOptions();

    $client = $this->clientFactory->getClient();
    if (!$client) {
      throw new \Exception(dt('The Content Hub client is not connected so the webhook operations could not be performed.'));
    }

    $webhook_url = $options['webhook_url'];
    if (empty($webhook_url)) {
      $webhook_url = Url::fromUri('internal:/acquia-contenthub/webhook', ['absolute' => TRUE])->toString();
    }

    switch ($op) {
      case 'register':
        $connection_manager = \Drupal::service('acquia_contenthub.connection_manager');
        $response = $connection_manager->registerWebhook($webhook_url);
        if (empty($response)) {
          return;
        }
        if (isset($response['success']) && FALSE === $response['success']) {
          $error_code = $response['error']['code'];
          if ($error_code === 4010) {
            $message = dt('Webhook was already registered (Code = @code): "@reason"', [
              '@code' => $response['error']['code'],
              '@reason' => $response['error']['message'],
            ]);
            $this->logger->notice($message);
          }
          else {
            $message = dt('Registering webhooks encountered an error. Error code: @code, Error Message: "@reason"', [
              '@code' => $response['error']['code'],
              '@reason' => $response['error']['message'],
            ]);
            $this->logger->log(LogLevel::ERROR, $message);
          }
          return;
        }

        $this->logger->log(LogLevel::SUCCESS,
          dt('Registered Content Hub Webhook: @url | @uuid',
            ['@url' => $webhook_url, '@uuid' => $response['uuid']]
          ));
        break;

      case 'unregister':
        $webhooks = $client->getWebHooks();
        if (empty($webhooks)) {
          $this->logger->log(LogLevel::CANCEL, dt('You have no webhooks.'));
          return;
        }

        /** @var \Acquia\ContentHubClient\Webhook $webhook */
        $webhook = $client->getWebHook($webhook_url);
        if (empty($webhook)) {
          $this->logger->log(LogLevel::CANCEL, dt('Webhook @url not found', ['@url' => $webhook_url]));
          return;
        }

        $event = new AcquiaContentHubUnregisterEvent($webhook->getUuid(), '', TRUE);
        $this->eventDispatcher->dispatch(AcquiaContentHubEvents::ACH_UNREGISTER, $event);

        $io = $this->io();
        $orphaned_filters = $event->getOrphanedFilters();
        if (!empty($orphaned_filters)) {
          $rows = [];

          foreach ($orphaned_filters as $name => $uuid) {
            $rows[] = [$name, $uuid];
          }

          $io->table(['Filter name', 'Filter UUID'], $rows);
          $answer = $io->choice(
            'What would you like to do with the filters? These can also be modified using the Discovery Interface',
            [
              'both' => 'Delete webhook and filters',
              'webhook' => 'Delete webhook only',
            ]
          );
        }

        $success = FALSE;
        /** @var \Drupal\acquia_contenthub\ContentHubConnectionManager $connection_manager */
        $connection_manager = \Drupal::service('acquia_contenthub.connection_manager');

        // This runs if user picks "delete webhook only" or
        // there are no orphaned filters.
        if (!isset($answer) || $answer === 'webhook') {
          $success = $connection_manager->unregisterWebhook($event);
        }

        // This runs only if user picked "delete webhooks and filters" and
        // there are orphaned filters which should be deleted.
        if (isset($answer) && $answer === 'both') {
          $success = $connection_manager->unregisterWebhook($event, TRUE);
        }

        if (!$success) {
          $this->logger->log(LogLevel::CANCEL, dt('There was an error unregistering the URL: @url', ['@url' => $webhook_url]));
          return;
        }

        $this->logger->log(LogLevel::SUCCESS, dt('Successfully unregistered Content Hub Webhook: @url', ['@url' => $webhook_url]));
        break;

      case 'list':
        $webhooks = $client->getWebHooks();
        if (empty($webhooks)) {
          $this->logger->warning(dt('You have no webhooks.'));
          return;
        }

        $rows_mapper = function ($webhook, $index) {
          return [
            $index + 1,
            $webhook->getUrl(),
            $webhook->getUuid(),
            !empty($webhook->getSuppressedUntil()) ? 'Suppressed until ' . date('m/d/Y H:i:s', $webhook->getSuppressedUntil()) : 'NO',
          ];
        };
        $rows = array_map($rows_mapper, $webhooks, array_keys($webhooks));

        (new Table($this->output()))
          ->setHeaders(['#', 'URL', 'UUID', 'Suppressed'])
          ->setRows($rows)
          ->render();
        break;

      default:
        // Invalid operation.
        throw new \Exception(dt('The op "@op" is invalid', ['@op' => $op]));
    }
  }

}
