<?php

namespace Drupal\commerce_payway_frame\Client;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
interface PayWayRestApiClientInterface {

  public function doRequest(PaymentInterface $payment, array $configuration);

  public function getResponse();
}
