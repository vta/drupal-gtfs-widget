<?php

namespace Drupal\vta_gtfs_import\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use League\Csv\Reader;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides base VTA GTFS import functionality.
 */
abstract class VtaGtfsImportGetBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * QueueFactory.
   *
   * @var Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * QueueWorkerManagerInterface.
   *
   * @var Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueManager;

  /**
   * QueueFactory.
   *
   * @var Drupal\Core\Queue\QueueFactory
   */
  protected $queue;

  /**
   * {@inheritdoc}
   */
  public function __construct(QueueFactory $queue, QueueWorkerManagerInterface $queue_manager) {
    $this->queueFactory = $queue;
    $this->queueManager = $queue_manager;
    $this->queue = $this->queueFactory->get('vta_gtfs_import_save_base', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $import_key = $data->content['key'];
    $info = $data->content['info'];
    $version = $data->content['version'];

    if (isset($info) && !empty($info)) {
      $csv = $this->parseCsvData($info);

      // Creating associative keys.
      $csv_header = array_shift($csv);
      $csv_header_keys = array_values($csv_header);
      foreach ($csv_header_keys as $csv_header_key) {
        $header_keys[] = strtolower(str_replace(' ', '_', $this->cleanText($csv_header_key)));
      }

      // Get the count of the CSV.
      $csv_row_count = count($csv);

      if (!empty($csv) && is_array($csv)) {
        $all_results = [];
        foreach ($csv as $key => $row) {
          $results = [];

          // Clean text and create array.
          foreach ($row as $field_key => $field) {
            $results[$header_keys[$field_key]] = $this->cleanText($field);
          }

          // Map the results.
          $mapped_data = $this->dataMapping($results, $import_key);
          if (!empty($mapped_data)) {
            $all_results[] = $mapped_data;
          }

          // Create items.
          if (count($all_results) >= 1000 || $key == $csv_row_count - 1) {
            $item = new \stdClass();
            $item->key = $import_key;
            $item->info = $all_results;
            $item->version = $version;
            $this->queue->createItem($item);
            $all_results = [];
          }
        }
      }
    }
  }

  /**
   * Helper function to clean incoming data.
   *
   * @param string $text
   *   Text that needs to be cleaned up.
   *
   * @return string
   *   Clean version of the text.
   */
  protected function cleanText($text) {
    $text = str_replace('"', '', $text);
    // Remove the BOM (Byte Order Mark).
    $text = str_replace("\xEF\xBB\xBF", '', $text);
    $text = trim($text);

    return $text;
  }

  /**
   * Helper function to parse CSV string into array.
   *
   * @param string $data
   *   String data from the CSV.
   *
   * @return array
   *   Array of parsed data.
   */
  protected function parseCsvData($data) {
    $parsed_data = [];

    if (isset($data)) {
      $csv = Reader::createFromString($data);
      $csv->setDelimiter(",");
      $csv->setEnclosure('"');
      $parsed_data = $csv->fetchAll();
    }

    return $parsed_data;
  }

  /**
   * Helper function to map data.
   *
   * @param array $info
   *   Import data.
   * @param string $key
   *   Import key.
   *
   * @return array
   *   Data array that has been mapped.
   */
  protected function dataMapping(array $info, $key) {
    $mapped_info = [];

    switch ($key) {
      /************************************************************
       * Routes
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#routestxt
       ************************************************************
       * Extended Route Type Reference:
       * http://developers.google.com/transit/gtfs/reference/
       * extended-route-types
       ************************************************************
       * Mapping:
       * - route_id                   -> field_route_id
       * - route_short_name           -> field_route_short_name
       * - route_long_name            -> title / field_route_long_name
       * - route_desc                 -> body
       * - route_type                 -> field_route_category
       * - route_url                  -> field_route_url
       * - route_color                -> field_route_color
       * - route_text_color           -> field_route_text_color
       * - route_sort_order           -> field_route_sort_order
       ************************************************************
       * Mapping (extended):
       * - ext_route_type             -> field_extended_route_category
       ************************************************************/
      case 'gtfs_routes':
        // Route ID.
        if (isset($info['route_id'])) {
          $mapped_info['field_route_id'] = ucwords($info['route_id']);
        }

        // Route Short Name.
        if (isset($info['route_short_name'])) {
          $mapped_info['field_route_short_name'] = $info['route_short_name'];
        }

        // Route Long Name.
        if (isset($info['route_long_name'])) {
          $mapped_info['title'] = $info['route_long_name'];
          $mapped_info['field_route_long_name'] = $info['route_long_name'];
        }

        // Body.
        if (isset($info['route_desc'])) {
          $mapped_info['body'] = $info['route_desc'];
        }

        // Route Type.
        $route_category_mapping = [
          '0' => 'Light Rail',
          '1' => 'Subway/Metro',
          '2' => 'Rail',
          '3' => 'Bus',
          '4' => 'Ferry',
          '5' => 'Cable Car',
          '6' => 'Suspended Cable Car',
          '7' => 'Funicular',
        ];

        if (isset($info['route_type']) && isset($route_category_mapping[$info['route_type']])) {
          $mapped_info['field_route_category'] = $route_category_mapping[$info['route_type']];

          /************************************************************
           * Extended Route Type Mapping
           ************************************************************
           * 110 -- Light Rail            (Replacement Rail Service)
           * 111 -- Levi's Express        (Special Rail Service)
           * 701 -- Express               (Regional Bus Service)
           * 702 -- Frequent              (Express Bus Service)
           * 704 -- Local                 (Local Bus Service)
           * 711 -- Shuttles              (Shuttle Bus)
           * 713 -- School Service        (School and Public Service Bus)
           * 714 -- Bus Bridge            (Rail Replacement Bus Service)
           * 900 -- Light Rail            (Tram Service)
           ************************************************************/
          $extended_route_category_mapping = [
            '110' => 'Light Rail',
            '111' => "Levi's Express",
            '701' => 'Express',
            '702' => 'Frequent',
            '704' => 'Local',
            '711' => 'Shuttles',
            '713' => 'School Service',
            '714' => 'Bus Bridge',
            '900' => 'Light Rail',
          ];

          if (isset($info['ext_route_type']) && isset($extended_route_category_mapping[$info['ext_route_type']])) {
            $mapped_info['field_route_category'] = $extended_route_category_mapping[$info['ext_route_type']];
            $mapped_info['field_extended_route_category'] = $info['ext_route_type'];
          }
        }

        // Route URL.
        if (isset($info['route_url'])) {
          $mapped_info['field_route_url'] = $info['route_url'];
        }

        // Route Color.
        if (isset($info['route_color'])) {
          // Add # prefix to hexcode.
          $mapped_info['field_route_color'] = '#' . strtoupper($info['route_color']);
        }

        // Route Text Color.
        if (isset($info['route_text_color'])) {
          // Add # prefix to hexcode.
          $mapped_info['field_route_text_color'] = '#' . strtoupper($info['route_text_color']);
        }

        // Route Sort Order.
        if (isset($info['route_sort_order'])) {
          $mapped_info['field_route_sort_order'] = $info['route_sort_order'];
        }

        break;

      /************************************************************
       * Stops
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#stopstxt
       ************************************************************
       * Mapping:
       * - stop_id                    -> stop_id
       * - stop_code                  -> stop_code
       * - stop_name                  -> stop_name + (stop_desc)
       * - stop_desc                  -> stop_desc
       * - stop_lat                   -> stop_lat
       * - stop_lon                   -> stop_lon
       * - zone_id                    -> zone_id
       * - stop_url                   -> stop_url
       * - location_type              -> location_type
       * - parent_station             -> parent_station
       * - stop_timezone              -> stop_timezone
       * - wheelchair_boarding        -> wheelchair_boarding
       ************************************************************/
      case 'gtfs_stops':
      case 'gtfs_stops__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'stop_id',
          'stop_code',
          'stop_desc',
          'stop_lat',
          'stop_lon',
          'stop_url',
          'parent_station',
          'stop_timezone',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        /******************************
         * Calculated / Parsed Mapping
         ******************************/
        // Stop Name.
        if (isset($info['stop_name'])) {
          $mapped_info['stop_name'] = $info['stop_name'];

          if (isset($info['stop_desc']) && !empty($info['stop_desc'])) {
            $mapped_info['stop_name'] .= ' (' . substr($info['stop_desc'], 0, 1) . ')';
          }
        }

        // Zone ID.
        if (isset($info['zone_id'])) {
          $mapped_info['zone_id'] = !empty($info['zone_id']) ? $info['zone_id'] : NULL;
        }

        // Location Type.
        $location_type_mapping = [
          '0' => 'stop',
          '1' => 'station',
          '2' => 'station_entrance_exit',
        ];
        if (isset($info['location_type'])) {
          if (empty($info['location_type'])) {
            $mapped_info['location_type'] = $location_type_mapping['0'];
          }
          elseif (isset($location_type_mapping[$info['location_type']])) {
            $mapped_info['location_type'] = $location_type_mapping[$info['location_type']];
          }
        }

        // Wheelchair Boarding.
        $wheelchair_boarding_mapping = [
          '0' => 'no_info',
          '1' => 'yes',
          '2' => 'no',
        ];
        if (isset($info['wheelchair_boarding'])) {
          if (empty($info['wheelchair_boarding'])) {
            $mapped_info['wheelchair_boarding'] = $wheelchair_boarding_mapping['0'];
          }
          elseif (isset($wheelchair_boarding_mapping[$info['wheelchair_boarding']])) {
            $mapped_info['wheelchair_boarding'] = $wheelchair_boarding_mapping[$info['wheelchair_boarding']];
          }
        }

        break;

      /************************************************************
       * Stations
       ************************************************************
       * Mapping:
       * - stop_name                  -> title
       * - stop_id                    -> field_stop_id
       * - stop_desc                  -> body
       * - stop_lat                   -> field_geolocation_single (lat)
       * - stop_lon                   -> field_geolocation_single (lng)
       ************************************************************/
      case 'gtfs_stations':
        // Location Type (1 - Station).
        if (isset($info['location_type']) && $info['location_type'] === '1') {

          // Title.
          if (isset($info['stop_name'])) {
            $mapped_info['title'] = $info['stop_name'];
          }

          // Stop ID.
          if (isset($info['stop_id'])) {
            $mapped_info['field_stop_id'] = $info['stop_id'];
          }

          // Body.
          if (isset($info['stop_desc'])) {
            $mapped_info['body'] = $info['stop_desc'];
          }

          // Location.
          if (isset($info['stop_lat']) && isset($info['stop_lon'])) {
            $mapped_info['field_geofield'] = \Drupal::service('geofield.wkt_generator')->WktBuildPoint([$info['stop_lon'], $info['stop_lat']]);
            $mapped_info['field_geolocation_single'] = [
              'lat' => $info['stop_lat'],
              'lng' => $info['stop_lon'],
            ];
          }
        }

        break;

      /************************************************************
       * Trips
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#tripstxt
       ************************************************************
       * Mapping:
       * - route_id                   -> route_id
       * - service_id                 -> service_id
       * - trip_id                    -> trip_id
       * - trip_headsign              -> trip_headsign
       * - direction_id               -> direction_id
       * - block_id                   -> block_id
       * - shape_id                   -> shape_id
       * - wheelchair_accessible      -> wheelchair_accessible
       * - bikes_allowed              -> bikes_allowed
       ************************************************************/
      case 'gtfs_trips':
      case 'gtfs_trips__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'route_id',
          'service_id',
          'trip_id',
          'trip_headsign',
          'direction_id',
          'block_id',
          'shape_id',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        // Wheelchair Accessible.
        $wheelchair_accessible_mapping = [
          '0' => 'no_info',
          '1' => 'yes',
          '2' => 'no',
        ];
        if (isset($info['wheelchair_accessible'])) {
          if (empty($info['wheelchair_accessible'])) {
            $mapped_info['wheelchair_accessible'] = $wheelchair_accessible_mapping['0'];
          }
          elseif (isset($wheelchair_accessible_mapping[$info['wheelchair_accessible']])) {
            $mapped_info['wheelchair_accessible'] = $wheelchair_accessible_mapping[$info['wheelchair_accessible']];
          }
        }

        // Bikes Allowed.
        $bikes_allowed_mapping = [
          '0' => 'no_info',
          '1' => 'yes',
          '2' => 'no',
        ];
        if (isset($info['bikes_allowed'])) {
          if (empty($info['bikes_allowed'])) {
            $mapped_info['bikes_allowed'] = $bikes_allowed_mapping['0'];
          }
          elseif (isset($bikes_allowed_mapping[$info['bikes_allowed']])) {
            $mapped_info['bikes_allowed'] = $bikes_allowed_mapping[$info['bikes_allowed']];
          }
        }

        break;

      /************************************************************
       * Directions
       ************************************************************
       * Reference:
       * N/A
       ************************************************************
       * Mapping:
       * - route_id                   -> route_id
       * - direction_id               -> direction_id
       * - direction                  -> direction
       * - direction_name             -> direction_name
       ************************************************************/
      case 'gtfs_directions':
      case 'gtfs_directions__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'route_id',
          'direction_id',
          'direction',
          'direction_name',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        break;

      /************************************************************
       * Stop Times
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#stop_timestxt
       ************************************************************
       * Mapping:
       * - trip_id                    -> trip_id
       * - arrival_time               -> arrival_time
       * - departure_time             -> departure_time
       * - stop_id                    -> stop_id
       * - stop_sequence              -> stop_sequence
       * - stop_headsign              -> stop_headsign
       * - pickup_type                -> pickup_type
       * - drop_off_type              -> drop_off_type
       * - shape_dist_traveled        -> shape_dist_traveled
       * - timepoint                  -> timepoint
       ************************************************************/
      case 'gtfs_stop_times':
      case 'gtfs_stop_times__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'trip_id',
          'arrival_time',
          'departure_time',
          'stop_id',
          'stop_sequence',
          'stop_headsign',
          'shape_dist_traveled',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        /******************************
         * Calculated / Parsed Mapping
         ******************************/
        // Pickup Type.
        $pickup_type_mapping = [
          '0' => 'regular',
          '1' => 'none',
          '2' => 'phone',
          '3' => 'driver',
        ];
        if (isset($info['pickup_type']) && isset($pickup_type_mapping[$info['pickup_type']])) {
          $mapped_info['pickup_type'] = $pickup_type_mapping[$info['pickup_type']];
        }

        // Dropoff Type.
        $drop_off_type_mapping = [
          '0' => 'regular',
          '1' => 'none',
          '2' => 'phone',
          '3' => 'driver',
        ];
        if (isset($info['drop_off_type']) && isset($drop_off_type_mapping[$info['drop_off_type']])) {
          $mapped_info['drop_off_type'] = $drop_off_type_mapping[$info['drop_off_type']];
        }

        // Timepoint.
        $timepoint_mapping = [
          '0' => 'approx',
          '1' => 'exact',
        ];
        if (isset($info['timepoint'])) {
          if (empty($info['timepoint'])) {
            $mapped_info['timepoint'] = $timepoint_mapping['1'];
          }
          elseif (isset($timepoint_mapping[$info['timepoint']])) {
            $mapped_info['timepoint'] = $timepoint_mapping[$info['timepoint']];
          }
        }

        break;

      /************************************************************
       * Calendar
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#calendartxt
       ************************************************************
       * Mapping:
       * - service_id                 -> service_id
       * - monday                     -> day_availability
       * - tuesday                    -> day_availability
       * - wednesday                  -> day_availability
       * - thursday                   -> day_availability
       * - friday                     -> day_availability
       * - saturday                   -> day_availability
       * - sunday                     -> day_availability
       * - start_date                 -> start_date
       * - end_date                   -> end_date
       ************************************************************/
      case 'gtfs_calendar':
      case 'gtfs_calendar__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'service_id',
          'start_date',
          'end_date',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        /******************************
         * Calculated / Parsed Mapping
         ******************************/
        // Day Availability.
        if (
          isset($info['monday']) &&
          isset($info['tuesday']) &&
          isset($info['wednesday']) &&
          isset($info['thursday']) &&
          isset($info['friday']) &&
          isset($info['saturday']) &&
          isset($info['sunday'])
        ) {
          $mapped_info['day_availability'] = [
            'monday' => $info['monday'],
            'tuesday' => $info['tuesday'],
            'wednesday' => $info['wednesday'],
            'thursday' => $info['thursday'],
            'friday' => $info['friday'],
            'saturday' => $info['saturday'],
            'sunday' => $info['sunday'],
          ];
          unset($mapped_info['monday']);
          unset($mapped_info['tuesday']);
          unset($mapped_info['wednesday']);
          unset($mapped_info['thursday']);
          unset($mapped_info['friday']);
          unset($mapped_info['saturday']);
          unset($mapped_info['sunday']);
        }
        $mapped_info['day_availability'] = serialize($mapped_info['day_availability']);

        break;

      /************************************************************
       * Calendar Dates
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#calendar_datestxt
       ************************************************************
       * Mapping:
       * - service_id                 -> service_id
       * - date                       -> date
       * - exception_type             -> exception_type
       ***********************************************************/
      case 'gtfs_calendar_dates':
      case 'gtfs_calendar_dates__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'service_id',
          'date',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        /******************************
         * Calculated / Parsed Mapping
         ******************************/
        // Exception Type.
        $exception_type_mapping = [
          '1' => 'added',
          '2' => 'removed',
        ];
        if (isset($info['exception_type']) && isset($exception_type_mapping[$info['exception_type']])) {
          $mapped_info['exception_type'] = $exception_type_mapping[$info['exception_type']];
        }

        break;

      /************************************************************
       * Calendar Attributes
       ************************************************************
       * Reference:
       * N/A
       ************************************************************
       * Mapping:
       * - service_id                 -> service_id
       * - service_description        -> service_description
       ***********************************************************/
      case 'gtfs_calendar_attributes':
      case 'gtfs_calendar_attributes__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'service_id',
          'service_description',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        break;

      /************************************************************
       * Fare Attributes
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#fare_attributestxt
       ************************************************************
       * Remove:
       * - agency_id
       ************************************************************
       * Mapping:
       * - fare_id                    -> fare_id
       * - price                      -> price
       * - currency_type              -> currency_type
       * - payment_method             -> payment_method
       * - transfers                  -> transfers
       * - transfer_duration          -> transfer_duration
       ************************************************************/
      case 'gtfs_fare_attributes':
      case 'gtfs_fare_attributes__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'fare_id',
          'price',
          'currency_type',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        /******************************
         * Calculated / Parsed Mapping
         ******************************/
        // Payment Method.
        $payment_method_mapping = [
          '0' => 'on_board',
          '1' => 'before_board',
        ];
        if (isset($info['payment_method']) && isset($payment_method_mapping[$info['payment_method']])) {
          $mapped_info['payment_method'] = $payment_method_mapping[$info['payment_method']];
        }

        // Transfers.
        $transfers_mapping = [
          '0' => 'none',
          '1' => 'once',
          '2' => 'twice',
        ];
        if (isset($info['transfers'])) {
          if (empty($info['transfers'])) {
            $mapped_info['transfers'] = 'unlimited';
          }
          elseif (isset($transfers_mapping[$info['transfers']])) {
            $mapped_info['transfers'] = $transfers_mapping[$info['transfers']];
          }
        }

        // Transfer Duration.
        if (isset($info['transfer_duration'])) {
          $mapped_info['transfer_duration'] = !empty($info['transfer_duration']) ? $info['transfer_duration'] : NULL;
        }

        break;

      /************************************************************
       * Fare Rules
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#fare_rulestxt
       ************************************************************
       * Mapping:
       * - fare_id                    -> fare_id
       * - route_id                   -> route_id
       * - origin_id                  -> origin_id
       * - destination_id             -> destination_id
       * - contains_id                -> contains_id
       ************************************************************/
      case 'gtfs_fare_rules':
      case 'gtfs_fare_rules__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'fare_id',
          'route_id',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        /******************************
         * Calculated / Parsed Mapping
         ******************************/
        // Origin ID.
        if (isset($info['origin_id'])) {
          $mapped_info['origin_id'] = !empty($info['origin_id']) ? $info['origin_id'] : NULL;
        }

        // Destination ID.
        if (isset($info['destination_id'])) {
          $mapped_info['destination_id'] = !empty($info['destination_id']) ? $info['destination_id'] : NULL;
        }

        // Contains ID.
        if (isset($info['contains_id'])) {
          $mapped_info['contains_id'] = !empty($info['contains_id']) ? $info['contains_id'] : NULL;
        }

        break;

      /************************************************************
       * Shapes
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#shapestxt
       ************************************************************
       * Mapping:
       * - shape_id                   -> shape_id
       * - shape_pt_lat               -> shape_pt_lat
       * - shape_pt_lon               -> shape_pt_lon
       * - shape_pt_sequence          -> shape_pt_sequence
       * - shape_dist_traveled        -> shape_dist_traveled
       ************************************************************/
      case 'gtfs_shapes':
      case 'gtfs_shapes__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'shape_id',
          'shape_pt_lat',
          'shape_pt_lon',
          'shape_pt_sequence',
          'shape_dist_traveled',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        break;

      /************************************************************
       * Frequencies
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#frequenciestxt
       ************************************************************
       * Mapping:
       * - trip_id                    -> trip_id
       * - start_time                 -> start_time
       * - end_time                   -> end_time
       * - headway_secs               -> headway_secs
       * - exact_times                -> exact_times
       ************************************************************/
      case 'gtfs_frequencies':
      case 'gtfs_frequencies__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'trip_id',
          'start_time',
          'end_time',
          'headway_secs',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        /******************************
         * Calculated / Parsed Mapping
         ******************************/
        // Exact Times.
        $exact_times_mapping = [
          '0' => 'none',
          '1' => 'exact',
        ];
        if (isset($info['exact_times'])) {
          if (empty($info['exact_times'])) {
            $mapped_info['exact_times'] = $exact_times_mapping['0'];
          }
          elseif (isset($exact_times_mapping[$info['exact_times']])) {
            $mapped_info['exact_times'] = $exact_times_mapping[$info['exact_times']];
          }
        }

        break;

      /************************************************************
       * Transfers
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#transferstxt
       ************************************************************
       * Mapping:
       * - from_stop_id               -> from_stop_id
       * - to_stop_id                 -> to_stop_id
       * - transfer_type              -> transfer_type
       * - min_transfer_time          -> min_transfer_time
       ************************************************************/
      case 'gtfs_transfers':
      case 'gtfs_transfers__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'from_stop_id',
          'to_stop_id',
          'min_transfer_time',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        /******************************
         * Calculated / Parsed Mapping
         ******************************/
        // Transfer Type.
        $transfer_type_mapping = [
          '0' => 'recommended',
          '1' => 'timed',
          '2' => 'min_timed',
          '3' => 'none',
        ];
        if (isset($info['transfer_type'])) {
          if (empty($info['transfer_type'])) {
            $mapped_info['transfer_type'] = $transfer_type_mapping['0'];
          }
          elseif (isset($transfer_type_mapping[$info['transfer_type']])) {
            $mapped_info['transfer_type'] = $transfer_type_mapping[$info['transfer_type']];
          }
        }

        break;

      /************************************************************
       * Feed Info
       ************************************************************
       * Reference:
       * http://developers.google.com/transit/gtfs/reference/#feed_infotxt
       ************************************************************
       * Mapping:
       * - feed_publisher_name        -> feed_publisher_name
       * - feed_publisher_url         -> feed_publisher_url
       * - feed_lang                  -> feed_lang
       * - default_lang               -> default_lang
       * - feed_start_date            -> feed_start_date
       * - feed_end_date              -> feed_end_date
       * - feed_version               -> feed_version
       * - feed_contact_email         -> feed_contact_email
       * - feed_contact_url           -> feed_contact_url
       ************************************************************/
      case 'gtfs_feed_info':
      case 'gtfs_feed_info__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'feed_publisher_name',
          'feed_publisher_url',
          'feed_lang',
          'default_lang',
          'feed_start_date',
          'feed_end_date',
          'feed_version',
          'feed_contact_email',
          'feed_contact_url',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        break;

      /************************************************************
       * Helper - Master Stop List
       ************************************************************
       * Mapping:
       * - lineabbr                   -> route_id
       * - direction                  -> stop_direction
       * - sequence                   -> stop_sequence
       * - stopid                     -> stop_id
       * - stoptype                   -> stop_type
       * - weekday_timepoint          -> timepoint_availability
       * - saturday_timepoint         -> timepoint_availability
       * - sunday_timepoint           -> timepoint_availability
       ************************************************************/
      case 'vta_gtfs_import_master_stop_list':
      case 'vta_gtfs_import_master_stop_list__upcoming':

        // lineAbbr.
        if (isset($info['lineabbr'])) {
          $mapped_info['route_id'] = $info['lineabbr'];
        }

        // StopId.
        if (isset($info['stopid'])) {
          $mapped_info['stop_id'] = $info['stopid'];
        }

        // Direction.
        if (isset($info['direction'])) {
          $mapped_info['stop_direction'] = $info['direction'][0] . 'B';
        }

        // Sequence.
        if (isset($info['sequence'])) {
          $mapped_info['stop_sequence'] = $info['sequence'];
        }

        // StopType.
        $stop_type_mapping = [
          'S' => 'stop',
          'N' => 'timepoint',
        ];
        if (isset($info['stoptype']) && isset($stop_type_mapping[$info['stoptype']])) {
          $mapped_info['stop_type'] = $stop_type_mapping[$info['stoptype']];
        }

        // Timepoint Availability.
        if (
          isset($info['weekday_timepoint']) &&
          isset($info['saturday_timepoint']) &&
          isset($info['sunday_timepoint'])
        ) {
          $mapped_info['timepoint_availability'] = [
            '1' => $info['weekday_timepoint'] === '1' ? 1 : 0,
            '2' => $info['saturday_timepoint'] === '1' ? 1 : 0,
            '3' => $info['sunday_timepoint'] === '1' ? 1 : 0,
          ];
          $mapped_info['timepoint_availability'] = serialize($mapped_info['timepoint_availability']);
        }

        break;

      /************************************************************
       * Helper - Route Mapping
       ************************************************************
       * Scenario 1 (New route / Route number):
       * - Mapping                    -> [empty], 60
       * Result:
       * - Current                    -> 60
       * - Upcoming                   -> 60
       ************************************************************
       * Scenario 2A (Discontinued Route - Relationship to new route):
       * - Mapping (1)                -> 10, 60
       * - Mapping (2)                -> [empty], 60
       * Result (1):
       * - Current                    -> 10
       * - Upcoming                   -> 60
       * Result (2):
       * - Current                    -> 10
       * - Upcoming                   -> 60
       ************************************************************
       * Scenario 2B (Discontinued Route - Relationship to existing route):
       * - Mapping (1)                -> 10, 60
       * - Mapping (2)                -> 60, 60
       * Result (1):
       * - Current                    -> 10
       * - Upcoming                   -> 60
       * Result (2):
       * - Current                    -> 60
       * - Upcoming                   -> 60
       ************************************************************
       * Scenario 3 (Discontinued route / Route number):
       * - Mapping                    -> 60, [empty]
       * Result:
       * - Current                    -> 60
       * - Upcoming                   -> 60
       ************************************************************
       * Mapping:
       * - old_route_id               -> old_route_id
       * - new_route_id               -> new_route_id
       ************************************************************/
      case 'vta_gtfs_import_route_mapping':
      case 'vta_gtfs_import_route_mapping__upcoming':
        /******************************
         * Direct Mapping
         ******************************/
        $direct_mapping = [
          'old_route_id',
          'new_route_id',
        ];
        foreach ($direct_mapping as $mapping) {
          if (isset($info[$mapping])) {
            $mapped_info[$mapping] = $info[$mapping];
          }
        }

        break;
    }

    return $mapped_info;
  }

}
