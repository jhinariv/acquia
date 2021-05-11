<?php

namespace Drupal\acquia_contenthub_publisher\Form\Webhook;

use Drupal\acquia_contenthub\Client\ClientFactory;
use Drupal\acquia_contenthub_publisher\Form\SubscriptionManagerFormTrait;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class WebhookDeleteConfirmForm.
 *
 * Defines the confirmation form to delete a webhook.
 */
class WebhookDeleteConfirmForm extends ConfirmFormBase {

  use SubscriptionManagerFormTrait;

  /**
   * The Acquia ContentHub Client object.
   *
   * @var \Acquia\ContentHubClient\ContentHubClient
   */
  protected $client;

  /**
   * The UUID of an item (a webhook or a client) to delete.
   *
   * @var string
   */
  protected $uuid;

  /**
   * SubscriptionManagerController constructor.
   *
   * @param \Drupal\acquia_contenthub\Client\ClientFactory $client_factory
   *   The client factory.
   */
  public function __construct(ClientFactory $client_factory) {
    $this->client = $client_factory->getClient();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('acquia_contenthub.client.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $uuid = NULL) {
    $this->uuid = $uuid;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Psr\Http\Message\ResponseInterface $response */
    $response = $this->client->deleteWebhook($this->uuid);
    if (!$this->isResponseSuccessful($response, $this->t('delete'), $this->t('webhook'), $this->uuid, $this->messenger())) {
      return;
    }

    $this->messenger()->addStatus(
      $this->t('Webhook %uuid has been deleted.',
        ['%uuid' => $this->uuid]));

    $form_state->setRedirect('acquia_contenthub.subscription_settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'acquia_contenthub_webhook_delete_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('acquia_contenthub.subscription_settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Are you sure you want to delete webhook %uuid?', ['%uuid' => $this->uuid]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return '';
  }

}