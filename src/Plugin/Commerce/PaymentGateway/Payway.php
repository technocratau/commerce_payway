<?php

namespace Drupal\commerce_payway\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsCreatingPaymentMethodsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;
use Drupal\commerce_payway\Client\paywayRestApiClientInterface;
use Drupal\commerce_payway\Exception\PaywayClientException;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Payway Frame payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "payway",
 *   label = "Payway",
 *   display_label = "Credit Card",
 *   forms = {
 *     "add-payment-method" =
 *   "Drupal\commerce_payway\PluginForm\Payway\StoredPaymentMethodAddForm",
 *   },
 *   payment_method_types = {"payway"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard",
 *   "visa",
 *   },
 *   requires_billing_information = TRUE,
 *   js_library = "commerce_payway/frame_form",
 * )
 */
class Payway extends OffsitePaymentGatewayBase implements SupportsCreatingPaymentMethodsInterface, SupportsVoidsInterface, SupportsRefundsInterface {

  /**
   * The Payway REST API Client.
   *
   * @var \Drupal\commerce_payway\Client\PaywayRestApiClientInterface
   */
  protected $paywayRestApiClient;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The default currency.
   */
  const CURRENCY = 'aud';

  /**
   * The default transaction type.
   */
  const TRANSACTION_TYPE = 'payment';

  /**
   * Constructs a new Payway gateway object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Drupal\commerce_payway\Client\PaywayRestApiClientInterface paywayRestApiClient
   *   The Payway REST API Client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   A logger factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time,
    PaywayRestApiClientInterface $paywayRestApiClient,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition,
      $entity_type_manager, $payment_type_manager,
      $payment_method_type_manager, $time);

    $this->paywayRestApiClient = $paywayRestApiClient;
    $this->paywayRestApiClient->setConfiguration($configuration);
    $this->logger = $logger_factory->get('commerce_payway');
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('commerce_payway.rest_api.client'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'merchant_id' => '',
        'api_url' => '',
        'secret_key_test' => '',
        'publishable_key_test' => '',
        'secret_key' => '',
        'publishable_key' => '',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => t('Merchant Id'),
      '#description' => t('eg. TEST'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    ];

    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Base Url'),
      '#default_value' => $this->configuration['api_url'],
      '#description' => t('eg. https://api.payway.com.au/rest/v1'),
      '#required' => TRUE,
    ];

    $form['test'] = [
      '#type' => 'fieldset',
      '#title' => t('Tests keys'),
    ];

    $form['test']['secret_key_test'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Secret Key'),
      '#default_value' => $this->configuration['secret_key_test'],
      '#required' => TRUE,
    ];

    $form['test']['publishable_key_test'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Publishable Key'),
      '#default_value' => $this->configuration['publishable_key_test'],
      '#required' => TRUE,
    ];

    $form['live'] = [
      '#type' => 'fieldset',
      '#title' => t('Live keys'),
    ];

    $form['live']['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Secret Key'),
      '#default_value' => $this->configuration['secret_key'],
    ];

    $form['live']['publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Publishable Key'),
      '#default_value' => $this->configuration['publishable_key'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['api_url'] = $values['api_url'];
      $this->configuration['secret_key_test'] = $values['test']['secret_key_test'];
      $this->configuration['publishable_key_test'] = $values['test']['publishable_key_test'];
      $this->configuration['secret_key'] = $values['live']['secret_key'];
      $this->configuration['publishable_key'] = $values['live']['publishable_key'];
    }
  }

  /**
   * Reacts to canceling an offsite payment method creation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   Thrown when the transaction fails for any reason.
   */
  public function cancelCreatePaymentMethod(Request $request) {
    // @TODO
  }

  /**
   * Get the publishable Key.
   *
   * @return string
   *   The publishable key.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function getPublishableKey() {
    switch ($this->configuration['mode']) {
      case 'test':
        $output = $this->configuration['publishable_key_test'];
        break;

      case 'live':
        $output = $this->configuration['publishable_key'];
        break;

      default:
        throw new MissingDataException('The publishable key is empty.');
    }
    return $output;
  }

  /**
   * Get the secret Key.
   *
   * @return string
   *   The secret key.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function getSecretKey() {
    switch ($this->configuration['mode']) {
      case 'test':
        $output = $this->configuration['secret_key_test'];
        break;

      case 'live':
        $output = $this->configuration['secret_key'];
        break;

      default:
        throw new MissingDataException('The private key is empty.');
    }
    return $output;
  }


  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {

    $required_keys = [
      // The expected keys are payment gateway specific and usually match
      // a PaymentMethodAddForm form elements. They are expected to be valid.
      'payment_credit_card_token',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf(
          '$payment_details must contain the %s key.', $required_key));
      }
    }

    if (!empty($payment_details['customer'])) {
      // Attempt to create a customer for the single use entity.
      try {
        $customer = $this->paywayRestApiClient->submitRequest('POST',
          '/customers', [
            'singleUseTokenId' => $payment_details['payment_credit_card_token'],
            'merchantId' => $this->configuration['merchant_id'],
          ]);
        $response = $this->paywayRestApiClient->getResponse();
        $response = json_decode($response);
      }
      catch (\Exception $e) {
        $this->logger->error('Failed to create a customer in the Payway API: %exception',
          ['%exception' => $e->getResponse()->getBody()->getContents()]);
        throw new PaymentGatewayException('An error occurred while adding your payment method, sorry.');
      }

      $cardDetails = $response->paymentSetup->creditCard;
      $expires = new \DateTime();
      $expires->setDate(2000 + $cardDetails->expiryDateYear,
        $cardDetails->expiryDateMonth, 1);
      $payment_method->setExpiresTime($expires->format('U'));
      $payment_method->setReusable(TRUE);
      $payment_method->setRemoteId($response->customerNumber);
    }
    else {
      $payment_method->setExpiresTime(0);
      $payment_method->setReusable(FALSE);
      $payment_method->setRemoteId($payment_details['payment_credit_card_token']);
    }
    $payment_method->setDefault(FALSE);
    $cardDetails = $response->paymentSetup->creditCard;
    $payment_method->payway_card_type = $cardDetails->cardScheme;
    $payment_method->payway_card_number = $cardDetails->cardNumber;
    $payment_method->payway_card_exp_month = $cardDetails->expiryDateMonth;
    $payment_method->payway_card_exp_year = $cardDetails->expiryDateYear;
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {

    try {
      $customerNumber = $payment_method->getRemoteId();
      // Cancel pending payments.
      $this->paywayRestApiClient->submitRequest('PATCH',
        '/customers/' . $customerNumber . '/payment-setup',
        ['stopped' => 'true']);

      // Delete unused customers. This will fail if the customer has activity.
      $this->paywayRestApiClient->submitRequest('DELETE',
        '/customers/' . $customerNumber, []);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete a customer in the Payway API: %exception',
        ['%exception' => $e->getResponse()->getBody()->getContents()]);
    }

    // Even if we've failed to delete the customer, we've cancelled any
    // payments using the card. Delete the payment method.
    $payment_method->delete();
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_payment\Exception\HardDeclineException
   * @throws \Exception
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    if ($payment->getState()->value !== 'new') {
      throw new \InvalidArgumentException('The provided payment is in an invalid state.');
    }

    /**
     * @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
     */
    $payment_method = $payment->getPaymentMethod();
    if ($payment_method === NULL) {
      throw new \InvalidArgumentException('The provided payment has no payment method referenced.');
    }

    /**
     * @var \Drupal\commerce_order\Entity\OrderInterface $order
     */
    $order = $payment->getOrder();

    // Make a request to Payway.
    try {
      $form = [
        'customerNumber' => $order->getCustomerId(),
        'transactionType' => 'payment',
        'principalAmount' => $order->getBalance()->getNumber(),
        'currency' => 'aud',
        'orderNumber' => $order->id(),
      ];

      $ip = \Drupal::request()->getClientIp();

      if ($ip) {
        $form['customerIpAddress'] = $ip;
      }

      $this->paywayRestApiClient->submitRequest('POST', '/transactions', $form);
      $result = json_decode($this->paywayRestApiClient->getResponse());
    }
    catch (PaywayClientException $e) {
      $this->deletePayment($payment, $order);
      $this->logger->warning($e->getMessage());
      throw new HardDeclineException('The payment request failed.', 0, $e);
    }

    // If the payment is not approved.
    if ($result->status !== 'approved'
      && $result->status !== 'approved*'
    ) {
      $this->deletePayment($payment, $order);
      $errorMessage = $result->responseCode . ': ' . $result->responseText;
      $this->logger->error($errorMessage);
      throw new HardDeclineException('The provided payment method has been declined');
    }

    // Update the local payment entity.
    $request_time = $this->time->getRequestTime();
    $payment->state = $capture ? 'completed' : 'authorization';
    $payment->setRemoteId($result->transactionId);
    $payment->setAuthorizedTime($request_time);
    if ($capture) {
      $payment->setCompletedTime($request_time);
    }
    $payment->save();

    if (!$payment_method->isReusable()) {
      $payment_method->delete();
    }
  }

  /**
   * Delete the payment instance to fix the list of payment methods.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The current instance of payment.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The current order.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \InvalidArgumentException
   */
  public function deletePayment(PaymentInterface $payment, OrderInterface $order) {
    $payment->delete();
    $order->set('payment_method', NULL);
    $order->set('payment_gateway', NULL);
    $order->save();
  }

  /**
   * Checks whether the given payment can be voided.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment to void.
   *
   * @return bool
   *   TRUE if the payment can be voided, FALSE otherwise.
   */
  public function canVoidPayment(PaymentInterface $payment) {
    // @TODO
  }

  /**
   * Voids the given payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment to void.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   Thrown when the transaction fails for any reason.
   */
  public function voidPayment(PaymentInterface $payment) {
    // @TODO
  }

  /**
   * Checks whether the given payment can be refunded.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment to refund.
   *
   * @return bool
   *   TRUE if the payment can be refunded, FALSE otherwise.
   */
  public function canRefundPayment(PaymentInterface $payment) {
    // @TODO
  }

  /**
   * Refunds the given payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment to refund.
   * @param \Drupal\commerce_price\Price $amount
   *   The amount to refund. If NULL, defaults to the entire payment amount.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   Thrown when the transaction fails for any reason.
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    // @TODO
  }

}
