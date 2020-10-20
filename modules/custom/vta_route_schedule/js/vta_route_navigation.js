/**
 * @file
 * A JavaScript file for the VTA Map.
 */

(function ($, Drupal) {
  $(function () {
    $('#block-route-navigation-block .vta-route-navigation-form #edit-route').on('change', function () {
      if ($(this)[0]['value'].length) {
        $('#block-route-navigation-block .vta-route-navigation-form #edit-submit-route-navigation').click();
      }
    });
  });

})(jQuery, Drupal);
