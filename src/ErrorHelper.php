<?php

namespace Drupal\commerce_squareup;

use Drupal\commerce_payment\Exception\AuthenticationException;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\SoftDeclineException;
use SquareConnect\ApiException;

/**
 * Translates Square exceptions and errors into Commerce exceptions.
 *
 * @see https://docs.connect.squareup.com/api/connect/v2/#type-errorcategory
 * @see https://docs.connect.squareup.com/api/connect/v2/#type-errorcode
 */
class ErrorHelper {

  /**
   * Translates Braintree exceptions into Commerce exceptions.
   *
   * @param \SquareConnect\ApiException $exception
   *   The Square exception.
   *
   * @return \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   The Commerce exception.
   */
  public static function convertException(ApiException $exception) {
    $response_body = $exception->getResponseBody();
    $error = reset($response_body->errors);

    $stop = null;
    switch ($error->category) {
      case 'PAYMENT_METHOD_ERROR':
        return new SoftDeclineException($error->detail);

      default:
        return new InvalidResponseException($exception->getMessage(), $exception->getCode(), $exception);
    }
  }

}
