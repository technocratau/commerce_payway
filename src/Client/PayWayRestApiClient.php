<?php

namespace Drupal\commerce_payway_frame\Client;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Component\Uuid\UuidInterface;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
class PayWayRestApiClient implements PayWayRestApiClientInterface {

  private $client;
  private $uuidService;
  private $response;
  //@todo : api_rul has to come from $confgiruation.
  const API_URL = 'https://api.payway.com.au/rest/v1/transactions';
  const METHOD = 'POST';
  const CURRENCY = 'aud';
  const TRANSACTION_TYPE = 'payment';

  public function __construct(Client $client, UuidInterface $uuid_service) {
    $this->client = $client;
    $this->uuidService = $uuid_service;
  }

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
      PayWayRestApiClient::METHOD, PayWayRestApiClient::API_URL, [
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
