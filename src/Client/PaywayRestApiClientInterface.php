<?php

namespace Drupal\commerce_payway\Client;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Pay Way Client interface.
 */
interface PaywayRestApiClientInterface {

  /**
   * Execute the payment request to payway.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   */
  public function doRequest(PaymentInterface $payment);

  /**
   * Get client response.
   *
   * @return string
   *   Body of the client response.
   */
  public function getResponse();

  /**
   * Set configuration.
   *
   * @param array
   *   The configuration array.
   */
  public function setConfiguration(array $configuration);

}
