<?php

namespace Drupal\commerce_square\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the Square payment gateway.
 */
interface SquareInterface extends OnsitePaymentGatewayInterface, SupportsRefundsInterface {

}
