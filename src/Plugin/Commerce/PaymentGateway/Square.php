<?php

namespace Drupal\commerce_square\Plugin\Commerce\PaymentGateway;

use Drupal\commerce\TimeInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_price\Price;
use Drupal\commerce_square\ErrorHelper;
use SquareConnect\Api\LocationApi;
use SquareConnect\Api\RefundApi;
use SquareConnect\Api\TransactionApi;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use SquareConnect\ApiException;
use SquareConnect\Model\ChargeRequest;
use SquareConnect\Model\CreateRefundRequest;
use SquareConnect\Model\Money;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\NestedArray;

/**
 * Provides the Square payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "square",
 *   label = "Square",
 *   display_label = "Square",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_square\PluginForm\Square\PaymentMethodAddForm",
 *   },
 *   js_library = "commerce_square/square_connect",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa",
 *   },
 * )
 */
class Square extends OnsitePaymentGatewayBase implements SquareInterface {

  protected $time;
  protected $transactionApi;
  protected $refundApi;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);
    $this->time = $time;
    $this->transactionApi = new TransactionApi();
    $this->refundApi = new RefundApi();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('commerce.time')
    );
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
        'wrapper' => 'square-location-wrapper',
        'callback' => [$this, 'locationsAjax'],
        // Needs the patch in https://www.drupal.org/node/2627788.
        'disable-refocus' => TRUE,
      ],
    ];
    $form['location_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'square-location-wrapper'],
    ];
    $values = $form_state->getValues();
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
        '#markup' => $this->t('Please provide a valid personal access token to select a location ID.'),
      ];
    }
    return $form;
  }

  /**
   * AJAX callback for the configuration form.
   */
  public function locationsAjax(array &$form, FormStateInterface $form_state) {
    return $form['configuration']['location_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    if (empty($values['location_wrapper']['location_id'])) {
      $form_state->setErrorByName('configuration[personal_access_token]', $this->t('Please provide a valid personal access token to select a location ID.'));
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
      $this->configuration['location_id'] = $values['location_wrapper']['location_id'];
    }
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
    if ($this->time->getRequestTime() >= $payment_method->getExpiresTime()) {
      throw new HardDeclineException('The provided payment method has expired');
    }
    $amount = $payment->getAmount();
    $amount = \Drupal::getContainer()->get('commerce_price.rounder')->round($amount);
    // Square only accepts integers and not floats.
    // @see https://docs.connect.squareup.com/api/connect/v2/#workingwithmonetaryamounts
    $amount = $amount->multiply('100');

    $charge_request = new ChargeRequest();
    $charge_request->setAmountMoney(new Money([
      'amount' => (int) $amount->getNumber(),
      'currency' => $amount->getCurrencyCode(),
    ]));
    $charge_request->setDelayCapture(!$capture);
    $charge_request->setCardNonce($payment_method->getRemoteId());
    $charge_request->setIdempotencyKey(uniqid());
    $charge_request->setBuyerEmailAddress($payment->getOrder()->getEmail());

    try {
      $result = $this->transactionApi->charge(
        $this->configuration['personal_access_token'],
        $this->configuration['location_id'],
        $charge_request
      );
      if ($result->getErrors()) {
        // @todo check.
      }
    }
    catch (ApiException $e) {
      throw ErrorHelper::convertException($e);
    }

    $transaction = $result->getTransaction();
    $tender = $transaction->getTenders()[0];

    $payment->state = $capture ? 'capture_completed' : 'authorization';
    $payment->setRemoteId($transaction->getId() . '|' . $tender->getId());
    $payment->setAuthorizedTime($transaction->getCreatedAt());
    // @todo Find out how long an authorization is valid, set its expiration.
    if ($capture) {
      $payment->setCapturedTime($result->getTransaction()->getCreatedAt());
    }
    else {
      $expires = $this->time->getRequestTime() + (3600 * 24 * 6) - 5;
      $payment->setAuthorizationExpiresTime($expires);
    }
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      'payment_method_nonce', 'card_type', 'last4',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    // @todo Make payment methods reusable. Currently they represent 24hr nonce.
    // @see https://docs.connect.squareup.com/articles/processing-recurring-payments-ruby
    // Meet specific requirements for reusable, permanent methods.
    $payment_method->setReusable(FALSE);
    $payment_method->card_type = $this->mapCreditCardType($payment_details['card_type']);
    $payment_method->card_number = $payment_details['last4'];
    $payment_method->card_exp_month = $payment_details['exp_month'];
    $payment_method->card_exp_year = $payment_details['exp_year'];
    $remote_id = $payment_details['payment_method_nonce'];
    $payment_method->setRemoteId($remote_id);

    // Nonces expire after 24h. We reduce that time by 5s to account for the
    // time it took to do the server request after the JS tokenization.
    $expires = $this->time->getRequestTime() + (3600 * 24) - 5;
    $payment_method->setExpiresTime($expires);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // @todo Currently there are no remote records stored.
    // Delete the local entity.
    $payment_method->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $amount = $amount ?: $payment->getAmount();
    // Square only accepts integers and not floats.
    // @see https://docs.connect.squareup.com/api/connect/v2/#workingwithmonetaryamounts
    $square_amount = \Drupal::getContainer()->get('commerce_price.rounder')->round($amount);
    $square_amount = $square_amount->multiply('100');
    list($transaction_id, $tender_id) = explode('|', $payment->getRemoteId());

    try {
      $result = $this->transactionApi->captureTransaction(
        $this->configuration['personal_access_token'],
        $this->configuration['location_id'],
        $transaction_id
      );
    }
    catch (ApiException $e) {
      throw ErrorHelper::convertException($e);
    }

    $payment->state = 'capture_completed';
    $payment->setAmount($amount);
    $payment->setCapturedTime($this->time->getRequestTime());
    $payment->save();

  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    list($transaction_id, $tender_id) = explode('|', $payment->getRemoteId());
    try {
      $result = $this->transactionApi->voidTransaction(
        $this->configuration['personal_access_token'],
        $this->configuration['location_id'],
        $transaction_id
      );
    }
    catch (ApiException $e) {
      throw ErrorHelper::convertException($e);
    }
    $payment->state = 'authorization_voided';
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $amount = $amount ?: $payment->getAmount();
    // Square only accepts integers and not floats.
    // @see https://docs.connect.squareup.com/api/connect/v2/#workingwithmonetaryamounts
    $square_amount = \Drupal::getContainer()->get('commerce_price.rounder')->round($amount);
    $square_amount = $square_amount->multiply('100');

    list($transaction_id, $tender_id) = explode('|', $payment->getRemoteId());
    $refund_request = new CreateRefundRequest([
      'idempotency_key' => uniqid(),
      'tender_id' => $tender_id,
      'amount_money' => $square_amount->getNumber(),
      'reason' => $this->t('Refunded through store backend'),
    ]);

    try {
      $result = $this->refundApi->createRefund(
        $this->configuration['personal_access_token'],
        $this->configuration['location_id'],
        $transaction_id,
        $refund_request
      );
    }
    catch (ApiException $e) {
      throw ErrorHelper::convertException($e);
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
   * Maps the Square credit card type to a Commerce credit card type.
   *
   * @param string $card_type
   *   The Square credit card type.
   *
   * @return string
   *   The Commerce credit card type.
   */
  protected function mapCreditCardType($card_type) {
    $map = [
      'AMERICAN_EXPRESS' => 'amex',
      'CHINA_UNIONPAY' => 'unionpay',
      'DISCOVER_DINERS' => 'dinersclub',
      'DISCOVER' => 'discover',
      'JCB' => 'jcb',
      'MASTERCARD' => 'mastercard',
      'VISA' => 'visa',
    ];
    if (!isset($map[$card_type])) {
      throw new HardDeclineException(sprintf('Unsupported credit card type "%s".', $card_type));
    }

    return $map[$card_type];
  }

}
