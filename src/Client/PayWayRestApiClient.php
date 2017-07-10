<?php
/**
 * Class pay way rest client api.
 */

namespace Drupal\commerce_payway_frame\Client;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Component\Uuid\UuidInterface;
use GuzzleHttp\Client;

/**
 * Pay Way Rest Client Api.
 */
class PayWayRestApiClient implements PayWayRestApiClientInterface {

  private $client;
  private $uuidService;
  private $response;

  const METHOD = 'POST';
  const CURRENCY = 'aud';
  const TRANSACTION_TYPE = 'payment';

  public function __construct(Client $client, UuidInterface $uuid_service) {
    $this->client = $client;
    $this->uuidService = $uuid_service;
  }

  /**
   * Execute the payment request to payway.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   * @param array $configuration
   *   The payment method configuration
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function doRequest(PaymentInterface $payment, array $configuration) {
    $payment_method = $payment->getPaymentMethod();
    /**
     * @var \Drupal\commerce_order\Entity\OrderInterface $order
     */
    $order = $payment->getOrder();

    // Prepare the one-time payment.
    $owner = $payment_method->getOwner();
    if ($owner && !$owner->isAnonymous()) {
      $customerNumber = $owner->get('uid')->first()->value;
    }
    else {
      $customerNumber = 'anonymous';
    }

    $this->response = $this->client->request(
      PayWayRestApiClient::METHOD, $configuration['api_url'], [
        'form_params' => [
          'singleUseTokenId' => $payment_method->getRemoteId(),
          'customerNumber' => $customerNumber,
          'transactionType' => PayWayRestApiClient::TRANSACTION_TYPE,
          'principalAmount' => round($payment->getAmount()->getNumber(), 2),
          'currency' => PayWayRestApiClient::CURRENCY,
          'orderNumber' => $order->id(),
          'merchantId' => $configuration['merchant_id'],
        ],
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($this->getSecretKey($configuration)),
          'Idempotency-Key' => $this->uuidService->generate(),
        ],
      ]
    );

  }

  /**
   * Get client response.
   *
   * @return mixed
   *    Body of the client response.
   */
  public function getResponse() {
    return $this->response->getBody();
  }

  /**
   * Get the secret Key.
   *
   * @return string
   *   The secret key.
   */
  public function getSecretKey($configuration) {
    switch ($configuration['mode']) {
      case 'test':
        $secretKey = $configuration['secret_key_test'];
        break;

      case 'live':
        $secretKey = $configuration['secret_key'];
        break;

      default:
        $secretKey = '';
        drupal_set_message(t('The private key is empty'), 'error');
    }
    return $secretKey;
  }

}
