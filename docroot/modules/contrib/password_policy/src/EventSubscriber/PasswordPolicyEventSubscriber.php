<?php

namespace Drupal\password_policy\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;

/**
 * Enforces password reset functionality.
 */
class PasswordPolicyEventSubscriber implements EventSubscriberInterface {

  /**
   * The currently logged in user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $userStorage;

  /**
   * The request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  protected $request;

  /**
   * PasswordPolicyEventSubscriber constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The currently logged in user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(AccountProxyInterface $currentUser, EntityTypeManagerInterface $entityTypeManager, MessengerInterface $messenger, RequestStack $requestStack) {
    $this->currentUser = $currentUser;
    $this->messenger = $messenger;
    $this->request = $requestStack->getCurrentRequest();
    $this->userStorage = $entityTypeManager->getStorage('user');
  }

  /**
   * Updates password reset value for all users.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   *   The config importer event.
   */
  public function onConfigImport(ConfigImporterEvent $event) {
    $modules = $event->getConfigImporter()->getExtensionChangelist('module', 'install');

    if (!in_array('password_policy', $modules)) {
      return;
    }
    $timestamp = gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, \Drupal::time()->getRequestTime());

    /** @var \Drupal\user\UserInterface[] $users */
    $users = $this->userStorage->loadMultiple();

    // @todo Get rid of updating all users.
    foreach ($users as $user) {
      if ($user->getAccountName() == NULL) {
        continue;
      }
      $user
        ->set('field_last_password_reset', $timestamp)
        ->set('field_password_expiration', '0')
        ->save();
    }
  }

  /**
   * Event callback to look for users expired password.
   */
  public function checkForUserPasswordExpiration(GetResponseEvent $event) {
    // There needs to be an explicit check for non-anonymous or else
    // this will be tripped and a forced redirect will occur.
    if ($this->currentUser->isAuthenticated()) {
      /* @var $user \Drupal\user\UserInterface */
      $user = $this->userStorage->load($this->currentUser->id());

      $route_name = $this->request->attributes->get(RouteObjectInterface::ROUTE_NAME);
      $ignore_route = in_array($route_name, [
        'entity.user.edit_form',
        'system.ajax',
        'user.logout',
        'admin_toolbar_tools.flush',
      ]);

      $is_ajax = $this->request->headers->get('X_REQUESTED_WITH') === 'XMLHttpRequest';

      $user_expired = FALSE;
      if ($user->get('field_password_expiration')->get(0)) {
        $user_expired = $user->get('field_password_expiration')
          ->get(0)
          ->getValue();
        $user_expired = $user_expired['value'];
      }

      // TODO - Consider excluding admins here.
      if ($user_expired && !$ignore_route && !$is_ajax) {
        $url = new Url('entity.user.edit_form', ['user' => $user->id()]);
        $url = $url->setAbsolute()->toString();
        $event->setResponse(new RedirectResponse($url));
        $this->messenger->addError('Your password has expired, please update it');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // TODO - Evaluate if there is a better place to add this check.
    $events[KernelEvents::REQUEST][] = ['checkForUserPasswordExpiration'];
    $events[ConfigEvents::IMPORT][] = ['onConfigImport'];
    return $events;
  }

}
