<?php

namespace Drupal\commerce_payway_frame\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityStorageException;
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
class PayWayFrame extends OnsitePaymentGatewayBase implements PayWayFrameInterface {

  private $gateway;
  private $client;
  private $uuid_service;
  const CURRENCY = 'aud';
  const TRANSACTIONTYPE = 'payment';

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, Client $client, UuidInterface $uuid_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);

    $this->client = $client;
    $this->uuid_service = $uuid_service;

  }

  /**
   * {@inheritdoc}
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
      $container->get('uuid')
    );

  }


  /**
   * {@inheritdoc}
   */
  public function getPublishableKey() {
    switch ($this->configuration['mode']) {
      case 'test':
        $publicKey = $this->configuration['publishable_key_test'];
        break;
      case 'live':
        $publicKey = $this->configuration['publishable_key'];
        break;
      default:
        $publicKey = '';
        drupal_set_message(t('The public key id empty'), 'error');
    }
    return $publicKey;
  }

  public function getSecretKey() {
    switch ($this->configuration['mode']) {
      case 'test':
        $secretKey = $this->configuration['secret_key_test'];
        break;
      case 'live':
        $secretKey = $this->configuration['secret_key'];
        break;
      default:
        $secretKey = '';
        drupal_set_message(t('The private key is empty'), 'error');
    }
    return $secretKey;
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

    $form['secret_key_test'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Secret Key'),
      '#default_value' => $this->configuration['secret_key_test'],
      '#required' => TRUE,
    ];

    $form['publishable_key_test'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Publishable Key'),
      '#default_value' => $this->configuration['publishable_key_test'],
      '#required' => TRUE,
    ];

    $form['secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Secret Key'),
      '#default_value' => $this->configuration['secret_key'],
      // '#required' => TRUE,
    ];

    $form['publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Publishable Key'),
      '#default_value' => $this->configuration['publishable_key'],
      // '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    // @todo: Publishable keys validation, if possible.
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['secret_key_test'] = $values['secret_key_test'];
      $this->configuration['publishable_key_test'] = $values['publishable_key_test'];
      $this->configuration['secret_key'] = $values['secret_key'];
      $this->configuration['publishable_key'] = $values['publishable_key'];
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws HardDeclineException
   * @throws \Exception
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    if ($payment->getState()->value !== 'new') {
      throw new \InvalidArgumentException('The provided payment is in an invalid state.');
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $payment->getPaymentMethod();
    if ($payment_method === null) {
      throw new \InvalidArgumentException('The provided payment has no payment method referenced.');
    }

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    // Delete and unset payment and related expired relationships.
    if ($payment_method->isExpired()) {
      try {
        // The next line breaks the payment method.
        //$payment_method->delete();
        $payment->delete();
        $order->set('payment_method',  null);
        $order->set('payment_gateway',  null);
        $order->save();
      }
      catch (EntityStorageException $e) {
        // Mute exceptions.
      }

      throw new HardDeclineException('The provided payment method has expired');
    }

    // Prepare the one-time payment.
    $owner = $payment_method->getOwner();
    if ($owner && !$owner->isAnonymous()) {
      $customerNumber = $owner->get('uid')->first()->value;
    } else {
      $customerNumber = 'anonymous';
    }

    try {
      $response = $this->client->request('POST', 'https://api.payway.com.au/rest/v1/transactions', [
        'form_params' => [
          'singleUseTokenId' => $payment_method->getRemoteId(),
          'customerNumber' => $customerNumber,
          'transactionType' => PayWayFrame::TRANSACTIONTYPE,
          'principalAmount' => round($payment->getAmount()->getNumber(), 2),
          'currency' => PayWayFrame::CURRENCY,
          'orderNumber' => $order->id(),
          'merchantId' => $this->configuration['merchant_id'],
        ],
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($this->getSecretKey()),
          'Idempotency-Key' => $this->uuid_service->generate(),
        ],
      ]);

      $result = json_decode($response->getBody());
    } catch (\Exception $e) {
      // @todo: the next line is supposed to expire the card, but it's not working.
      $payment_method->setExpiresTime(0);
      \Drupal::logger('commerce_payway_frame')->warning($e->getMessage());
      throw new HardDeclineException('The provided payment method has been refused');
    }

    // If the payment is not approved.
    if ($result->status !== 'approved'
      && $result->status !== 'approved*' ) {
      $errorMessage = $result->responseCode . ': '. $result->responseText;
      // @todo: the next line is supposed to expire the card, but it's not working.
      $payment_method->setExpiresTime(0);
      \Drupal::logger('commerce_payway_net')->error($errorMessage);
      throw new HardDeclineException('The provided payment method has been declined');
    }

    // Update the local payment entity.
    $request_time = \Drupal::time()->getRequestTime();
    $payment->state = $capture ? 'capture_completed' : 'authorization';
    $payment->setRemoteId($result->transactionId);
    $payment->setAuthorizedTime($request_time);

    // @todo Find out how long an authorization is valid, set its expiration.
    if ($capture) {
      $payment->setCapturedTime($request_time);
    }
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    // @todo
    $a = 1;
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    // @todo
    $a = 1;
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    // @todo
    $a = 1;
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      // The expected keys are payment gateway specific and usually match
      // the PaymentMethodAddForm form elements. They are expected to be valid.
      'payment_credit_card_token'
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    $payment_method->setExpiresTime(REQUEST_TIME + (10 * 60)); // 10 minutes.
    $payment_method->setReusable(false);
    $payment_method->setRemoteId($payment_details['payment_credit_card_token']);
    $payment_method->setDefault(false);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // @todo
    $a = 1;
  }

}
