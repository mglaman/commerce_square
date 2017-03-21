<?php

namespace Drupal\commerce_squareup\Plugin\Commerce\PaymentGateway;

use Braintree\Exception;
use SquareConnect\Api\LocationApi;
use SquareConnect\Api\TransactionApi;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\NestedArray;

/**
 * Provides the Squareup payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "squareup",
 *   label = "Squareup",
 *   display_label = "Squareup",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_squareup\PluginForm\Squareup\PaymentMethodAddForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class Squareup extends OnsitePaymentGatewayBase {

  protected $api;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);
    $this->api = new TransactionApi();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'app_name' => '',
      'app_id' => '',
      'personal_access_token' => '',
      'location_id' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['app_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application Name'),
      '#default_value' => $this->configuration['app_name'],
      '#required' => TRUE,
    ];
    $form['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application ID'),
      '#default_value' => $this->configuration['app_id'],
      '#required' => TRUE,
    ];
    $form['personal_access_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Personal Access Token'),
      '#default_value' => $this->configuration['personal_access_token'],
      '#required' => TRUE,
      '#ajax' => [
        'wrapper' => 'squareup-location-wrapper',
        'callback' => [$this, 'locationsAjax'],
        // Needs the patch in https://www.drupal.org/node/2627788.
        'disable-refocus' => TRUE,
      ],
    ];
    $form['location_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'squareup-location-wrapper'],
    ];
    $values = $form_state->getValues();
    $input = $form_state->getUserInput();
    $personal_access_token = NestedArray::getValue($values, $form['#parents'])['personal_access_token'];
    if (empty($personal_access_token)) {
      $personal_access_token = $this->configuration['personal_access_token'];
    }
    $location_markup = TRUE;
    if (!empty($personal_access_token)) {
      $location_api = new LocationApi();
      try {
        $locations = $location_api->listLocations($personal_access_token);
        if (!empty($locations)) {
          $location_options = $locations->getLocations();
          $options = [];
          foreach ($location_options as $location_option) {
            $options[$location_option->getId()] = $location_option->getName();
          }

          $form['location_wrapper']['location_id'] = [
            '#type' => 'select',
            '#title' => $this->t('Location ID'),
            '#default_value' => $this->configuration['location_id'],
            '#required' => TRUE,
            '#options' => $options,
          ];
        }
        $location_markup = FALSE;
      }
      catch (\Exception $e) {
      }

    }
    if ($location_markup) {
      $form['location_wrapper']['location_id'] = [
        '#markup' => $this->t('Please provide a valid personal access token to select a location id.'),
      ];
    }
    return $form;
  }

  /**
   * AJAX callback for the configuration form.
   */
  public function locationsAjax(array &$form, FormStateInterface $form_state, Request $request) {
    return $form['configuration']['location_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValues();
    $location = NestedArray::getValue($values, $form['#parents'])['location_wrapper']['location_id'];
    if (empty($location)) {
      $form_state->setErrorByName('configuration[personal_access_token]', $this->t('Please provide a valid personal access token to select a location id.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['app_name'] = $values['app_name'];
      $this->configuration['app_id'] = $values['app_id'];
      $this->configuration['personal_access_token'] = $values['personal_access_token'];
      $this->configuration['location_id'] = $values['location_id'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getApplicationId() {
    return $this->configuration['app_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    if ($payment->getState()->value != 'new') {
      throw new \InvalidArgumentException('The provided payment is in an invalid state.');
    }
    $payment_method = $payment->getPaymentMethod();
    if (empty($payment_method)) {
      throw new \InvalidArgumentException('The provided payment has no payment method referenced.');
    }
    if (REQUEST_TIME >= $payment_method->getExpiresTime()) {
      throw new HardDeclineException('The provided payment method has expired');
    }
    $amount = $payment->getAmount();
    $currency_code = $payment->getAmount()->getCurrencyCode();
    if (empty($this->configuration['merchant_account_id'][$currency_code])) {
      throw new InvalidRequestException(sprintf('No merchant account ID configured for currency %s', $currency_code));
    }

    $transaction_data = [
      'channel' => 'CommerceGuys_BT_Vzero',
      'merchantAccountId' => $this->configuration['merchant_account_id'][$currency_code],
      'orderId' => $payment->getOrderId(),
      'amount' => $amount->getNumber(),
      'options' => [
        'submitForSettlement' => $capture,
      ],
    ];
    if ($payment_method->isReusable()) {
      $transaction_data['paymentMethodToken'] = $payment_method->getRemoteId();
    }
    else {
      $transaction_data['paymentMethodNonce'] = $payment_method->getRemoteId();
    }

    try {
      $result = $this->api->transaction()->sale($transaction_data);
      ErrorHelper::handleErrors($result);
    }
    catch (Exception $e) {
      ErrorHelper::handleException($e);
    }

    $payment->state = $capture ? 'capture_completed' : 'authorization';
    $payment->setRemoteId($result->transaction->id);
    $payment->setAuthorizedTime(REQUEST_TIME);
    // @todo Find out how long an authorization is valid, set its expiration.
    if ($capture) {
      $payment->setCapturedTime(REQUEST_TIME);
    }
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException('Only payments in the "authorization" state can be captured.');
    }
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    try {
      $remote_id = $payment->getRemoteId();
      $decimal_amount = $amount->getNumber();
      $result = $this->api->transaction()->submitForSettlement($remote_id, $decimal_amount);
      ErrorHelper::handleErrors($result);
    }
    catch (Exception $e) {
      ErrorHelper::handleException($e);
    }

    $payment->state = 'capture_completed';
    $payment->setAmount($amount);
    $payment->setCapturedTime(REQUEST_TIME);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException('Only payments in the "authorization" state can be voided.');
    }

    try {
      $remote_id = $payment->getRemoteId();
      $result = $this->api->transaction()->void($remote_id);
      ErrorHelper::handleErrors($result);
    }
    catch (Exception $e) {
      ErrorHelper::handleException($e);
    }

    $payment->state = 'authorization_voided';
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    if (!in_array($payment->getState()->value, ['capture_completed', 'capture_partially_refunded'])) {
      throw new \InvalidArgumentException('Only payments in the "capture_completed" and "capture_partially_refunded" states can be refunded.');
    }
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    // Validate the requested amount.
    $balance = $payment->getBalance();
    if ($amount->greaterThan($balance)) {
      throw new InvalidRequestException(sprintf("Can't refund more than %s.", $balance->__toString()));
    }

    try {
      $remote_id = $payment->getRemoteId();
      $decimal_amount = $amount->getNumber();
      $result = $this->api->transaction()->refund($remote_id, $decimal_amount);
      ErrorHelper::handleErrors($result);
    }
    catch (Exception $e) {
      ErrorHelper::handleException($e);
    }

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->state = 'capture_partially_refunded';
    }
    else {
      $payment->state = 'capture_refunded';
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      'payment_method_nonce', 'card_type', 'last2',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    if (!$payment_method->isReusable()) {
      $payment_method->card_type = $this->mapCreditCardType($payment_details['card_type']);
      $payment_method->card_number = $payment_details['last2'];

      $remote_id = $payment_details['payment_method_nonce'];
      // Nonces expire after 3h. We reduce that time by 5s to account for the
      // time it took to do the server request after the JS tokenization.
      $expires = REQUEST_TIME + (3600 * 3) - 5;
    }
    else {
      $remote_payment_method = $this->doCreatePaymentMethod($payment_method, $payment_details);
      $payment_method->card_type = $this->mapCreditCardType($remote_payment_method['card_type']);
      $payment_method->card_number = $remote_payment_method['last4'];
      $payment_method->card_exp_month = $remote_payment_method['expiration_month'];
      $payment_method->card_exp_year = $remote_payment_method['expiration_year'];

      $remote_id = $remote_payment_method['token'];
      $expires = CreditCard::calculateExpirationTimestamp($remote_payment_method['expiration_month'], $remote_payment_method['expiration_year']);
    }

    $payment_method->setRemoteId($remote_id);
    $payment_method->setExpiresTime($expires);
    $payment_method->save();
  }

  /**
   * Creates the payment method on the gateway.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   *
   * @return array
   *   The payment method information returned by the gateway. Notable keys:
   *   - token: The remote ID.
   *   Credit card specific keys:
   *   - card_type: The card type.
   *   - last4: The last 4 digits of the credit card number.
   *   - expiration_month: The expiration month.
   *   - expiration_year: The expiration year.
   */
  protected function doCreatePaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $owner = $payment_method->getOwner();
    /** @var \Drupal\address\AddressInterface $address */
    $address = $payment_method->getBillingProfile()->address->first();
    // If the owner is anonymous, the created customer will be blank.
    // https://developers.braintreepayments.com/reference/request/customer/create/php#blank-customer
    $customer_id = NULL;
    $customer_data = [];
    if ($owner) {
      $customer_id = $owner->commerce_remote_id->getByProvider('commerce_squareup');
      $customer_data['email'] = $owner->getEmail();
    }
    $billing_address_data = [
      'billingAddress' => [
        'firstName' => $address->getGivenName(),
        'lastName' => $address->getFamilyName(),
        'company' => $address->getOrganization(),
        'streetAddress' => $address->getAddressLine1(),
        'extendedAddress' => $address->getAddressLine2(),
        'locality' => $address->getLocality(),
        'region' => $address->getAdministrativeArea(),
        'postalCode' => $address->getPostalCode(),
        'countryCodeAlpha2' => $address->getCountryCode(),
      ],
    ];
    $payment_method_data = [
      'cardholderName' => $address->getGivenName() . ' ' . $address->getFamilyName(),
      'paymentMethodNonce' => $payment_details['payment_method_nonce'],
      'options' => [
        'verifyCard' => TRUE,
      ],
    ];

    if ($customer_id) {
      // Create a payment method for an existing customer.
      try {
        $data = $billing_address_data + $payment_method_data + [
          'customerId' => $customer_id,
        ];
        $result = $this->api->paymentMethod()->create($data);
        ErrorHelper::handleErrors($result);
      }
      catch (\Exception $e) {
        ErrorHelper::handleException($e);
      }

      $remote_payment_method = $result->paymentMethod;
    }
    else {
      // Create both the customer and the payment method.
      try {
        $data = $customer_data + [
          'creditCard' => $billing_address_data + $payment_method_data,
        ];
        $result = $this->api->customer()->create($data);
        ErrorHelper::handleErrors($result);
      }
      catch (Exception $e) {
        ErrorHelper::handleException($e);
      }
      $remote_payment_method = $result->customer->paymentMethods[0];
      if ($owner) {
        $customer_id = $result->customer->id;
        $owner->commerce_remote_id->setByProvider('commerce_squareup', $customer_id);
        $owner->save();
      }
    }

    return [
      'token' => $remote_payment_method->token,
      'card_type' => $remote_payment_method->cardType,
      'last4' => $remote_payment_method->last4,
      'expiration_month' => $remote_payment_method->expirationMonth,
      'expiration_year' => $remote_payment_method->expirationYear,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the remote record.
    try {
      $result = $this->api->paymentMethod()->delete($payment_method->getRemoteId());
      ErrorHelper::handleErrors($result);
    }
    catch (Exception $e) {
      ErrorHelper::handleException($e);
    }
    // Delete the local entity.
    $payment_method->delete();
  }

  /**
   * Maps the Braintree credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The Braintree credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  protected function mapCreditCardType($card_type) {
    $map = [
      'American Express' => 'amex',
      'China UnionPay' => 'unionpay',
      'Diners Club' => 'dinersclub',
      'Discover' => 'discover',
      'JCB' => 'jcb',
      'Maestro' => 'maestro',
      'MasterCard' => 'mastercard',
      'Visa' => 'visa',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }

    return $map[$card_type];
  }

}
