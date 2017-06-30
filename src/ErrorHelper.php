<?php

namespace Drupal\commerce_payway_frame;

use Drupal\commerce_payment\Exception\AuthenticationException;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\InvalidResponseException;

/**
 * Translates PayWay Frame exceptions and errors into Commerce exceptions.
 */
class ErrorHelper {

  /**
   * @param $result
   * @throws \Exception
   */
  public static function handleErrors($result) {
    // Success.
    $result->getMessage();
    if ($result->getMessage() === null) {
      return;
    }

    // Failure.
    $errorCode = $result->getHttpResponseCode();
    $errorMessage = $result->getData('message');
    throw new \Exception($errorMessage, $errorCode);
  }

  /**
   * @param $exception
   * @throws \Exception
   */
  public static function handleException($exception) {
    $exceptionMessage = $exception->getMessage() . $exception->getCode();
    //@todo:  Add a service and inject it.
    \Drupal::logger('commerce_payway_frame')->warning($exceptionMessage);
    throw new \Exception($exception->getMessage(), $exception->getCode());
  }

}
