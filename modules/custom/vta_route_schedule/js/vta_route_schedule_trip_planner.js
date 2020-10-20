/**
 * @file
 * A JavaScript file for the VTA Map.
 */

(function ($, Drupal, drupalSettings) {

  var table_selector = '.schedule-table-wrapper[data-schedule-table-id="' + drupalSettings.active_table + '"]';
  var trip_info_selector = '.trip-information-wrapper[data-schedule-table-id="' + drupalSettings.active_table + '"]';
  $(function () {
    if ($(table_selector).length != 0) {
      $(table_selector).addClass('trip-planner');

      /******************************
      * Default to trip at current time
      * or last trip if no trips are at current time.
      ******************************/
      var current_time = Math.round((new Date()).getTime() / 1000);
      var check_time = 0;
      var schedule_table_trip_rows = $(table_selector + ' tr.schedule-table-trip');

      schedule_table_trip_rows.each(function (index) {
        check_time = $(this).find('td[data-timestamp][data-timestamp!=""]').attr('data-timestamp');

        if (check_time >= current_time || index === schedule_table_trip_rows.length - 1) {
          current_trip_id = $(this).attr('data-trip-id');
          $(table_selector + ' tr.schedule-table-trip[data-trip-id="' + current_trip_id + '"]').addClass('active');
          return false;
        }
      });

      // Scroll table to active trip
      active_trip = $(table_selector + ' tr.schedule-table-trip[data-trip-id="' + current_trip_id + '"]');
      if (active_trip.length) {
        $(table_selector).find('.schedule-table-inner-wrapper').scrollTop(active_trip[0]['offsetTop']);
      }

      buildTripInformation(current_trip_id);
      $(trip_info_selector).addClass('active');

      /******************************
       * Schedule Table Trip Click
       ******************************/
      $(table_selector + ' tr.schedule-table-trip').on('click', function (e) {
        current_trip_id = $(this).attr('data-trip-id');
        $('.trip-information-wrapper').remove();
        buildTripInformation(current_trip_id);
        $(trip_info_selector).addClass('active');
      });

       /******************************
       * Inbetween trips summary click
       ******************************/
      $('.trip-inbetween-stops > summary').on('click', function () {
        setTimeout(function () {
          scheduleOrder();
        }, 300);
      });

      /******************************
       * Handling hash
       ******************************/
      var hash = window.location.hash;

      if (hash.length) {
        var hash = hash.replace('#', '');
        let schedule_table_id = $('.trip-information-wrapper').attr('data-schedule-table-id');

        if(schedule_table_id == hash) {
          if ($('.trip-information-wrapper').attr('data-schedule-table-id') == schedule_table_id) {
            $(this).addClass('active');
          }
        }
        else {
          $('.trip-information-wrapper').removeClass('active');
        }
      }
    }
  });

  function buildTripInformation(current_trip_id) {
    trip_information_html = '';

    /************************************************************
     * Trip Information
     * - Origin
     * - Inbetween Stops
     * - Destination
     * - Trip Duration
     ************************************************************/
    trip_information_html += '<div class="trip-information-wrapper" data-schedule-table-id="' + drupalSettings.active_table + '" aria-labelledby="' + drupalSettings.active_table + '">';
    trip_information_html += '<div class="trip-information-header">' + 'Trip Information' + '</div>';

    // Calculate duration of stops to use later.
    var origin_stop_sequence = 0;
    var origin_stop_type = '';
    var destination_stop_sequence = 0;
    var destination_stop_type = '';
    $.each(drupalSettings.stop_sequence, function (key, value) {
      if (value.stop_id === drupalSettings.origin) {
        origin_stop_sequence = key;
        origin_stop_type = value['stop_type'];
      }

      if (origin_stop_sequence != 0 && value.stop_id === drupalSettings.destination) {
        destination_stop_sequence = key;
        destination_stop_type = value['stop_type'];
        return;
      }
    });
    duration_stops = destination_stop_sequence - origin_stop_sequence;

    /************************************************************
     * Trip Summary
     * - # of Stops / Time
     ************************************************************/
    trip_information_html += '<div class="trip-summary-wrapper">';
    /******************************
     * Time
     ******************************/
    origin_time = drupalSettings.trips[current_trip_id][drupalSettings.origin];
    if (origin_time === undefined || origin_time == '') {
      origin_time = drupalSettings.trips[current_trip_id][drupalSettings.schedule_timepoints['timepoint_adjustments'][drupalSettings.origin]['before']];
    }
    origin_moment = moment.duration(origin_time, 'HH:mm');

    destination_time = drupalSettings.trips[current_trip_id][drupalSettings.destination];
    if (destination_time === undefined || destination_time == '') {
      destination_time = drupalSettings.trips[current_trip_id][drupalSettings.schedule_timepoints['timepoint_adjustments'][drupalSettings.destination]['after']];
    }
    destination_moment = moment.duration(destination_time, 'HH:mm');

    duration_time = destination_moment.subtract(origin_moment);
    duration_time_formatted = '';

    // Hours
    if (duration_time.hours() > 0) {
      duration_time_formatted += duration_time.hours() + ' hour(s) ';
    }
    // Minutes
    if (duration_time.minutes() > 0) {
      duration_time_formatted += duration_time.minutes() + ' minute(s)';
    }
    // Trip Summary.
    trip_information_html += '<div class="trip-summary">';
    trip_information_html += '<span>Trip Summary:</span> ' + (duration_stops + 1) + ' stop(s)' + ' / ' + duration_time_formatted.trim();
    trip_information_html += '</div>';
    // Stop Type Legend.
    trip_information_html += '<div class="stop-type-legend">';
    trip_information_html += '<span class="timepoint">Timepoints</span>';
    trip_information_html += '<span class="stop">Estimated departures</span>';
    trip_information_html += '</div>';

    // Close trip-summary-wrapper.
    trip_information_html += '</div>';

    /************************************************************
     * Origin
     * - Stop Name
     * - Arrival Time
     ************************************************************/
    trip_information_html += '<div class="trip-origin-wrapper">';
    /******************************
     * Stop Name
     ******************************/
    trip_information_html += '<div class="trip-origin-name">' + drupalSettings.stops[drupalSettings.origin] + '</div>'
    /******************************
     * Arrival Time
     ******************************/
    trip_information_html += '<div class="trip-origin-arrival-time ' + origin_stop_type + '">' + convertTime(origin_time) + '</div>';

    // Close trip-origin-wrapper.
    trip_information_html += '</div>';

    /************************************************************
     * Inbetween Stops
     * - Stop Name
     * - Arrival Time
     ************************************************************/
    // Don't display inbetween stops if there aren't any.
    if ((duration_stops - 1) > 0) {
      trip_information_html += '<details class="trip-inbetween-stops">';
      trip_information_html += '<summary>' + (duration_stops - 1) + ' stop(s)</summary>';

      hit_origin = false;
      hit_destination = false;

      $.each(drupalSettings.stop_sequence, function (key, value) {
        // Check if stop is the origin or destination.
        if (value.stop_id == drupalSettings.origin) {
          hit_origin = true;
        }
        else if (value.stop_id == drupalSettings.destination) {
          hit_destination = true;
        }
        // If stop is between origin and destination, then include it.
        if (hit_origin && value.stop_id != drupalSettings.origin && !hit_destination) {

          inbetween_stop_time = drupalSettings.trips[current_trip_id][value.stop_id];

          if (inbetween_stop_time === undefined || inbetween_stop_time == '') {
            if (
              drupalSettings.schedule_timepoints['timepoint_adjustments'][value.stop_id] !== undefined &&
              drupalSettings.schedule_timepoints['timepoint_adjustments'][value.stop_id]['before'] !== undefined &&
              drupalSettings.trips[current_trip_id][drupalSettings.schedule_timepoints['timepoint_adjustments'][value.stop_id]['before']] !== undefined
            ) {
              inbetween_stop_time = drupalSettings.trips[current_trip_id][drupalSettings.schedule_timepoints['timepoint_adjustments'][value.stop_id]['before']];
            }
          }

          trip_information_html += '<div class="trip-inbetween-stop-wrapper">';

          /******************************
           * Stop Name
           ******************************/
          trip_information_html += '<div class="trip-inbetween-stop-name">' + drupalSettings.stops[value.stop_id] + '</div>';

          /******************************
           * Arrival Time
           ******************************/
          if (inbetween_stop_time !== undefined && inbetween_stop_time !== '') {
            trip_information_html += '<div class="trip-inbetween-stop-arrival-time ' + value['stop_type'] + '">' + convertTime(inbetween_stop_time) + '</div>';
          }
          else {
            trip_information_html += '<div class="trip-inbetween-stop-arrival-time' + value['stop_type'] + '">-</div>';
          }

          // Close trip-inbetween-stop-wrapper.
          trip_information_html += '</div>';
        }
      });
      // Close trip-inbetween-stops-wrapper.
      trip_information_html += '</details>';
    }

    /************************************************************
     * Destination
     * - Stop Name
     * - Arrival Time
     ************************************************************/
    trip_information_html += '<div class="trip-destination-wrapper">';
    /******************************
     * Stop Name
     ******************************/
    trip_information_html += '<div class="trip-destination-name">' + drupalSettings.stops[drupalSettings.destination] + '</div>';
    /******************************
     * Arrival Time
     ***************************/
    trip_information_html += '<div class="trip-destination-arrival-time ' + destination_stop_type + '">' + convertTime(destination_time) + '</div>';

    // Close trip-destination-wrapper.
    trip_information_html += '</div>';

    // Close trip-information-wrapper.
    trip_information_html += '</div>';

    $(table_selector).after(trip_information_html);
  }

  // Helper function to convert time to short American format.
  function convertTime(time) {
    let exploded = time.split(':');

    if (parseInt(exploded[0]) === 0 || parseInt(exploded[0]) === 24) {
      return `12:${exploded[1]} AM`;
    }
    else if (parseInt(exploded[0]) < 12) {
      return `${parseInt(exploded[0])}:${exploded[1]} AM`;
    }
    else if (parseInt(exploded[0]) === 12) {
      return `12:${exploded[1]} PM`;
    }
    else if (parseInt(exploded[0]) > 12 && parseInt(exploded[0]) < 24) {
      let hour = parseInt(exploded[0]) - 12;
      return `${hour}:${exploded[1]} PM`;
    }
  }

 /******************************
 * Moves travel options / schedule / trip information below tab
 ******************************/
function scheduleOrder() {
  if ($(window).width() < 768) {
    $('.schedule-tab-general-wrapper .tab').css({
      'margin-bottom' : 0
    });

    var scheduleTabOffset = $('.schedule-tab-general-wrapper .tab.active').offset().top;
    var scheduleTabHeight = $('.schedule-tab-general-wrapper .tab.active').height();
    var travelOptionsHeight = $('.schedule-travel-options-general-wrapper').height();
    var scheduleHeight = $('.schedule-table-general-wrapper').height();

    $('.schedule-travel-options-general-wrapper').css({
      'position' : 'absolute',
      'top': scheduleTabOffset + scheduleTabHeight,
      'width' : 'calc(100% - 1.875em)'
    });

    $('.schedule-table-general-wrapper').css({
      'position' : 'absolute',
      'top': scheduleTabOffset + scheduleTabHeight + travelOptionsHeight,
      'width' : 'calc(100% - 1.875em)'
    });

    $('.schedule-tab-general-wrapper .tab.active').css({
      'margin-bottom' : travelOptionsHeight + scheduleHeight
    });
  }
  else {
    $('.schedule-travel-options-general-wrapper').css({
      'position' : 'static',
      'top': 'unset',
      'width' : 'auto'
    });

    $('.schedule-table-general-wrapper').css({
      'position' : 'static',
      'top': 'unset',
      'width' : '100%'
    });

    $('.schedule-tab-general-wrapper .tab.active').css({
      'margin-bottom' : '0'
    });
  }
}

})(jQuery, Drupal, drupalSettings);
