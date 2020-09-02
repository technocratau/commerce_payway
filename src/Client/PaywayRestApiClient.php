<?php

namespace Drupal\commerce_payway\Client;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payway\Exception\PaywayClientException;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;

/**
 * Class PaywayRestApiClient.
 *
 * @package Drupal\commerce_payway\Client
 */
class PaywayRestApiClient implements PaywayRestApiClientInterface {

  /**
   * The REST API Client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private $client;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  private $uuidService;

  /**
   * The response from the REST API.
   *
   * @var \Psr\Http\Message\ResponseInterface
   */
  private $response;

  /**
   * The default request method.
   */
  const METHOD = 'POST';

  /**
   * The default currency.
   */
  const CURRENCY = 'aud';

  /**
   * The request transaction type.
   */
  const TRANSACTION_TYPE_PAYMENT = 'payment';

  /**
   * @var array
   *
   * The configuration array.
   */
  private $configuration;

  /**
   * PaywayRestApiClient constructor.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   Guzzle client.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_service
   *   Uuid service.
   */
  public function __construct(
    ClientInterface $client,
    UuidInterface $uuid_service
  ) {
    $this->client = $client;
    $this->uuidService = $uuid_service;
  }

  /**
   *
   */
  public function submitRequest($method, $path, $params) {
    $body = [
      'form_params' => $params + [
        'merchantId' => $this->configuration['merchant_id'],
      ],
      'headers' => [
        'Authorization' => 'Basic ' . base64_encode($this->getSecretKey($this->configuration)),
        'Idempotency-Key' => $this->uuidService->generate(),
      ],
    ];

    $this->response = $this->client->request(
      $method, $this->configuration['api_url'] . $path, $body
    );
  }

  /**
   * {@inheritdoc}
   */
  public function doRequest(PaymentInterface $payment) {
    $payment_method = $payment->getPaymentMethod();

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();

    // Prepare the one-time payment.
    $owner = $payment_method->getOwner();
    $customerNumber = 'anonymous';
    if ($owner && !$owner->isAnonymous()) {
      $customerNumber = $owner->get('uid')->first()->value;
    }

    $this->submitRequest(
      static::METHOD, $this->configuration['api_url'], [
        'singleUseTokenId' => $payment_method->getRemoteId(),
        'customerNumber' => $customerNumber,
        'transactionType' => static::TRANSACTION_TYPE_PAYMENT,
        'principalAmount' => round($payment->getAmount()->getNumber(), 2),
        'currency' => static::CURRENCY,
        'orderNumber' => $order->id(),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getResponse() {
    if ($this->response !== NULL) {
      return (string) $this->response->getBody();
    }
    return '';
  }

  /**
   * Get the secret Key.
   *
   * @return string
   *    The secret key.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function getSecretKey() {
    switch ($this->configuration['mode']) {
      case 'test':
        $secretKey = $this->configuration['secret_key_test'];
        break;

      case 'live':
        $secretKey = $this->configuration['secret_key'];
        break;

      default:
        throw new MissingDataException('The private key is empty.');
    }
    return $secretKey;
  }

  /**
   * Set the configuration array.
   *
   * @oaran array
   *   The configuration array.
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = $configuration;
  }

}
