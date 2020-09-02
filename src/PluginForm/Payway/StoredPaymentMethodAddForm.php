<?php

namespace Drupal\commerce_payway\PluginForm\Payway;

use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentMethodOffsiteAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\profile\Entity\Profile;

/**
 * Payment Method Add form.
 */
class StoredPaymentMethodAddForm extends PaymentMethodOffsiteAddForm {

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
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;

    /* @var \Drupal\commerce_payway\Plugin\Commerce\PaymentGateway\PaywayFrame $plugin */
    $plugin = $this->plugin;

    $form['#attached']['library'][] = 'commerce_payment/payment_method_form';

    // Set our key to settings array.
    $form['#attached']['drupalSettings']['commercePaywayStoredForm'] = [
      'publishableKey' => $plugin->getPublishableKey(),
    ];

    $form['#tree'] = TRUE;
    $form['payment_details'] = [
      '#parents' => array_merge($form['#parents'], ['payment_details']),
      '#type' => 'container',
      '#payment_method_type' => $payment_method->bundle(),
    ];

    // We need a single use token ID set.
    $input = $form_state->getUserInput();
    $input['singleUseTokenId'] = '';
    $form_state->setUserInput($input);

    $form['payment_details'] = $this->buildPaywayForm($form['payment_details'],
      $form_state);

    $order = $this->routeMatch->getParameter('commerce_order');

    if ($order) {
    $store = $order->getStore();
    }
    else {
      /** @var \Drupal\commerce_store\StoreStorageInterface $store_storage */
      $store_storage = \Drupal::entityTypeManager()
        ->getStorage('commerce_store');
      $store = $store_storage->loadDefault();
    }

    /**
     * @var \Drupal\profile\Entity\ProfileInterface $billing_profile
     */
    $billing_profile = $order ? $order->getBillingProfile() : NULL;
    if ($billing_profile === NULL ||
      ($billing_profile && empty($billing_profile->getOwnerId()))) {
      $billing_profile = Profile::create(
        [
          'type' => 'customer',
          'uid' => $payment_method->getOwnerId(),
        ]
      );
    }

    $form['billing_information'] = [
      '#parents' => array_merge($form['#parents'], ['billing_information']),
      '#type' => 'commerce_profile_select',
      '#default_value' => $billing_profile,
      '#default_country' => $store ? $store->getAddress()
        ->getCountryCode() : NULL,
      '#available_countries' => $store ? $store->getBillingCountries() : [],
    ];

    if ($order) {
      $form['reusable'] = [
        '#type' => 'value',
        '#value' => $payment_method->isReusable(),
      ];
      if (!empty($form['#allow_reusable'])) {
        if ($form['#always_save']) {
          $form['reusable']['#value'] = TRUE;
        }
        else {
          $form['reusable'] = [
            '#type' => 'checkbox',
            '#title' => t('Save this payment method for later use'),
            '#default_value' => FALSE,
          ];
        }
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ) {
    if (!$form_state->isSubmitted()) {
      return;
    }

    // @TODO Use Postman or such like to test this. And unit tests.
    $input = $form_state->getUserInput();
    if (empty($input['singleUseTokenId'])) {
      $form_state->setErrorByName('add-payment-method','You need to provide valid payment information');
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_payment\Exception\DeclineException
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;
    $token = $form_state->getUserInput()['singleUseTokenId'];

    if (array_key_exists('billing_information', $form)) {
      $payment_method->setBillingProfile($form['billing_information']['#profile']);
    }

    /* @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $this->plugin;
    // The payment method form is customer facing. For security reasons
    // the returned errors need to be more generic.
    try {
      $user = \Drupal::routeMatch()->getParameter('user');

      if ($form_state->getUserInput()['payment_information']['add_payment_method']['reusable'] &&
        !$payment_method->isReusable()) {
        $user = \Drupal::currentUser();
      }
      $payment_gateway_plugin->createPaymentMethod($payment_method, [
        'customer' => $user,
        'payment_credit_card_token' => $token]
      );
    }
    catch (DeclineException $e) {
      \Drupal::logger('commerce_payment')->warning($e->getMessage());
      throw new DeclineException('We encountered an error processing your
              payment method. Please verify your details and try again.');
    }
    catch (PaymentGatewayException $e) {
      \Drupal::logger('commerce_payment')->error($e->getMessage());
      throw new PaymentGatewayException('We encountered an unexpected
              error processing your payment method. Please try again later.');
    }
  }

  /**
   * Build Pay Way form.
   *
   * @param array $element
   *   Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Form element.
   */
  public function buildPaywayForm(array $element, FormStateInterface $form_state) {

    $element['payment_credit_card'] = [
      '#type' => 'markup',
      '#markup' => <<< HTML
<div id="payway-credit-card"></div>
HTML
    ];

    $inputValues = &$form_state->getUserInput();

    if (!empty($inputValues['singleUseTokenId'])) {
      $element['payment_credit_card_token'] = [
        '#type' => 'hidden',
        '#value' => $inputValues['singleUseTokenId'],
      ];
    }

    $element['#attached']['library'][] = 'commerce_payway/frame_form';

    return $element;
  }

}
