<?php

namespace Drupal\commerce_payway_frame\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the PayWay Frame payment gateway.
 */
interface PayWayFrameInterface extends OnsitePaymentGatewayInterface, SupportsAuthorizationsInterface, SupportsRefundsInterface {

  /**
   * Get the PayWay Frame API Publishable key set for the payment gateway.
   *
   * @return string
   *   The PayWay Frame API publishable key.
   */
  public function getPublishableKey();

}
