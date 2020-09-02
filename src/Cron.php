<?php

namespace Drupal\commerce_payway;

use Drupal\commerce_payway\Client\PaywayRestApiClient;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\facets\Exception\Exception;

/**
 * Automate secret API key renewal.
 *
 * Implements https://www.payway.com.au/docs/rest.html#automate-secret-api-key-renewal.
 */
class Cron implements CronInterface {

  /**
   * The Payway Rest API client.
   *
   * @var \Drupal\commerce_payway\Client\PaywayRestApiClient
   */
  protected $apiClient;

  /**
   * Constructs a new Cron object.
   *
   * @param \Drupal\commerce_payway\Client\PaywayRestApiClient $apiClient
   *   The Payway Rest API client.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   */
  public function __construct(PaywayRestApiClient $apiClient) {
    $this->apiClient = $apiClient;
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    $last_run = \Drupal::state()->get('commerce_payway.last_cron');
    if ($last_run && $last_run > (time() - 86400)) {
      return;
    }

    // Deliberately not catching exceptions so errors are seen.
    //  eg Ultimate cron will show a bad status.
    $this->apiClient->refreshSecretKey();
    \Drupal::state()->set('commerce_payway.last_cron', time());
  }

}
