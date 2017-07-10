<?php

namespace Drupal\commerce_payway_frame\Client;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Pay Way Client interface.
 */
interface PayWayRestApiClientInterface {

  /**
   * Execute the request to do a payment with PayWay.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   * @param array $configuration
   *
   * @return
   */
  public function doRequest(PaymentInterface $payment, array $configuration);

  /**
   * Get the response of the transaction.
   */
  public function getResponse();

}
