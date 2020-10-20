/**
 * @file
 * A JavaScript file for the VTA Map.
 */

(function ($, Drupal, drupalSettings) {
  $(function () {
    /******************************
     * Route ID category aria label
     ******************************/
    $('.block-views-blockroutes-block-route-id').find('.related-route-reference-link').each(function (i) {
      var routeCategory = $(this).find('.vocabulary-route-category').text();
      $(this).attr('aria-label', $(this).attr('aria-label') + routeCategory);
    });

    /******************************
     * Resize event
     ******************************/
    $(window).resize(function () {
      // Open first tab when moving from mobile (none active) to desktop
      if($(window).width() >= 768 && $('.schedule-tab-general-wrapper .tab.active').length == 0) {
        $('.schedule-tab-general-wrapper .tab-wrapper > .tab:first-of-type a').click();
      }

      // Update table padding for header
      tablePadding();
    });

    /******************************
     * Route schedule tab click
     ******************************/
    $('.block-vta-route-schedule .schedule-tab-general-wrapper .tab a').on('click', function (e) {
      e.preventDefault();
      let schedule_table_id = $(this).attr('data-schedule-table-id');
      let day = $(this).attr('data-day');
      let direction = $(this).attr('data-direction');

      // Mobile closes active tab on click
      if($(window).width() < 768 && $(this).parents('.tab').hasClass('active')) {
        $(this).parents('.tab.active').removeClass('active');
        $(this).parents('.tab').css({
          'margin-bottom' : 0
        });

        $(this).siblings().each(function () {
          $(this).removeClass('active');
        });

        scrollTo('.schedule-tab-general-wrapper');
      }
      else {
        $('.block-vta-route-schedule .schedule-tab-general-wrapper .tab').removeClass('active');
        $(this).closest('.tab').addClass('active');

         // Toggle elements visibility according to tab selected.
        $('.schedule-travel-options-wrapper, .schedule-table-wrapper, .trip-information-wrapper').each(function () {
          if ($(this).attr('data-schedule-table-id') == schedule_table_id) {
            $(this).addClass('active');
          }
          else {
            $(this).removeClass('active');
          }
        });

        // Scroll window to selected tab
        scrollTo(this);

        // Scroll table to active trip
        active_trip = $('.schedule-table-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').find('tr.schedule-table-trip.active');
        if (active_trip.length) {

          // Trip planner info active
          if($('.schedule-table-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').hasClass('trip-planner') == true) {
            $('.schedule-table-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').find('.schedule-table-inner-wrapper').scrollTop(active_trip[0]['offsetTop']);
          }
          else {
            $('.schedule-table-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').find('tbody').scrollTop(active_trip[0]['offsetTop']);
          }
        }
      }

      // ===== toggle aria attributes
      $(this).parent('.tab').siblings().children('a').attr('aria-selected', 'false');
      $(this).attr('aria-selected', function (i, attr) {
        return attr == 'true' ? 'false' : 'true'
      });

      // ==== set hash
      if($(this).attr('aria-selected') == 'true') {
        var tabID = $(this).attr('data-schedule-table-id');
        window.location.hash = tabID;
      }
      // ==== clear hash on close
      else {
        var stateObj = { foo: "bar" };
        history.pushState(stateObj, "clear", " ");
      }

      // Set proper values to Form exposed filter hidden fields.
      $('#vta-route-schedule-form input[name="day_of_travel"]').val(day);
      $('#vta-route-schedule-form input[name="direction"]').val(direction);

      // Schedule Table header width and scrolling
      tableScrolling();
      tablePadding();
    });

    /******************************
     * Travel Options
     * - Click
     * - Keypress (Enter)
     ******************************/
    $('#edit-travel-options > a').on('click', function () {
      $('#edit-travel-options').toggleClass('active');

      $('#edit-travel-options').attr('aria-expanded', function (i, attr) {
        return attr == 'true' ? 'false' : 'true'
      });
    });
    $('#edit-travel-options > a').on('keypress',function (e) {
      if(e.which == 13) {
        $('#edit-travel-options').toggleClass('active');

        $('#edit-travel-options').attr('aria-expanded', function (i, attr) {
          return attr == 'true' ? 'false' : 'true'
        });
      }
    });

    /******************************
     * Schedule Toggler
     ******************************/
    $('.schedule-toggle-wrapper > a.active').on('click', function (e) {
      e.preventDefault();
    });

    /******************************
    * Set table padding for header
    ******************************/
    tablePadding();

    if ($('.schedule-table-wrapper').length != 0) {
      // Schedule Table header width and scrolling
      tableScrolling();

      /******************************
       * Schedule Table - Schedule Time
       ******************************/
      if (drupalSettings.schedule_time != null) {
        current_time = drupalSettings.schedule_time;

        $('.schedule-table-wrapper').each(function () {
          $(this).find('tr.schedule-table-trip').each(function () {
            check_time = $(this).find('td').attr('data-timestamp');
            if (check_time >= current_time) {
              current_trip_id = $(this).attr('data-trip-id');
              $('.schedule-table-wrapper tr.schedule-table-trip[data-trip-id="' + current_trip_id + '"]').addClass('active');
              return false;
            }
          });

          // Scroll table to active trip
          $(this).find('tbody').scrollTop($('.schedule-table-trips-wrapper tr.schedule-table-trip[data-trip-id="' + current_trip_id + '"]')[0]['offsetTop']);
        });
      }

      /******************************
       * Schedule Table - Trip Click
       ******************************/
      $('.schedule-table-wrapper tr.schedule-table-trip').on('click', function (e) {
        // Remove all 'active' classes and add the new 'active class.
        $('.schedule-table-wrapper tr.schedule-table-trip').removeClass('active');
        $('.schedule-table-wrapper tr.schedule-table-trip').css('background-color', '');
        $(this).addClass('active');

        // Set new active trip
        active_time = $(this).find('td').attr('data-timestamp');

        if($(window).width() < 768) {
          $(this).parents('.tab').siblings().each(function () {
            $(this).find('tr.schedule-table-trip').each(function () {
              check_time = $(this).find('td').attr('data-timestamp');
              if (check_time >= active_time) {
                current_trip_id = $(this).attr('data-trip-id');
                $('.schedule-table-wrapper tr.schedule-table-trip[data-trip-id="' + current_trip_id + '"]').addClass('active');
                return false;
              }
            });
          });
        }
        else {
          $(this).parents('.schedule-table-wrapper').siblings().each(function () {
            $(this).find('tr.schedule-table-trip').each(function () {
              check_time = $(this).find('td').attr('data-timestamp');
              if (check_time >= active_time) {
                current_trip_id = $(this).attr('data-trip-id');
                $('.schedule-table-wrapper tr.schedule-table-trip[data-trip-id="' + current_trip_id + '"]').addClass('active');
                return false;
              }
            });
          });
        }
      });
    }
    // no scheudule
    else if ($('.schedule-table-wrapper').length === 0 ) {
      $('body').addClass('no-schedule');
    }

    if ($('select[name="origin"]').val() != '') {
      $('select[name="destination"]').attr('disabled', false);
      $('select[name="destination"]').parent().removeClass('form-disabled');
      selected_origin_id = $('select[name="origin"]').val();
      handleDestinationDisabledOptions();
    }
    else {
      $('select[name="destination"]').attr('disabled', true);
      $('select[name="destination"]').parent().addClass('form-disabled');
    }

    // Handle if destination has a value on load.
    if ($('select[name="destination"]').val() != '') {
      selected_origin_id = $('select[name="origin"]').val();
      handleDestinationDisabledOptions();
    }

    /******************************
     * Handling hash
     ******************************/
    var hash = window.location.hash;

    if (hash.length) {
      var hash = hash.replace('#', '');

      $('.tab').each(function () {
        let schedule_table_id = $(this).children('a').attr('data-schedule-table-id');

        if(schedule_table_id == hash) {
          if ($(this).children('a').attr('data-schedule-table-id') == schedule_table_id) {
            $(this).addClass('active');
          }
        }
        else {
          $(this).removeClass('active');
        }
      });

      $('.schedule-travel-options-wrapper, .schedule-table-wrapper').each(function () {
        let schedule_table_id = $(this).attr('data-schedule-table-id');

        if(schedule_table_id == hash) {
          if ($(this).attr('data-schedule-table-id') == schedule_table_id) {
            $(this).addClass('active');

            // Scroll table to active trip
            active_trip = $('.schedule-table-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').find('tr.schedule-table-trip.active');
            if(active_trip.length) {
              $('.schedule-table-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').find('tbody').scrollTop(active_trip[0]['offsetTop']);
            }
          }
        }
        else {
          $(this).removeClass('active');
        }
      });

      // Set width of table header and horizontal scrolling
      tableScrolling();
    }
    // If not hash and trip info not set
    else if(window.location.search.length == 0) {
      if($(window).width() < 768) {
        $('.tab, .schedule-travel-options-wrapper, .schedule-table-wrapper').each(function () {
          $(this).removeClass('active');
        });
      }
    }
  });

  Drupal.behaviors.vtaRouteScheduleGeneral = {
    attach: function (context, settings) {

      // Reset the Origin and Destination when the Direction changes.
      $('input[name="direction"]').on('change', function (e) {
        $('select[name="origin"]').val('');
        $('select[name="destination"]').val('');
      });

      $('select[name="origin"]').on('change', function (e) {
        selected_origin_id = $(this).val();
        $('select[name="destination"]').val('');

        if (selected_origin_id != '') {
          $('select[name="destination"]').attr('disabled', false);
          $('select[name="destination"]').parent().removeClass('form-disabled');

          handleDestinationDisabledOptions();
        }
        else {
          $('select[name="destination"]').attr('disabled', true);
          $('select[name="destination"]').parent().addClass('form-disabled');
        }
      });
    }
  };

  /******************************
   * handleDestinationDisabledOptions
   ******************************/
  function handleDestinationDisabledOptions() {
    origin_destination_match = false;

    $('select[name="destination"] option').each(function () {
      // Stop is before the Origin Stop.
      if (!origin_destination_match && $(this).val() != selected_origin_id) {
        $(this).attr('disabled', true);
      }
      // Stop is after the Origin Stop.
      else if (origin_destination_match) {
        $(this).attr('disabled', false);
      }
      // Stop is the Origin Stop.
      else {
        $(this).attr('disabled', true);
        origin_destination_match = true;
      }
    });
  }

  /******************************
    * Schedule Table header width and scrolling
  ******************************/
  function tableScrolling() {

    var $scheduleTable = $('.schedule-table-wrapper.active table');
    var $scheduleTableOverflow = $('.schedule-table-wrapper.active .schedule-table-inner-wrapper');
    var $scheduleTableHead = $('.schedule-table-wrapper.active .schedule-table-stops-wrapper');
    var $scheduleTableStops = $('.schedule-table-wrapper.active .schedule-table-stops');
    var $scheduleTableNumberOfStops = $scheduleTableStops.children('th').length;

    // Set the width of the table head row to be the same as the table width
    $scheduleTableStops.width($scheduleTable.width());

    // Set the width of each stop to be equal
    $scheduleTableStops.children('th').width(($scheduleTableHead.width() / $scheduleTableNumberOfStops) / $scheduleTableHead.width() * 100 + '%');

    // Set the width of each stop to be equal on resize
    $(window).on('resize', function () {
      $scheduleTableStops.children('th').width(($scheduleTableHead.width() / $scheduleTableNumberOfStops) / $scheduleTableHead.width() * 100 + '%');
    })

    tablePadding();

    // Set the scroll postion of the table head to be the same as the tables overflow
    // when the table is scrolled on the x-axis
    $scheduleTableOverflow.on('scroll', function (e) {
      e.stopPropagation();
      $scheduleTableHead.scrollLeft($scheduleTableOverflow.scrollLeft());
    });

    $scheduleTableHead.on('scroll', function (e) {
      e.stopPropagation();
      $scheduleTableOverflow.scrollLeft($scheduleTableHead.scrollLeft());
    });
  }

  /******************************
    * Adding padding to top of schedule list to compensate for header (so no times are hidden)
  ******************************/
  function tablePadding() {
    var header = $('.schedule-table-wrapper.active .schedule-table-stops-wrapper').height();
    $('.schedule-table-wrapper.active').css({'margin-top': header });
    $('.schedule-table-wrapper.active .schedule-table-stops-wrapper').css({'top': -header});
  }

   /******************************
   * Scroll top
   ******************************/
  function scrollTo(element) {
    var offset = $(element).offset().top;
    var headerHeight = $('#header').outerHeight() + 20;

    if($('body').hasClass('user-logged-in')) {
      headerHeight += $('#toolbar-administration').height();
    }

    $('html, body').animate({
      scrollTop: eval(offset - headerHeight)
    }, 200);
  }

  /******************************
   * Moves travel options / schedule / into tab on mobile
  ******************************/
  ssm.addState({
    id: 'medium-screen',
    query: '(max-width: 767px)',
    onEnter: function () {
      $('.schedule-tab-general-wrapper .tab').each(function () {
        let schedule_table_id = $(this).children('a').attr('data-schedule-table-id');

        // Travel Options.
        $(this).append($('.schedule-travel-options-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').detach());
        // Route Schedule Table.
        $(this).append($('.schedule-table-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').detach());
        // Trip Information.
        $('.schedule-table-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').after($('.trip-information-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').detach());
      });
    },
    onLeave: function () {
      // Travel Options.
      $('.schedule-travel-options-general-wrapper').append($('.schedule-travel-options-wrapper').detach());
      // Route Schedule Table.
      $('.schedule-table-general-wrapper').append($('.schedule-table-wrapper').detach());

      $('.schedule-tab-general-wrapper .tab').each(function () {
        let schedule_table_id = $(this).children('a').attr('data-schedule-table-id');

        // Trip Information.
        $('.schedule-table-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').after($('.trip-information-wrapper[data-schedule-table-id="' + schedule_table_id + '"]').detach());
      });
    }
  });

})(jQuery, Drupal, drupalSettings);
