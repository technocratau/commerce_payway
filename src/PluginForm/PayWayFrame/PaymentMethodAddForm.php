<?php

namespace Drupal\commerce_payway_frame\PluginForm\PayWayFrame;

use Drupal\commerce_payment\PluginForm\PaymentGatewayFormBase;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\profile\Entity\Profile;

class PaymentMethodAddForm extends PaymentGatewayFormBase {

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new PaymentMethodAddForm.
   */
  public function __construct() {
    $this->routeMatch = \Drupal::service('current_route_match');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;

    $plugin = $this->plugin;

    $form['#attached']['library'][] = 'commerce_payment/payment_method_form';

    // Set our key to settings array.
    $form['#attached']['drupalSettings']['commercePayWayFrame'] = [
      'publishableKey' => $plugin->getPublishableKey(),
    ];

    $form['#tree'] = TRUE;
    $form['payment_details'] = [
      '#parents' => array_merge($form['#parents'], ['payment_details']),
      '#type' => 'container',
      '#payment_method_type' => $payment_method->bundle(),
    ];

    $form['payment_details'] = $this->buildPayWayForm($form['payment_details'], $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;
    /** @var \Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = Profile::create([
      'type' => 'customer',
      'uid' => $payment_method->getOwnerId(),
    ]);
    if ($order = $this->routeMatch->getParameter('commerce_order')) {
      $store = $order->getStore();
    }
    else {
      /** @var \Drupal\commerce_store\StoreStorageInterface $store_storage */
      $store_storage = \Drupal::entityTypeManager()->getStorage('commerce_store');
      $store = $store_storage->loadDefault();
    }

    $form['billing_information'] = [
      '#parents' => array_merge($form['#parents'], ['billing_information']),
      '#type' => 'commerce_profile_select',
      '#default_value' => $billing_profile,
      '#default_country' => $store ? $store->getAddress()->getCountryCode() : NULL,
      '#available_countries' => $store ? $store->getBillingCountries() : [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->validatePayWayForm($form['payment_details'], $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->submitPayWayForm($form['payment_details'], $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;
    $payment_method->setBillingProfile($form['billing_information']['#profile']);

    $values = $form_state->getValue($form['#parents']);
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $this->plugin;
    // The payment method form is customer facing. For security reasons
    // the returned errors need to be more generic.
    try {
      $payment_gateway_plugin->createPaymentMethod($payment_method, $values['payment_details']);
    }
    catch (DeclineException $e) {
      \Drupal::logger('commerce_payment')->warning($e->getMessage());
      throw new DeclineException('We encountered an error processing your payment method. Please verify your details and try again.');
    }
    catch (PaymentGatewayException $e) {
      \Drupal::logger('commerce_payment')->error($e->getMessage());
      throw new PaymentGatewayException('We encountered an unexpected error processing your payment method. Please try again later.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildPayWayForm(array $element, FormStateInterface $form_state) {

    $element['payment_credit_card'] = [
      '#type' => 'markup',
      '#markup' => <<< HTML
<div id="payway-credit-card"></div>
HTML
    ];

    $inputValues =& $form_state->getUserInput();

    if (!empty($inputValues['singleUseTokenId'])) {
      $element['payment_credit_card_token'] = [
        '#type' => 'hidden',
        '#value' => $inputValues['singleUseTokenId'],
      ];
    }

    $element['#attached']['library'][] = 'commerce_payway_frame/form';

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function validatePayWayForm(array &$element, FormStateInterface $form_state) {
    // The JS library performs its own validation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitPayWayForm(array $element, FormStateInterface $form_state) {
    // The payment gateway plugin will process the submitted payment details.
    $values = $form_state->getValue($element['#parents']);
    $this->entity->payway_token = $values['payment_credit_card_token'];
  }

}
