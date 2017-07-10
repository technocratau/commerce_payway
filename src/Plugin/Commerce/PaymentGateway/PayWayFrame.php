<?php

namespace Drupal\commerce_payway_frame\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_payway_frame\Client\PayWayRestApiClientInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the PayWay Frame payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "payway_frame",
 *   label = "PayWay Frame",
 *   display_label = "PayWay Frame",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_payway_frame\PluginForm\PayWayFrame\PaymentMethodAddForm",
 *   },
 *   payment_method_types = {"payway"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 *   js_library = "commerce_payway_frame/form",
 * )
 */
class PayWayFrame extends OnsitePaymentGatewayBase {

  private $client;
  private $uuidService;
  private $payWayRestApiClient;
  const CURRENCY = 'aud';
  const TRANSACTION_TYPE = 'payment';

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, Client $client, UuidInterface $uuid_service, PayWayRestApiClientInterface $payWayRestApiClient) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);

    $this->client = $client;
    $this->uuidService = $uuid_service;
    $this->payWayRestApiClient = $payWayRestApiClient;

  }

  /**
   * {@inheritdoc}
   *
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
    $configuration,
    $plugin_id,
    $plugin_definition,
    $container->get('entity_type.manager'),
    $container->get('plugin.manager.commerce_payment_type'),
    $container->get('plugin.manager.commerce_payment_method_type'),
    $container->get('http_client'),
    $container->get('uuid'),
    $container->get('commerce_payway_frame.rest_api.client')
    );

  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'secret_key_test' => '',
      'publishable_key_test' => '',
      'secret_key' => '',
      'publishable_key' => '',
      'merchant_id' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant_id'] = array(
      '#type' => 'textfield',
      '#title' => t('Merchant Id'),
      '#description' => t('eg. TEST'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    );

    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Url'),
      '#default_value' => $this->configuration['api_url'],
      '#description' => t('eg. https://api.payway.com.au/rest/v1/transactions'),
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
   * {@inheritdoc}
   *
   * @throws HardDeclineException
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

    // Request Payway.
    try {
      $this->payWayRestApiClient->doRequest($payment, $this->configuration);
      $result = json_decode($this->payWayRestApiClient->getResponse());
    }
    catch (\Exception $e) {
      $this->deletePayment($payment, $order);
      \Drupal::logger('commerce_payway_frame')->warning($e->getMessage());
      throw new HardDeclineException('The payment request failed.', 0, $e);
    }

    // If the payment is not approved.
    if ($result->status !== 'approved'
        && $result->status !== 'approved*'
    ) {
      $this->deletePayment($payment, $order);
      $errorMessage = $result->responseCode . ': ' . $result->responseText;
      \Drupal::logger('commerce_payway_net')->error($errorMessage);
      throw new HardDeclineException('The provided payment method has been declined');
    }

    // Update the local payment entity.
    $request_time = \Drupal::time()->getRequestTime();
    $payment->state = $capture ? 'capture_completed' : 'authorization';
    $payment->setRemoteId($result->transactionId);
    $payment->setAuthorizedTime($request_time);

    if ($capture) {
      $payment->setCapturedTime($request_time);
    }
    $payment->save();
  }

  /**
   * {@inheritdoc}
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

    $payment_method->setExpiresTime(0);
    $payment_method->setReusable(FALSE);
    $payment_method->setRemoteId($payment_details['payment_credit_card_token']);
    $payment_method->setDefault(FALSE);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
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
   */
  public function deletePayment(PaymentInterface $payment, OrderInterface $order) {
    $payment->delete();
    $order->set('payment_method', NULL);
    $order->set('payment_gateway', NULL);
    $order->save();
  }

}
