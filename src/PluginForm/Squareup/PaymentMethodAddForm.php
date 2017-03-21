<?php

namespace Drupal\commerce_squareup\PluginForm\Squareup;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Payment method add form for Square.
 */
class PaymentMethodAddForm extends BasePaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_squareup\Plugin\Commerce\PaymentGateway\Squareup $plugin */
    $plugin = $this->plugin;

    $element['#attached']['library'][] = 'commerce_squareup/squareup';
    $element['#attached']['library'][] = 'commerce_squareup/form';
    $element['#attached']['drupalSettings']['commerceSquareup'] = [
      'applicationId' => $plugin->getApplicationId(),
    ];
    $element['#attributes']['class'][] = 'squareup-form';
    // Populated by the JS library.
    $element['payment_method_nonce'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['squareup-nonce'],
      ],
    ];
    $element['card_type'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['squareup-card-type'],
      ],
    ];
    $element['last2'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'class' => ['squareup-last2'],
      ],
    ];

    $element['number'] = [
      '#type' => 'item',
      '#title' => t('Card number'),
      '#markup' => '<div id="squareup-card-number"></div>',
    ];

    $element['expiration'] = [
      '#type' => 'item',
      '#title' => t('Expiration'),
      '#markup' => '<div id="squareup-expiration-date"></div>',
    ];
    $element['cvv'] = [
      '#type' => 'item',
      '#title' => t('CVV'),
      '#markup' => '<div id="squareup-cvv"></div>',
    ];
    $element['postal-code'] = [
      '#type' => 'item',
      '#title' => t('Postal code'),
      '#markup' => '<div id="squareup-postal-code"></div>',
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    // The JS library performs its own validation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    // The payment gateway plugin will process the submitted payment details.
  }

}
