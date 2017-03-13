/**
 * @file
 * Defines behaviors for the Squareup payment method form.
 */

(function ($, Drupal, drupalSettings, squareup) {

  'use strict';

  /**
   * Attaches the commerceSquareForm behavior.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the commerceSquareForm behavior.
   *
   * @see Drupal.commerceSquare
   */
  Drupal.behaviors.commerceSquareForm = {
    attach: function (context) {
      var $form = $('.squareup-form', context);
      if ($form.length > 0) {
        squareup = new Drupal.commerceSquareup($form, drupalSettings.commerceSquareup);
      }
    },
    detach: function (context) {
      var $form = $('.squareup-form', context);
      if ($form.length > 0) {
        squareup.integration.teardown();
      }
    }
  };

  /**
   * Wraps the Squareup object with Commerce-specific logic.
   *
   * @constructor
   */
  Drupal.commerceSquareup = function($form, settings) {
    this.settings = settings;
    var paymentForm = new SqPaymentForm({
      applicationId: settings.applicationId,
      inputClass: 'sq-input',
      inputStyles: [
        {
          fontSize: '15px'
        }
      ],
      cardNumber: {
        elementId: 'squareup-card-number',
        placeholder: '•••• •••• •••• ••••'
      },
      cvv: {
        elementId: 'squareup-cvv',
        placeholder: 'CVV'
      },
      expirationDate: {
        elementId: 'squareup-expiration-date',
        placeholder: 'MM/YY'
      },
      postalCode: {
        elementId: 'squareup-postal-code'
      },
      callbacks: {
        // Called when the SqPaymentForm completes a request to generate a card
        // nonce, even if the request failed because of an error.
        cardNonceResponseReceived: function(errors, nonce, cardData) {
          if (errors) {
            console.log("Encountered errors:");
             // This logs all errors encountered during nonce generation to the
            // Javascript console.
            errors.forEach(function(error) {
              console.log('  ' + error.message);
            });
          // No errors occurred. Extract the card nonce.
          }
          else {
            alert('Nonce received: ' + nonce);
            // document.getElementById('card-nonce').value = nonce;
            // document.getElementById('nonce-form').submit();
          }
        },

        unsupportedBrowserDetected: function() {
          // Fill in this callback to alert buyers when their browser is not supported.
        },

        // Fill in these cases to respond to various events that can occur while a
        // buyer is using the payment form.
        inputEventReceived: function(inputEvent) {
          switch (inputEvent.eventType) {
            case 'focusClassAdded':
              // Handle as desired
              break;
            case 'focusClassRemoved':
              // Handle as desired
              break;
            case 'errorClassAdded':
              // Handle as desired
              break;
            case 'errorClassRemoved':
              // Handle as desired
              break;
            case 'cardBrandChanged':
              // Handle as desired
              break;
            case 'postalCodeChanged':
              // Handle as desired
              break;
          }
        },

        paymentFormLoaded: function() {
         paymentForm.setPostalCode('94103');
        }
      }
    });

    paymentForm.build();
    return this;
  };

  // This function is called when a buyer clicks the Submit button on the webpage
  // to charge their card.
  function requestCardNonce(event) {
    // This prevents the Submit button from submitting its associated form.
    // Instead, clicking the Submit button should tell the SqPaymentForm to generate
    // a card nonce, which the next line does.
    event.preventDefault();

    paymentForm.requestCardNonce();
  }

})(jQuery, Drupal, drupalSettings, window.squareup);
