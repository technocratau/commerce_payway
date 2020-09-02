/**
 * @file
 * Javascript to generate Payway Frame token in PCI-compliant way.
 */

(function ($, Drupal, drupalSettings) {

  'use strict';

  var frameInstance = null;

  /**
   * Attaches the commercePaywayForm behavior.
   *
   * @type {Drupal~behavior}
   *
   * @see Drupal.commercePaywayForm
   */
  Drupal.behaviors.commercePaywayApiFrameForm = {

    attach: function (context) {
      $(context).find('#payway-credit-card').once('commercePaywayApiFrameForm').each(
        function () {
          if (frameInstance !== null) {
            frameInstance.destroy();
          }

          payway.createCreditCardFrame(
            {
              publishableApiKey: drupalSettings.commercePaywayStoredForm.publishableKey
            }, function (err, frame) {
              frameInstance = frame;
            }
          );
        }
      );

    }

  };

})(jQuery, Drupal, drupalSettings);
