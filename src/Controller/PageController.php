<?php

namespace Drupal\commerce_payway_frame\Controller;

use Drupal\commerce_payment\PaymentGatewayManager;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use \Drupal\Core\File\FileSystem;

/**
 * This is a controller for PayWay Frame pages.
 */
class PageController implements ContainerInjectionInterface {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * The Drupal file system.
   *
   * @var \Drupal\Component\DependencyInjection\Container
   */
  protected $fileSystem;

  /**
   * Payment gateway plugin manager.
   *
   * @var \Drupal\commerce_payment\PaymentGatewayManager
   */
  private $paymentPluginManager;

  /**
   * Constructs a new DummyRedirectController object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
        RequestStack $request_stack,
        FileSystem $fileSystem,
        PaymentGatewayManager $paymentGatewayManager
    ) {
    $this->currentRequest = $request_stack->getCurrentRequest();
    $this->fileSystem = $fileSystem;
    $this->paymentPluginManager = $paymentGatewayManager;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   */
  public static function create(ContainerInterface $container) {
    /**
 * @noinspection PhpParamsInspection
*/
    return new static(
    $container->get('request_stack'),
    $container->get('file_system'),
    $container->get('plugin.manager.commerce_payment_gateway')
    );
  }

  /**
   * Callback method which accepts POST.
   *
   * 1. Read the single use token from the parameter
   * 2. Verify your customer using a session cookie
   * 3. Using your secret API key and the single use token, send a request
   * to take a one-time payment.
   */
  public function post() {
    $cancel = $this->currentRequest->request->get('cancel');
    $return = $this->currentRequest->request->get('return');
    $total = $this->currentRequest->request->get('total');

    if ($total > 20) {
      return new TrustedRedirectResponse($return);
    }

    return new TrustedRedirectResponse($cancel);
  }

  /**
   * Callback method which renders the secure frame HTML.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   If the instance cannot be created, such as if the ID is invalid.
   */
  public function frameHtml($pluginId) {
    $publishableKey = '';
    $modulePath = drupal_get_path('module', 'commerce_payway_frame');
    $moduleRealPath = $this->fileSystem->realpath($modulePath);
    $htmlString = file_get_contents($moduleRealPath . '/templates/HostedCreditCardIFrame.html');

    return str_replace('{publishableApiKey}', $publishableKey, $htmlString);
  }

}
