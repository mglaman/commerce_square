<?php

namespace Drupal\commerce_square\Plugin\Commerce\PaymentGateway;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_price\Price;
use Drupal\commerce_square\ErrorHelper;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use SquareConnect\Api\LocationsApi;
use SquareConnect\Api\TransactionsApi;
use SquareConnect\ApiClient;
use SquareConnect\ApiException;
use SquareConnect\Configuration;
use SquareConnect\Model\ChargeRequest;
use SquareConnect\Model\CreateRefundRequest;
use SquareConnect\Model\Money;
use SquareConnect\ObjectSerializer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;

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

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);
    $this->time = $time;
    $this->pluginDefinition['modes']['test'] = $this->t('Sandbox');
    $this->pluginDefinition['modes']['live'] = $this->t('Production');
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
      $container->get('datetime.time')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $default_configuration = [
      'app_name' => '',
      'app_secret' => '',
      'live_access_token_expiry' => '',
    ];
    foreach ($this->getSupportedModes() as $mode => $object) {
      $default_configuration[$mode . '_app_id'] = '';
      $default_configuration[$mode . '_location_id'] = '';
      $default_configuration[$mode . '_access_token'] = '';
    }
    return $default_configuration + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $app_id = $this->configuration['live_app_id'];
    $app_secret = $this->configuration['app_secret'];
    if (empty($this->configuration['live_access_token'])) {
      $code = \Drupal::request()->query->get('code');
      if (!empty($code) && !empty($app_id) && !empty($app_secret)) {
        $form['help'] = [
          '#markup' => $this->t('Second step of payment gateway creation: please select production Location.'),
          '#weight' => -10,
        ];
        $client = \Drupal::httpClient();
        // We can send this request only once to square.
        $response = $client->post('https://connect.squareup.com/oauth2/token', [
          'json' => [
            'client_id' => $app_id,
            'client_secret' => $app_secret,
            'code' => $code,
          ],
        ]);
        $this->processTokenResponse($response);
      }
      else {
        $form['help'] = [
          '#markup' => $this->t('First step of payment gateway creation. After clicking save you will be redirected to Square to retrieve a token, then redirected back here to finish the payment gateway creation and select a Location.'),
          '#weight' => -10,
        ];
      }
    }

    $form['app_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application Name'),
      '#default_value' => $this->configuration['app_name'],
      '#required' => TRUE,
    ];
    $form['app_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Application Secret'),
      '#description' => $this->t('You can get this by selecting your app <a href="https://connect.squareup.com/apps">here</a> and clicking on the OAuth tab.'),
      '#default_value' => $this->configuration['app_secret'],
      '#required' => TRUE,
    ];

    foreach ($this->getSupportedModes() as $mode => $object) {
      $form[$mode] = [
        '#type' => 'fieldset',
        '#description' => $this->t('You can get these by selecting your app <a href="https://connect.squareup.com/apps">here</a>.'),
        '#collapsible' => FALSE,
        '#collapsed' => FALSE,
        '#title' => $this->t('@mode credentials', ['@mode' => $this->pluginDefinition['modes'][$mode]]),
        '#tree' => TRUE,
      ];
    }
    $form['live']['#description'] = t('You can get your Application ID by going <a href="https://connect.squareup.com/apps">here</a>, and selecting your application. You will also need to configure your OAuth Redirect URL. Click the OAuth tab and type "https://example.com/admin/commerce_square/oauth/obtain" into the "Redirect URL" field (replace "example.com" with the domain of your Drupal install).');

    $form['test']['access_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox Access Token'),
      '#default_value' => $this->configuration['test_access_token'],
      '#required' => TRUE,
      '#ajax' => [
        'wrapper' => 'square-test-location-wrapper',
        'callback' => [$this, 'locationsAjax'],
        'disable-refocus' => TRUE,
      ],
      '#weight' => 10,
    ];

    foreach ($this->getSupportedModes() as $mode => $object) {
      $form[$mode]['app_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Application ID'),
        '#default_value' => $this->configuration[$mode . '_app_id'],
        '#required' => TRUE,
      ];

      $form[$mode]['location_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'square-' . $mode . '-location-wrapper'],
        '#weight' => 20,
      ];

      $values = NestedArray::getValue($form_state->getUserInput(), $form['#parents']);
      $access_token = $values[$mode]['access_token'];
      if (empty($access_token)) {
        $values = NestedArray::getValue($form_state->getValues(), $form['#parents']);
        $access_token = $values[$mode]['access_token'];
        if (empty($access_token)) {
          $access_token = $this->configuration[$mode . '_access_token'];
        }
      }
      $location_markup = TRUE;
      if (!empty($access_token)) {
        $config = new Configuration();
        $config->setAccessToken($access_token);
        $location_api = new LocationsApi(new ApiClient($config));
        try {
          $this->renewAccessToken();
          $locations = $location_api->listLocations();
          if (!empty($locations)) {
            $location_options = $locations->getLocations();
            $options = [];
            foreach ($location_options as $location_option) {
              $options[$location_option->getId()] = $location_option->getName();
            }

            $form[$mode]['location_wrapper']['location_id'] = [
              '#type' => 'select',
              '#title' => $this->t('Location ID'),
              '#default_value' => $this->configuration[$mode . '_location_id'],
              '#required' => TRUE,
              '#options' => $options,
            ];
            $location_markup = FALSE;
          }
        }
        catch (\Exception $e) {
          drupal_set_message($e->getMessage(), 'error');
        }
      }
      if ($location_markup && 'test' === $mode) {
        $form[$mode]['location_wrapper']['location_id'] = [
          '#markup' => $this->t('Please provide a valid access token to select a @mode location ID.', ['@mode' => $mode]),
        ];
      }
    }
    return $form;
  }

  /**
   * AJAX callback for the configuration form.
   */
  public static function locationsAjax(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $parents = $triggering_element['#parents'];
    array_pop($parents);
    $credentials = NestedArray::getValue($form, $parents);
    return $credentials['location_wrapper'];
  }

  /**
   * Processes a retrieved token response.
   *
   * Used both when getting a token for the first time and also when renewing
   * it.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   */
  protected function processTokenResponse($response) {
    $response_body = json_decode($response->getBody());
    if (!empty($response_body->access_token)) {
      $this->configuration['live_access_token'] = $response_body->access_token;
      // Save the gateway entity right away to make sure we send the above
      // request only once to Square.
      $gateway= $this->entityTypeManager
        ->getStorage('commerce_payment_gateway')
        ->load($this->entityId);
      $configuration = $gateway->get('configuration');
      $configuration['live_access_token'] = $response_body->access_token;
      $configuration['live_access_token_expiry'] = strtotime($response_body->expires_at);
      $gateway->set('configuration', $configuration);
      $gateway->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    if (empty($values['test']['location_wrapper']['location_id'])) {
      $form_state->setErrorByName('configuration[test_access_token]', $this->t('Please provide a valid application sandbox token to select a test location ID.'));
    }
    if (empty($values['live']['location_wrapper']['location_id']) && !empty($this->getConfiguration()['live_access_token'])) {
      $form_state->setErrorByName('configuration[test_access_token]', $this->t('Please perform the OAuth flow to get an access token and to select a test location ID.'));
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
      $this->configuration['app_secret'] = $values['app_secret'];

      foreach ($this->getSupportedModes() as $mode => $object) {
        $this->configuration[$mode . '_app_id'] = $values[$mode]['app_id'];
        $this->configuration[$mode . '_location_id'] = $values[$mode]['location_wrapper']['location_id'];
      }
      // The live access token is saved in ::buildConfigurationForm().
      $this->configuration['test_access_token'] = $values['test']['access_token'];
    }

    // We obtain the live access token via OAuth. Sandbox uses the
    // omnipotent personal access token.
    if (!empty($this->configuration['live_app_id']) && empty($this->configuration['live_access_token'])) {
      $options = [
        'absolute' => TRUE,
        'query' => [
          'client_id' => $this->configuration['live_app_id'],
          'state' => \Drupal::csrfToken()->get() . ' ' . $this->entityId,
          'scope' => 'MERCHANT_PROFILE_READ PAYMENTS_READ PAYMENTS_WRITE CUSTOMERS_READ CUSTOMERS_WRITE',
        ],
      ];

      $url = Url::fromUri('https://connect.squareup.com/oauth2/authorize', $options);
      $form_state->setResponse(new TrustedRedirectResponse($url->toString()));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getApiClient() {
    $config = new Configuration();
    $config->setAccessToken($this->configuration[$this->getMode() . '_access_token']);
    return new ApiClient($config);
  }

  /**
   * {@inheritdoc}
   */
  public function renewAccessToken() {
    if (empty($this->configuration['live_app_id']) || empty($this->configuration['live_access_token'])) {
      return;
    }
    if (!empty($this->configuration['live_access_token_expiry']) && $this->configuration['live_access_token_expiry'] < \Drupal::time()->getRequestTime()) {
      $client = \Drupal::httpClient();
      // We can send this request only once to square.
      try {
        $response = $client->post('https://connect.squareup.com/oauth2/clients/' . $this->configuration['live_app_id'] . '/access-token/renew', [
          'json' => [
            'access_token' => $this->configuration['live_access_token'],
          ],
        ]);
      }
      catch (\Exception $e) {
        throw ErrorHelper::convertException($e);
      }
      $this->processTokenResponse($response);
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
    $charge_request->offsetSet('integration_id', 'sqi_b6ff0cd7acc14f7ab24200041d066ba6');
    $charge_request->setDelayCapture(!$capture);
    $charge_request->setCardNonce($payment_method->getRemoteId());
    $charge_request->setIdempotencyKey(uniqid());
    $charge_request->setBuyerEmailAddress($payment->getOrder()->getEmail());

    $mode = $this->getMode();
    // Since the SDK does not support `integration_id`, we must call it direct.
    try {
      $api_client = $this->getApiClient();
      $resourcePath = "/v2/locations/{location_id}/transactions";
      $queryParams = [];
      $headerParams = [];
      $headerParams['Accept'] = ApiClient::selectHeaderAccept(['application/json']);
      $headerParams['Content-Type'] = ApiClient::selectHeaderContentType(['application/json']);
      $headerParams['Authorization'] = $api_client->getSerializer()->toHeaderValue($this->configuration[$mode . '_access_token']);

      $resourcePath = str_replace(
        '{location_id}',
        $api_client->getSerializer()->toPathValue($this->configuration[$mode . '_location_id']),
        $resourcePath
      );

      $charge_request = $charge_request->__toString();
      // The `integration_id` is only valid when live.
      if ($mode == 'live') {
        $charge_request = json_decode($charge_request, TRUE);
        $charge_request['integration_id'] = 'sqi_b6ff0cd7acc14f7ab24200041d066ba6';
        $charge_request = json_encode($charge_request, JSON_PRETTY_PRINT);
      }

      try {
        list($response, $statusCode, $httpHeader) = $api_client->callApi(
          $resourcePath, 'POST',
          $queryParams, $charge_request,
          $headerParams, '\SquareConnect\Model\ChargeResponse'
        );
        if (!$response) {
          return [NULL, $statusCode, $httpHeader];
        }

        /** @var \SquareConnect\Model\ChargeResponse $result */
        $result = ObjectSerializer::deserialize($response, '\SquareConnect\Model\ChargeResponse', $httpHeader);
      }
      catch (ApiException $e) {
        switch ($e->getCode()) {
          case 200:
            $data = ObjectSerializer::deserialize($e->getResponseBody(), '\SquareConnect\Model\ChargeResponse', $e->getResponseHeaders());
            $e->setResponseObject($data);
            break;
        }

        throw $e;
      }

      // @todo Use once SDK supports `integration_id`
      // $result = $this->transactionApi->charge(
      // $this->configuration[$mode . '_access_token'],
      // $this->configuration[$mode . '_location_id'],
      // $charge_request
      // );
      // if ($result->getErrors()) { }
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

    $mode = $this->getMode();
    try {
      $transaction_api = new TransactionsApi($this->getApiClient());
      $result = $transaction_api->captureTransaction(
        $this->configuration[$mode . '_location_id'],
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
    $mode = $this->getMode();
    try {
      $transaction_api = new TransactionsApi($this->getApiClient());
      $result = $transaction_api->voidTransaction(
        $this->configuration[$mode . '_location_id'],
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
      'amount_money' => new Money([
        'amount' => (int) $square_amount->getNumber(),
        'currency' => $amount->getCurrencyCode(),
      ]),
      'reason' => (string) $this->t('Refunded through store backend'),
    ]);

    $mode = $this->getMode();
    try {
      $transaction_api = new TransactionsApi($this->getApiClient());
      $result = $transaction_api->createRefund(
        $this->configuration[$mode . '_location_id'],
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
