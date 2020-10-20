<?php

namespace Drupal\vta_gtfs_import\Services;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\State;
use Drupal\file\Entity\File;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Helper service class for GTFS import.
 */
class VtaGtfsImportService {

  /**
   * The name for the get queue.
   *
   * @var string
   */
  const GET_QUEUE_NAME = 'vta_gtfs_import_get_manual';

  /**
   * The name for the save queue.
   *
   * @var string
   */
  const SAVE_QUEUE_NAME = 'vta_gtfs_import_save_manual';

  /**
   * The directory path for the GTFS import files.
   *
   * @var string
   */
  const IMPORT_DIRECTORY_PATH = 'private://vta_gtfs_import_files/';

  /**
   * The directory path to store the retrieved GTFS import file.
   *
   * @var string
   */
  const RETRIEVE_DIRECTORY_PATH = 'private://vta_gtfs_import_files/upload/';

  /**
   * The directory path for the route schedule PDFs.
   *
   * @var string
   */
  const ROUTE_SCHEDULE_PDF_DIRECTORY_PATH = 'public://route_schedule_pdfs/';

  /**
   * The name of the GTFS import logger.
   *
   * @var string
   */
  const GTFS_IMPORT_LOG = 'vta_gtfs_import_log';

  /**
   * Defines the queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Provides an interface for a queue worker manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueManager;


  /**
   * Provides access to config.
   *
   * @var Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;


  /**
   * Provides an interface for entity type managers.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * Returns the current primary database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $databaseConnection;

  /**
   * Provides helpers to operate on files and stream wrappers.
   *
   * @var \Drupal\Core\File\FileSystem
   */
  protected $fileSystem;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Provides the state system using a key value store.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * Defines a factory for logging channels.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Stores runtime messages sent out to individual users on the page.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    QueueFactory $queue,
    QueueWorkerManagerInterface $queue_manager,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_manager,
    Connection $database_connection,
    FileSystem $file_system,
    DateFormatterInterface $date_formatter,
    State $state,
    LoggerChannelFactoryInterface $logger_factory,
    Messenger $messenger
  ) {
    $this->queueFactory = $queue;
    $this->queueManager = $queue_manager;
    $this->configFactory = $config_factory;
    $this->entityManager = $entity_manager;
    $this->databaseConnection = $database_connection;
    $this->fileSystem = $file_system;
    $this->dateFormatter = $date_formatter;
    $this->state = $state;
    $this->loggerFactory = $logger_factory;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('plugin.manager.queue_worker'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('file_system'),
      $container->get('date.formatter'),
      $container->get('state'),
      $container->get('logger.factory'),
      $container->get('messenger')
    );
  }

  /******************************************************************************************
   * Queue helper functions.
   */

  /**
   * Helper function to clear up queue for the import when run on drush.
   *
   * @param string $queue_name
   *   Name of the queue.
   */
  protected function initQueue($queue_name) {
    $queue = $this->queueFactory->get($queue_name, TRUE);
    $queue->deleteQueue();
  }

  /**
   * Helper function to populate the queue.
   *
   * @param string $queue_name
   *   Name of the queue.
   * @param string $key
   *   Import key.
   * @param string $file_name
   *   Name of the data file.
   * @param string $version
   *   GTFS version (current or upcoming).
   */
  protected function populateQueue($queue_name, $key, $file_name, $version) {
    $queue = $this->queueFactory->get($queue_name, TRUE);
    $data = $this->getFileContents($file_name);
    $item = new \stdClass();
    $item->content = [
      'key' => $key,
      'info' => $data,
      'version' => $version,
    ];

    $queue->createItem($item);
  }

  /**
   * Helper function to get contents of the file.
   *
   * @param string $file_name
   *   Name of the data file.
   *
   * @return string|false
   *   File contents or false.
   */
  protected function getFileContents($file_name) {
    $full_file_path = VtaGtfsImportService::IMPORT_DIRECTORY_PATH . $file_name;

    if (file_exists($full_file_path)) {
      $data = file_get_contents($full_file_path);
    }
    else {
      $data = FALSE;
    }

    return $data;
  }

  /**
   * Helper function to process queue item.
   *
   * @param string $queue_name
   *   Name of the queue.
   * @param object $item
   *   Current queue item.
   */
  protected function processQueueItem($queue_name, $item) {
    $queue = $this->queueFactory->get($queue_name, TRUE);
    $queue_worker = $this->queueManager->createInstance($queue_name);

    if (isset($item)) {
      try {
        $queue_worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
      }
      catch (\Throwable $t) {
        watchdog_exception('vta_gtfs_import', $t);
      }
      catch (\Exception $e) {
        watchdog_exception('vta_gtfs_import', $e);
      }
    }
  }

  /******************************************************************************************
   * GTFS Import main helper functions.
   */

  /**
   * Helper function to increase PHP memory limit to 1GB.
   *
   * @param bool $is_drush
   *   Flag to determine if origin is Drush.
   */
  protected function increasePhpMemoryLimit($is_drush) {
    ini_set('memory_limit', '1024M');
    $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
    $this->sendDrushLog('Adjusting PHP Memory Limit to ' . ini_get('memory_limit'), 'success', $is_drush);
    $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
  }

  /**
   * Helper function to retrieve GTFS import files.
   *
   * Logging Status: Not Done.
   *
   * @param bool $is_drush
   *   Flag to determine if Drush log must be sent.
   */
  public function retrieve($is_drush = FALSE) {
    $config = $this->configFactory->get('vta_gtfs_import.settings');
    $gtfs_file_url = $config->get('gtfs_file_url');

    $this->sendDrushLog('Retrieving GTFS Files', 'success', $is_drush);
    $this->sendDrushLog('------------------', 'success', $is_drush);

    if (!empty($gtfs_file_url)) {
      $retrieve_time = $this->dateFormatter->format(time(), 'custom', 'm-d-Y_g-i-A');
      $destination_filename = 'gtfs_import__' . $retrieve_time . '.zip';
      $destination_path = VtaGtfsImportService::RETRIEVE_DIRECTORY_PATH . $destination_filename;

      $this->sendDrushLog('-- GTFS file - ' . $destination_filename, 'success', $is_drush);

      $this->sendDrushLog('-- Attempting to retrieve', 'success', $is_drush);

      $file_put_contents_result = file_put_contents($destination_path, fopen($gtfs_file_url, 'r'));

      if ($file_put_contents_result) {
        $this->sendDrushLog('-- Successfully retrieved', 'success', $is_drush);

        $file = File::create([
          'uid' => 1,
          'filename' => $destination_filename,
          'uri' => $destination_path,
          'status' => 1,
        ]);

        $this->retrieveFiles($file, 'gtfs_files', 'current');
      }
    }

    $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
    $this->logMessage($is_drush);
  }

  /**
   * Helper function to prepare GTFS import.
   *
   * Logging Status: Done.
   *
   * @param bool $is_drush
   *   Flag to determine if Drush log must be sent.
   */
  public function prepare($is_drush = FALSE) {
    try {
      $this->sendDrushLog('------------------', 'success', $is_drush);
      $this->sendDrushLog('Prepare - GTFS schedule switch over check', 'success', $is_drush);
      $this->sendDrushLog('------------------', 'success', $is_drush);

      /******************************
       * Check for GTFS switchover
       * - Upcoming > Feed Info > Start Date
       * -- Exists
       * -- In the past
       ******************************/
      $table_key = 'gtfs_feed_info__upcoming';
      $query = $this->databaseConnection->select($table_key, 'gfi');
      $query->fields('gfi', [
        'feed_start_date',
      ]);
      $results = $query->execute()->fetchAll();

      $upcoming_start_date = '';
      if (!empty($results)) {
        $upcoming_start_date = $results[0]->feed_start_date;
      }

      if (
        !empty($upcoming_start_date) &&
        strtotime($upcoming_start_date) < strtotime('now')
      ) {
        $this->sendDrushLog('-- Ready for switch over', 'success', $is_drush);
        $this->sendDrushLog('--------------------------------------', 'success', $is_drush);

        /******************************
         * Copying / Removing Route Maps and Turn by Turn Directions
         * - Upcoming -> Current
         ******************************/
        try {
          $this->sendDrushLog('------------------', 'success', $is_drush);
          $this->sendDrushLog('Copying / Removing Route Maps and Turn by Turn Directions', 'success', $is_drush);
          $this->sendDrushLog('------------------', 'success', $is_drush);

          $routes = $this->entityManager->getStorage('node')->loadByProperties([
            'type' => 'route',
          ]);
          $route_update_count = 0;

          if (!empty($routes)) {

            foreach ($routes as $route) {
              $route_updated = FALSE;

              /******************************
               * Maps
               ******************************/
              if (
                $route->hasField('field_map') &&
                $route->hasField('field_up_map') &&
                !($route->get('field_up_map')->isEmpty())
              ) {
                $upcoming_map_target_id = $route->get('field_up_map')->getValue()[0]['target_id'];
                $route->get('field_map')->setValue($upcoming_map_target_id);
                $route->get('field_up_map')->setValue(NULL);
                $route_updated = TRUE;
              }

              /******************************
               * Turn by Turn Directions
               ******************************/
              if (
                $route->hasField('field_turn_by_turn_directions') &&
                $route->hasField('field_up_turn_by_turn_directions') &&
                !empty($route->get('field_up_turn_by_turn_directions')->getValue()[0]['value'])
              ) {
                $upcoming_turn_by_turn_directions = [
                  $route->get('field_up_turn_by_turn_directions')->getValue()[0]['value'],
                  $route->get('field_up_turn_by_turn_directions')->getValue()[1]['value'],
                ];
                $route->get('field_turn_by_turn_directions')->setValue($upcoming_turn_by_turn_directions);
                $route->get('field_up_turn_by_turn_directions')->setValue(NULL);
                $route_updated = TRUE;
              }

              if ($route_updated) {
                $route->save();
                $route_update_count++;
              }
            }
          }
          $this->sendDrushLog('Routes updated: ' . $route_update_count, 'success', $is_drush);
        }
        catch (\Exception $e) {
          throw $e;
        }
        finally {
          /******************************
           * Validate
           * - Expected Result:
           * -- Empty Route Upcoming Map and Turn by Turn Directions
           ******************************/
          $validation_result = TRUE;

          $routes = $this->entityManager->getStorage('node')->loadByProperties([
            'type' => 'route',
          ]);

          if (!empty($routes)) {
            foreach ($routes as $route) {
              if (
                (
                  $route->hasField('field_up_map') &&
                  !($route->get('field_up_map')->isEmpty())
                ) || (
                  $route->hasField('field_up_turn_by_turn_directions') &&
                  !empty($route->get('field_up_turn_by_turn_directions')->getValue()[0]['value'])
                )
              ) {
                $validation_result = FALSE;
              }
            }
          }

          /******************************
           * Logging
           ******************************/
          $logging_info = [
            'step' => 'Prepare - Copying / Removing Route Maps and Turn by Turn Directions',
            'result' => $validation_result ? 'passed' : 'failed',
            'summary' => $validation_result ? 'Successfully copied / removed Route Maps and Turn by Turn Directions.' : 'Route Maps and Turn by Turn Directions not fully copied / removed.',
          ];
          $this->logMessage($is_drush, $logging_info);
        }

        /******************************
         * Remove all Route Schedule PDFs
         ******************************/
        try {
          $this->sendDrushLog('------------------', 'success', $is_drush);
          $this->sendDrushLog('Removing outdated Route Schedule PDFs', 'success', $is_drush);
          $this->sendDrushLog('------------------', 'success', $is_drush);

          $route_schedule_pdf_versions = [
            'current',
            'upcoming',
          ];

          foreach ($route_schedule_pdf_versions as $route_schedule_pdf_version) {
            $this->sendDrushLog(ucwords($route_schedule_pdf_version), 'success', $is_drush);

            $directory = VtaGtfsImportService::ROUTE_SCHEDULE_PDF_DIRECTORY_PATH . $route_schedule_pdf_version . '/';
            $it = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

            $remove_directory_count = 0;
            $remove_file_count = 0;
            foreach ($files as $file) {
              if ($file->isDir()) {
                rmdir($file);
                $remove_directory_count++;
              }
              else {
                unlink($file);
                $remove_file_count++;
              }
            }
            $this->sendDrushLog('-- Directories removed: ' . $remove_directory_count, 'success', $is_drush);
            $this->sendDrushLog('-- Files removed: ' . $remove_file_count, 'success', $is_drush);
            if ($route_schedule_pdf_versions === 'current') {
              $this->sendDrushLog('------------------', 'success', $is_drush);
            }
          }
          $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
        }
        catch (\Exception $e) {
          throw $e;
        }
        finally {
          /******************************
           * Validate
           * - Expected Result:
           * -- Empty Route Schedule PDF directories
           ******************************/
          $validation_result = TRUE;

          foreach ($route_schedule_pdf_versions as $route_schedule_pdf_version) {
            $directory = VtaGtfsImportService::ROUTE_SCHEDULE_PDF_DIRECTORY_PATH . $route_schedule_pdf_version;

            if ((new \FilesystemIterator($directory))->valid()) {
              $validation_result = FALSE;
            }
          }

          /******************************
           * Logging
           ******************************/
          $logging_info = [
            'step' => 'Prepare - Remove Route Schedule PDFs',
            'result' => $validation_result ? 'passed' : 'failed',
            'summary' => $validation_result ? 'Successfully removed Route Scheduled PDFs.' : 'Route Scheduled PDFs not fully removed.',
          ];
          $this->logMessage($is_drush, $logging_info);
        }

        /******************************
         * Copying / Removing GTFS files
         *
         * Mapping:
         * - current_previous   -> (removed)
         * - current            -> current_previous
         * - upcoming           -> current
         * - upcoming_next      -> upcoming
         ******************************/
        try {
          $this->sendDrushLog('------------------', 'success', $is_drush);
          $this->sendDrushLog('Copying / Removing GTFS files', 'success', $is_drush);
          $this->sendDrushLog('------------------', 'success', $is_drush);

          $gtfs_import_files_directory = VtaGtfsImportService::IMPORT_DIRECTORY_PATH;
          $current_previous_directory = $gtfs_import_files_directory . 'current_previous/';
          $current_directory = $gtfs_import_files_directory . 'current/';
          $upcoming_directory = $gtfs_import_files_directory . 'upcoming/';
          $upcoming_next_directory = $gtfs_import_files_directory . 'upcoming_next/';

          $gtfs_import_files_mappings = [
            'current_previous' => [
              'source' => $current_previous_directory,
              'destination' => '',
              'action' => 'remove',
            ],
            'current' => [
              'source' => $current_directory,
              'destination' => $current_previous_directory,
              'action' => 'copy',
            ],
            'upcoming' => [
              'source' => $upcoming_directory,
              'destination' => $current_directory,
              'action' => 'copy',
            ],
            'upcoming_next' => [
              'source' => $upcoming_next_directory,
              'destination' => $upcoming_directory,
              'action' => 'copy',
            ],
          ];

          foreach ($gtfs_import_files_mappings as $mapping_key => $gtfs_import_files_mapping) {
            $mapping_key = ucwords(str_replace('_', ' ', $mapping_key));

            // Main GTFS files.
            $this->sendDrushLog($mapping_key, 'success', $is_drush);
            $this->prepareFiles($gtfs_import_files_mapping, $is_drush);

            // Helper GTFS files.
            $this->sendDrushLog($mapping_key . ' - Helper', 'success', $is_drush);
            $gtfs_import_files_mapping['source'] .= '/helper/';
            $gtfs_import_files_mapping['destination'] .= '/helper/';
            $this->prepareFiles($gtfs_import_files_mapping, $is_drush);

            $this->sendDrushLog('-------------------', 'success', $is_drush);
          }
        }
        catch (\Exception $e) {
          throw $e;
        }
        finally {
          /******************************
           * Validate
           * - Expected Result:
           * -- Directory is EMPTY     -- TRUE
           * -- Directory is NOT EMPTY -- FALSE
           ******************************/
          $validation_result = TRUE;
          $gtfs_import_files_expected_results = [
            'current_prevous' => FALSE,
            'current' => FALSE,
            'upcoming' => TRUE,
            'upcoming_next' => TRUE,
          ];

          foreach ($gtfs_import_files_mappings as $mapping_key => $gtfs_import_files_mapping) {
            $files = scandir($gtfs_import_files_mapping['source']);

            foreach ($files as $file) {
              // Check to see if there is a valid file.
              if (!in_array($file, ['.', '..', 'helper']) && substr($file, 0, 1) != '.') {
                // Check if the directory should be empty.
                if ($gtfs_import_files_expected_results[$mapping_key]) {
                  $validation_result = FALSE;
                }
                break;
              }
            }
          }

          /******************************
           * Logging
           ******************************/
          $logging_info = [
            'step' => 'Prepare - Copying / Removing GTFS files',
            'result' => $validation_result ? 'passed' : 'failed',
            'summary' => $validation_result ? 'Successfully copied / removed GTFS files.' : 'GTFS files not fully copied / removed.',
          ];
          $this->logMessage($is_drush, $logging_info);
        }
      }
      else {
        $this->sendDrushLog('-- Not ready for switch over', 'success', $is_drush);
      }
      $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
    }
    catch (\Exception $e) {
      throw $e;
    }
  }

  /**
   * Helper function to clean GTFS import queues.
   *
   * Logging Status: Done.
   *
   * @param bool $is_drush
   *   Flag to determine if Drush log must be sent.
   */
  public function clean($is_drush = FALSE) {
    try {
      $queues = [VtaGtfsImportService::GET_QUEUE_NAME, VtaGtfsImportService::SAVE_QUEUE_NAME];

      $this->sendDrushLog('------------------', 'success', $is_drush);
      $this->sendDrushLog('Clearing queues', 'success', $is_drush);
      $this->sendDrushLog('------------------', 'success', $is_drush);

      foreach ($queues as $queue) {
        $this->sendDrushLog('-- ' . $queue, 'success', $is_drush);
        $this->initQueue($queue);
      }

      $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
    }
    catch (\Exception $e) {
      throw $e;
    }
    finally {
      /******************************
       * Validate
       * - Expected Result:
       * -- 0
       ******************************/
      $validation_result = FALSE;

      $query = $this->databaseConnection->select('queue', 'q');
      $query->addExpression('COUNT(q.name)', 'count');
      $query->condition('q.name', $queues, 'IN');
      $results = $query->execute()->fetchAll();
      if (!empty($results)) {
        $validation_result = intval($results[0]->count) === 0 ? TRUE : FALSE;
      }

      /******************************
       * Logging
       ******************************/
      $logging_info = [
        'step' => 'Clean',
        'result' => $validation_result ? 'passed' : 'failed',
        'summary' => $validation_result ? 'Successfully cleared queues.' : 'Queues not fully cleared.',
      ];
      $this->logMessage($is_drush, $logging_info);
    }
  }

  /**
   * Helper function to pupulate GTFS import queue.
   *
   * Logging Status: Done.
   *
   * @param bool $is_drush
   *   Flag to determine if Drush log must be sent.
   */
  public function populate($is_drush = FALSE) {
    try {
      $this->increasePhpMemoryLimit($is_drush);

      $this->sendDrushLog('------------------', 'success', $is_drush);
      $this->sendDrushLog('Populating queues', 'success', $is_drush);
      $this->sendDrushLog('------------------', 'success', $is_drush);

      /**************************************************
       * Import files:                      Desintation:
       * - Routes                           (entity)
       * - Stops                            (table)
       * - Stations                         (entity)
       * - Trips                            (table)
       * - Directions                       (table)
       * - Stop Times                       (table)
       * - Calendar                         (table)
       * - Calendar Dates                   (table)
       * - Calendar Attributes              (table)
       * - Fare Attributes                  (table)
       * - Fare Rules                       (table)
       * - Shapes                           (table)
       * - Frequencies                      (table)
       * - Transfers                        (table)
       * - Feed Info                        (table)
       * - Helper - Master Stop List        (table)
       * - Helper - Route Mapping           (table)
       **************************************************/
      $imports = [
        'Routes' => [
          'file_name' => 'routes.txt',
          'key' => 'gtfs_routes',
          'destination' => 'entity',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Stops' => [
          'file_name' => 'stops.txt',
          'key' => 'gtfs_stops',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Stations' => [
          'file_name' => 'stops.txt',
          'key' => 'gtfs_stations',
          'destination' => 'entity',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Trips' => [
          'file_name' => 'trips.txt',
          'key' => 'gtfs_trips',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Directions' => [
          'file_name' => 'directions.txt',
          'key' => 'gtfs_directions',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Stop Times' => [
          'file_name' => 'stop_times.txt',
          'key' => 'gtfs_stop_times',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Calendar' => [
          'file_name' => 'calendar.txt',
          'key' => 'gtfs_calendar',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Calendar Dates' => [
          'file_name' => 'calendar_dates.txt',
          'key' => 'gtfs_calendar_dates',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Calendar Attributes' => [
          'file_name' => 'calendar_attributes.txt',
          'key' => 'gtfs_calendar_attributes',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Fare Attributes' => [
          'file_name' => 'fare_attributes.txt',
          'key' => 'gtfs_fare_attributes',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Fare Rules' => [
          'file_name' => 'fare_rules.txt',
          'key' => 'gtfs_fare_rules',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Shapes' => [
          'file_name' => 'shapes.txt',
          'key' => 'gtfs_shapes',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Frequencies' => [
          'file_name' => 'frequencies.txt',
          'key' => 'gtfs_frequencies',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Transfers' => [
          'file_name' => 'transfers.txt',
          'key' => 'gtfs_transfers',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Feed Info' => [
          'file_name' => 'feed_info.txt',
          'key' => 'gtfs_feed_info',
          'destination' => 'table',
          'type' => 'gtfs',
          'skip' => FALSE,
        ],
        'Helper - Master Stop List' => [
          'file_name' => 'master_stop_list.csv',
          'key' => 'vta_gtfs_import_master_stop_list',
          'destination' => 'table',
          'type' => 'helper',
          'skip' => FALSE,
        ],
        'Helper - Route Mapping' => [
          'file_name' => 'route_mapping.csv',
          'key' => 'vta_gtfs_import_route_mapping',
          'destination' => 'table',
          'type' => 'helper',
          'skip' => FALSE,
        ],
      ];
      $versions = [
        'current',
        'upcoming',
      ];

      foreach ($imports as $key => $import) {
        if (!$import['skip']) {
          foreach ($versions as $version) {
            $modified_import_key = $import['key'];
            if ($version == 'upcoming') {
              $key .= ' (' . ucfirst($version) . ')';
              $modified_import_key .= '__upcoming';
            }
            $this->sendDrushLog($key, 'success', $is_drush);

            /******************************
             * Truncate Database Table
             ******************************/
            if ($import['destination'] == 'table') {
              $this->sendDrushLog('-- Truncating', 'success', $is_drush);
              $this->databaseConnection->truncate($modified_import_key)->execute();
            }

            /******************************
             * Populate Queue
             ******************************/
            // Generate modified file name to include version folder.
            $modified_file_name = $version . '/' . $import['file_name'];
            // Use the version folder and type folder for helper files.
            if ($import['type'] == 'helper') {
              $modified_file_name = $version . '/' . $import['type'] . '/' . $import['file_name'];
            }
            $this->sendDrushLog('-- Populating', 'success', $is_drush);

            if ($import['destination'] == 'table') {
              $this->populateQueue(VtaGtfsImportService::GET_QUEUE_NAME, $modified_import_key, $modified_file_name, $version);
            }
            else {
              $this->populateQueue(VtaGtfsImportService::GET_QUEUE_NAME, $import['key'], $modified_file_name, $version);
            }
          }
          $this->sendDrushLog('------------------', 'success', $is_drush);
        }
      }
      $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
    }
    catch (\Exception $e) {
      throw $e;
    }
    finally {
      /******************************
       * Validate
       * - Expected Result:
       * -- $logging_expected_result
       ******************************/
      $validation_result = FALSE;
      $logging_expected_result = count($imports) * count($versions);

      $query = $this->databaseConnection->select('queue', 'q');
      $query->addExpression('COUNT(q.name)', 'count');
      $query->condition('q.name', VtaGtfsImportService::GET_QUEUE_NAME);
      $results = $query->execute()->fetchAll();
      if (!empty($results)) {
        $validation_result = intval($results[0]->count) === $logging_expected_result ? TRUE : FALSE;
      }

      /******************************
       * Logging
       ******************************/
      $logging_info = [
        'step' => 'Populate',
        'result' => $validation_result ? 'passed' : 'failed',
        'summary' => $validation_result ? 'Successfully populated queues.' : 'Queues not fully populated.',
      ];
      $this->logMessage($is_drush, $logging_info);
    }
  }

  /**
   * Helper function to process GTFS import queue items.
   *
   * Logging Status: Done.
   *
   * @param string $queue_name
   *   Name of queue to be processed.
   * @param bool $is_drush
   *   Flag to determine if Drush log must be sent.
   */
  public function process($queue_name, $is_drush = FALSE) {
    try {
      $this->increasePhpMemoryLimit($is_drush);

      // Get queue to process.
      switch ($queue_name) {
        case VtaGtfsImportService::GET_QUEUE_NAME:
          $action = 'get';
          break;

        case VtaGtfsImportService::SAVE_QUEUE_NAME:
          $action = 'save';
          break;
      }
      $queue = $this->queueFactory->get($queue_name, TRUE);

      $this->sendDrushLog('------------------', 'success', $is_drush);
      $this->sendDrushLog('Processing ' . strtoupper($action) . ' queues', 'success', $is_drush);
      $this->sendDrushLog('------------------', 'success', $is_drush);

      $i = 0;
      while ($item = $queue->claimItem()) {
        $key = '';

        switch ($action) {
          case 'get':
            $key = $item->data->content['key'];
            break;

          case 'save':
            $key = $item->data->key;
            break;
        }
        $this->sendDrushLog('Processing ' . $action . ' queue for ' . $key . ' - ' . $i, 'success', $is_drush);
        $this->processQueueItem($queue_name, $item);
        $i++;
      }

      $this->sendDrushLog('------------------', 'success', $is_drush);
      $this->sendDrushLog('Processed ' . $i . ' items.', 'success', $is_drush);
      $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
    }
    catch (\Exception $e) {
      throw $e;
    }
    finally {
      /******************************
       * Validate
       * - Expected Result:
       * -- 0
       ******************************/
      $validation_result = FALSE;

      $query = $this->databaseConnection->select('queue', 'q');
      $query->addExpression('COUNT(q.name)', 'count');
      $query->condition('q.name', $queue_name);
      $results = $query->execute()->fetchAll();
      if (!empty($results)) {
        $validation_result = intval($results[0]->count) === 0 ? TRUE : FALSE;
      }

      /******************************
       * Logging
       ******************************/
      $logging_info = [
        'step' => 'Process - ' . strtoupper($action),
        'result' => $validation_result ? 'passed' : 'failed',
        'summary' => $validation_result ? 'Successfully processed ' . strtoupper($action) . ' queue items.' : strtoupper($action) . ' queue items not fully processed.',
      ];
      $this->logMessage($is_drush, $logging_info);
    }
  }

  /**
   * Helper function to check the import tracking.
   *
   * Logging Status: Not Done.
   *
   * @param bool $is_drush
   *   Flag to determine if Drush log must be sent.
   */
  public function check($is_drush = FALSE) {
    $this->increasePhpMemoryLimit($is_drush);

    $this->sendDrushLog('Checking Import Tracking', 'success', $is_drush);
    $this->sendDrushLog('-------------------', 'success', $is_drush);

    /******************************
     * Query 1:
     * - Route Tracking
     ******************************/
    $this->sendDrushLog('Running Query 1: Route Tracking', 'success', $is_drush);
    // Select all rows from Route Tracking table.
    $query = $this->databaseConnection->select('vta_gtfs_import_route_tracking', 'vgirt');
    $query->fields('vgirt', ['id', 'file', 'last_updated']);
    $query->fields('rid', ['entity_id']);
    $query->leftJoin('node__field_route_id', 'rid', 'vgirt.id = rid.field_route_id_value');
    $tracking_result = $query->execute()->fetchAll();

    /******************************
     * Query 2:
     * - Routes
     ******************************/
    $this->sendDrushLog('Running Query 2: Routes - All', 'success', $is_drush);
    // Select all rows from Upcoming Routes All table.
    $query = $this->databaseConnection->select('vta_routes_all__upcoming', 'vrau');
    $query->fields('vrau', ['id', 'data']);
    $upcoming_result = $query->execute()->fetchAll();

    // Build array with Upcoming Routes data.
    $upcoming_route_info = [];
    if (!empty($upcoming_result) && is_array($upcoming_result)) {
      foreach ($upcoming_result as $upcoming) {
        if (!empty($upcoming) && is_object($upcoming) && isset($upcoming->id) && isset($upcoming->data)) {
          $upcoming_route_info[$upcoming->id] = unserialize($upcoming->data);
        }
      }
    }
    $this->sendDrushLog('-------------------', 'success', $is_drush);

    $this->sendDrushLog('Updating: Route Status', 'success', $is_drush);
    if (!empty($tracking_result)) {
      foreach ($tracking_result as $res) {
        // Check if row has an existing Route node.
        if (!empty($res->entity_id)) {
          $updated_values = [];
          // Check if route is present in current and/or upcoming files.
          switch ($res->file) {
            case 'current':
              // If it gets here, current route is found only in file 'current'.
              // If route was last updated longer than 24 hours ago,
              // set its status as 'inactive' and unpublish the node.
              if ($res->last_updated < strtotime('24 hours ago')) {
                $updated_values['field_route_status'] = 'inactive';
                $updated_values['status'] = 0;
              }
              // If route's last update is newer than 24 hours ago,
              // set its status as 'discontinued' and keep it published.
              else {
                $updated_values['field_route_status'] = 'discontinued';
                $updated_values['status'] = 1;
              }
              break;

            case 'upcoming':
              // If it gets here, current route is found in file 'upcoming',
              // and may be present in file 'current' as well.
              // Check if route is found in Upcoming Routes data.
              if (isset($upcoming_route_info[$res->id]) && !empty($upcoming_route_info[$res->id])) {
                // Get schedule status for current route.
                $route_status = $upcoming_route_info[$res->id]['schedule_status'];
                if (!empty($route_status) && is_array($route_status) && isset($route_status['current']) && isset($route_status['upcoming'])) {
                  // If route is not found in file 'current' and
                  // exists in file 'upcoming', set its status as 'new'.
                  if (empty($route_status['current']) && !empty($route_status['upcoming'])) {
                    $updated_values['field_route_status'] = 'new';
                    $updated_values['status'] = 1;
                  }
                  // If route exists in both files 'current' and 'upcoming',
                  // set its status as 'active'.
                  elseif (!empty($route_status['current']) && !empty($route_status['upcoming'])) {
                    $updated_values['field_route_status'] = 'active';
                    $updated_values['status'] = 1;
                  }
                }
              }
              break;
          }
          if (!empty($updated_values) && is_array($updated_values)) {
            $this->sendDrushLog('Route > ' . $res->id . ' -- ' . ucwords($updated_values['field_route_status']), 'success', $is_drush);
            // Update the node.
            $entity = $this->entityManager->getStorage('node')->load($res->entity_id);
            if (!empty($entity) && $entity instanceof NodeInterface) {
              foreach ($updated_values as $key => $value) {
                if ($entity->hasField($key)) {
                  $entity->$key->setValue($value);
                }
              }
              $entity->save();
            }
          }
        }
      }
    }

    /******************************
     * TODO ***********************
     ******************************
     * Query 2:
     * - Stations
     ******************************/

    $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
    $this->logMessage($is_drush);
  }

  /**
   * Helper function to generate refined route data.
   *
   * Logging Status: Done.
   *
   * @param bool $is_drush
   *   Flag to determine if Drush log must be sent.
   */
  public function generate($is_drush = FALSE) {
    try {
      $this->increasePhpMemoryLimit($is_drush);

      $versions = [
        'current',
        'upcoming',
      ];
      foreach ($versions as $version) {
        try {
          $database_table_version_suffix = ($version == 'upcoming') ? '__' . $version : '';
          $skip_query_and_insert = FALSE;

          $drush_log = 'Create Refined Route Data' . (($version == 'upcoming') ? ' (' . ucfirst($version) . ')' : '');
          $this->sendDrushLog($drush_log, 'success', $is_drush);
          $this->sendDrushLog('-------------------', 'success', $is_drush);

          /******************************
           * Query 1:
           * - Helper - Route Mapping
           ******************************/
          $this->sendDrushLog('Running Query 1: Helper - Route Mapping', 'success', $is_drush);
          $table_key = 'vta_gtfs_import_route_mapping' . $database_table_version_suffix;
          $query = $this->databaseConnection->select($table_key, 'vgirm');
          $query->fields('vgirm', [
            'old_route_id',
            'new_route_id',
          ]);
          $results = $query->execute()->fetchAll();

          $schedule_status = [];
          $route_mapping = [];
          if (!empty($results)) {
            foreach ($results as $res) {
              if (!empty($res->old_route_id)) {
                $schedule_status[$res->old_route_id]['current'] = TRUE;
              }
              if (!empty($res->new_route_id)) {
                $schedule_status[$res->new_route_id]['upcoming'] = TRUE;
              }

              if (!empty($res->old_route_id) && !empty($res->new_route_id)) {
                // Map old to new. (Old).
                $route_mapping['old_to_new'][$res->old_route_id][] = $res->new_route_id;
                // Map new to old. (Current).
                $route_mapping['new_to_old'][$res->new_route_id][] = $res->old_route_id;
              }
            }
          }
          else {
            $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
            $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
            $skip_query_and_insert = TRUE;
          }

          /******************************
           * Query 2:
           * - Trip List
           * - Stop List
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 2: Trip List, Stop List', 'success', $is_drush);
            $table_key = 'gtfs_stop_times' . $database_table_version_suffix;
            $query = $this->databaseConnection->select($table_key, 'gst');
            $query->fields('gst', [
              'trip_id',
              'arrival_time',
              'stop_id',
            ]);
            $query->orderBy('trip_id');
            $query->orderBy('stop_sequence');
            $results = $query->execute()->fetchAll();

            $trip_list = [];
            $stop_list = [];
            if (!empty($results)) {
              foreach ($results as $res) {
                $trip_list[$res->trip_id][$res->stop_id] = $res->arrival_time;
                $stop_list[$res->stop_id] = $res->stop_id;
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }

          /******************************
           * Query 3:
           * - Helper - Master Stop List
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 3: Helper - Master Stop List', 'success', $is_drush);
            $table_key = 'vta_gtfs_import_master_stop_list' . $database_table_version_suffix;
            $query = $this->databaseConnection->select($table_key, 'vgimsl');
            $query->fields('vgimsl', [
              'route_id',
              'stop_id',
              'stop_direction',
              'stop_sequence',
              'stop_type',
              'timepoint_availability',
            ]);
            $query->condition('vgimsl.stop_id', $stop_list, 'IN');
            $results = $query->execute()->fetchAll();

            $master_stop_list = [];
            if (!empty($results)) {
              foreach ($results as $res) {
                $master_stop_list[$res->route_id][$res->stop_direction][$res->stop_sequence] = [
                  'stop_id' => $res->stop_id,
                  'stop_sequence' => $res->stop_sequence,
                  'stop_type' => $res->stop_type,
                  'timepoint_availability' => unserialize($res->timepoint_availability),
                ];
              }
              // Sort by stop sequence.
              foreach ($master_stop_list as $route_id => $route) {
                foreach ($route as $direction_id => $direction) {
                  ksort($master_stop_list[$route_id][$direction_id]);
                }
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }

          /******************************
           * Query 4:
           * - Stop List
           * - Stop Code List
           * - Stop Coordinate List
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 4: Stop List, Stop Code List, Stop Coordinate List', 'success', $is_drush);
            $table_key = 'gtfs_stops' . $database_table_version_suffix;
            $query = $this->databaseConnection->select($table_key, 'gs');
            $query->fields('gs', [
              'stop_id',
              'stop_name',
              'stop_lat',
              'stop_lon',
            ]);
            $query->condition('gs.stop_id', $stop_list, 'IN');
            $results = $query->execute()->fetchAll();

            $stop_name_list = [];
            $stop_coordinate_list = [];
            if (!empty($results)) {
              foreach ($results as $res) {
                $stop_name_list[$res->stop_id] = $res->stop_name;
                $stop_coordinate_list[$res->stop_id] = [
                  'lat' => (double) $res->stop_lat,
                  'lng' => (double) $res->stop_lon,
                ];
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }

          /******************************
           * Query 5:
           * - Effective Dates
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 5: Effective Dates', 'success', $is_drush);
            $table_key = 'gtfs_feed_info' . $database_table_version_suffix;
            $query = $this->databaseConnection->select($table_key, 'gfi');
            $query->fields('gfi', [
              'feed_start_date',
              'feed_end_date',
            ]);
            $results = $query->execute()->fetchAll();

            $effective_dates = [];
            if (!empty($results)) {
              foreach ($results as $res) {
                $effective_dates = [
                  'start_date' => strtotime($res->feed_start_date),
                  'end_date' => strtotime($res->feed_end_date),
                ];
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }

          /******************************
           * Query 6:
           * - Routes - Basic
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 6: Routes - Basic', 'success', $is_drush);
            $query = $this->databaseConnection->select('node', 'n');
            $query->fields('nfd', ['title']);
            $query->fields('rid', ['field_route_id_value']);
            $query->fields('rsn', ['field_route_short_name_value']);
            $query->fields('rc', ['field_route_color_value']);
            $query->fields('rtc', ['field_route_text_color_value']);
            $query->fields('ttfd', ['name']);
            $query->leftJoin('node_field_data', 'nfd', 'nfd.nid = n.nid');
            $query->leftJoin('node__field_route_id', 'rid', 'n.nid = rid.entity_id');
            $query->leftJoin('node__field_route_short_name', 'rsn', 'n.nid = rsn.entity_id');
            $query->leftJoin('node__field_route_color', 'rc', 'n.nid = rc.entity_id');
            $query->leftJoin('node__field_route_text_color', 'rtc', 'n.nid = rtc.entity_id');
            $query->leftJoin('node__field_route_category', 'rcat', 'n.nid = rcat.entity_id');
            $query->leftJoin('taxonomy_term_field_data', 'ttfd', 'rcat.field_route_category_target_id = ttfd.tid');
            $query->condition('rid.bundle', 'route');
            $query->condition('nfd.status', 1);
            $results = $query->execute()->fetchAll();

            $routes = [];
            if (!empty($results)) {
              foreach ($results as $res) {
                $routes[$res->field_route_id_value]['route_id'] = $res->field_route_id_value;
                $routes[$res->field_route_id_value]['route_name'] = $res->field_route_short_name_value . ' - ' . $res->title;
                $modified_route_category = str_replace(' ', '-', strtolower(trim($res->name)));
                $routes[$res->field_route_id_value]['route_category'] = $modified_route_category;
                $routes[$res->field_route_id_value]['route_color'] = $res->field_route_color_value;
                $routes[$res->field_route_id_value]['route_text_color'] = $res->field_route_text_color_value;

                // Effective Dates.
                $routes[$res->field_route_id_value]['effective_dates'] = $effective_dates;

                // Schedule Status.
                $routes[$res->field_route_id_value]['schedule_status'] = [
                  'current' => isset($schedule_status[$res->field_route_id_value]['current']) ? $schedule_status[$res->field_route_id_value]['current'] : FALSE,
                  'upcoming' => isset($schedule_status[$res->field_route_id_value]['upcoming']) ? $schedule_status[$res->field_route_id_value]['upcoming'] : FALSE,
                ];

                // Route Mapping.
                $routes[$res->field_route_id_value]['route_mapping'] = [
                  'current' => isset($route_mapping['new_to_old'][$res->field_route_id_value]) ? $route_mapping['new_to_old'][$res->field_route_id_value] : [$res->field_route_id_value],
                  'upcoming' => isset($route_mapping['old_to_new'][$res->field_route_id_value]) ? $route_mapping['old_to_new'][$res->field_route_id_value] : [$res->field_route_id_value],
                ];
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }

          /******************************
           * Query 7:
           * - Service List
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 7: Service List', 'success', $is_drush);
            $table_key = 'gtfs_calendar' . $database_table_version_suffix;
            $query = $this->databaseConnection->select($table_key, 'gc');
            $query->fields('gc', [
              'service_id',
              'start_date',
              'end_date',
            ]);
            $results = $query->execute()->fetchall();

            $service_list = [];
            if (!empty($results)) {
              foreach ($results as $res) {
                $start_date = strtotime($res->start_date);
                $end_date = strtotime($res->end_date);
                /******************************
                 * Interval calculation explanation
                 * - Difference (seconds) divided by 86400 = days
                 * -- 86400 = 60 (minutes) * 60 (hours) * 24 (days)
                 ******************************/
                $interval = ($end_date - $start_date) / 86400;

                $service_list[$res->service_id] = [
                  'start_date' => $start_date,
                  'end_date' => $end_date,
                  'interval' => $interval,
                ];
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }

          /******************************
           * Query 8:
           * - Service Options
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 8: Service Options', 'success', $is_drush);
            $table_key = 'gtfs_calendar_attributes' . $database_table_version_suffix;
            $query = $this->databaseConnection->select($table_key, 'gca');
            $query->fields('gca', [
              'service_id',
              'service_description',
            ]);
            $results = $query->execute()->fetchall();

            $service_mapping = [];
            if (!empty($results)) {
              foreach ($results as $res) {
                if (strlen($res->service_id) > 1) {
                  $service_mapping[$res->service_id]['parent_service_id'] = substr($res->service_id, -1);
                }
                $service_mapping[$res->service_id]['description'] = $res->service_description;
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }

          /******************************
           * Query 9:
           * - Directions
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 9: Directions', 'success', $is_drush);
            $table_key = 'gtfs_directions' . $database_table_version_suffix;
            $query = $this->databaseConnection->select($table_key, 'gd');
            $query->fields('gd', [
              'route_id',
              'direction_id',
              'direction_name',
            ]);
            $results = $query->execute()->fetchAll();

            $route_direction_mapping = [];
            if (!empty($results)) {
              foreach ($results as $res) {
                $route_direction_mapping[$res->route_id][$res->direction_id] = [
                  'direction_code' => $res->direction_name[0] . 'B',
                  'direction_name' => $res->direction_name,
                ];
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }

          /******************************
           * Query 10:
           * - Routes - Detailed
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 10: Routes - Detailed', 'success', $is_drush);
            $table_key = 'gtfs_trips' . $database_table_version_suffix;
            $query = $this->databaseConnection->select($table_key, 'gt');
            $query->fields('gt', [
              'route_id',
              'service_id',
              'trip_id',
              'direction_id',
              'shape_id',
            ]);
            $query->orderBy('service_id', 'ASC');
            $results = $query->execute()->fetchAll();

            $trip_info_by_shape_id = [];
            $stops = [];
            $service_options = [];
            if (!empty($results)) {
              foreach ($results as $res) {
                if (
                  isset($master_stop_list[$res->route_id]) &&
                  isset($routes[$res->route_id]) &&
                  isset($route_direction_mapping[$res->route_id])
                ) {

                  // Map trip direction_id.
                  $trip_direction_id = $route_direction_mapping[$res->route_id][$res->direction_id]['direction_code'];

                  // Modify service_id, if necessary.
                  $modified_service_id = isset($service_mapping[$res->service_id]) ? $res->service_id : substr($res->service_id, -1);
                  $parent_service_id = '';
                  if (isset($service_mapping[$modified_service_id]['parent_service_id'])) {
                    $parent_service_id = $service_mapping[$modified_service_id]['parent_service_id'];
                  }
                  else {
                    $parent_service_id = $modified_service_id;
                  }

                  /******************************
                   * Trip info by Shape ID
                   * - Used for helping shape data
                   ******************************/
                  $trip_info_by_shape_id[$res->shape_id] = [
                    'route_id' => $res->route_id,
                    'direction_id' => $trip_direction_id,
                  ];

                  /******************************
                   * Route - Trip
                   ******************************/
                  $routes[$res->route_id]['schedule'][$trip_direction_id]['trips'][$parent_service_id][$res->trip_id] = $trip_list[$res->trip_id];

                  /******************************
                   * Route - Service
                   ******************************/
                  if (isset($service_list[$res->service_id])) {
                    if (!isset($routes[$res->route_id]['schedule'][$trip_direction_id]['services'][$parent_service_id][$res->service_id])) {
                      $routes[$res->route_id]['schedule'][$trip_direction_id]['services'][$parent_service_id][$res->service_id] = $service_list[$res->service_id];
                    }
                    $routes[$res->route_id]['schedule'][$trip_direction_id]['services'][$parent_service_id][$res->service_id]['trips'][] = $res->trip_id;
                  }

                  if (
                    isset($master_stop_list[$res->route_id]) &&
                    isset($master_stop_list[$res->route_id][$trip_direction_id]) &&
                    !isset($routes[$res->route_id]['schedule'][$trip_direction_id]['stops'][$parent_service_id])
                  ) {
                    foreach ($master_stop_list[$res->route_id][$trip_direction_id] as $stop_info) {
                      /******************************
                       * Route - Stops
                       ******************************/
                      $stop_name = isset($stop_name_list[$stop_info['stop_id']]) ? $stop_name_list[$stop_info['stop_id']] : '';
                      $routes[$res->route_id]['schedule'][$trip_direction_id]['stops'][$parent_service_id][$stop_info['stop_id']] = $stop_name;
                      /******************************
                       * Route - Stop Coordinates
                       ******************************/
                      $stop_coordinates = isset($stop_coordinate_list[$stop_info['stop_id']]) ? $stop_coordinate_list[$stop_info['stop_id']] : [];
                      $routes[$res->route_id]['schedule'][$trip_direction_id]['stop_coordinates'][$parent_service_id][$stop_info['stop_id']] = $stop_coordinates;
                      /******************************
                       * Route - Stop Sequence
                       ******************************/
                      $routes[$res->route_id]['schedule'][$trip_direction_id]['stop_sequence'][$parent_service_id][$stop_info['stop_sequence']] = [
                        'stop_id' => $stop_info['stop_id'],
                        'stop_type' => $stop_info['stop_type'],
                        'timepoint_availability' => $stop_info['timepoint_availability'][$parent_service_id],
                      ];
                    }

                    if (isset($routes[$res->route_id]['schedule'][$trip_direction_id]['stop_sequence'])) {
                      ksort($routes[$res->route_id]['schedule'][$trip_direction_id]['stop_sequence']);
                    }
                  }
                  ksort($routes[$res->route_id]['schedule']);

                  /******************************
                   * Route - Direction Options
                   ******************************/
                  $routes[$res->route_id]['direction_options'][$trip_direction_id] = $route_direction_mapping[$res->route_id][$res->direction_id]['direction_name'];
                  ksort($routes[$res->route_id]['direction_options']);

                  /******************************
                   * Stops - Direction & Stop Time
                   ******************************/
                  foreach (array_keys($trip_list[$res->trip_id]) as $stop_id) {
                    $stops[$stop_id]['routes'][$res->route_id]['days_of_service'][$parent_service_id][$res->trip_id] = [
                      'direction' => $trip_direction_id,
                      'stop_time' => $trip_list[$res->trip_id][$stop_id],
                    ];
                  }

                  /******************************
                   * Route - Day of Service Options
                   ******************************/
                  if (isset($service_mapping[$modified_service_id]) && !isset($routes[$res->route_id]['day_of_service_options'][$modified_service_id])) {
                    if (!empty($parent_service_id)) {
                      $routes[$res->route_id]['day_of_service_options'][$parent_service_id]['description'] = $service_mapping[$parent_service_id]['description'];

                      if ($modified_service_id != $parent_service_id) {
                        $routes[$res->route_id]['day_of_service_options'][$parent_service_id]['variants'][$res->trip_id] = [
                          'variant_service_id' => $modified_service_id,
                          'description' => $service_mapping[$modified_service_id]['description'],
                        ];
                      }
                    }
                    else {
                      $routes[$res->route_id]['day_of_service_options'][$modified_service_id] = $service_mapping[$modified_service_id];
                    }
                  }
                  ksort($routes[$res->route_id]['day_of_service_options']);

                  /******************************
                   * General - Day of Service Options
                   ******************************/
                  $service_options[$modified_service_id] = $service_mapping[$modified_service_id];
                }
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }

          /******************************
           * Query 11:
           * - Shapes
           ******************************/
          if (!$skip_query_and_insert) {
            $this->sendDrushLog('Running Query 11: Shapes', 'success', $is_drush);
            $table_key = 'gtfs_shapes' . $database_table_version_suffix;
            $query = $this->databaseConnection->select($table_key, 'gs');
            $query->fields('gs', [
              'shape_id',
              'shape_pt_lat',
              'shape_pt_lon',
            ]);
            $results = $query->execute()->fetchAll();

            if (!empty($results)) {
              foreach ($results as $res) {
                if (isset($trip_info_by_shape_id[$res->shape_id])) {
                  $route_id = $trip_info_by_shape_id[$res->shape_id]['route_id'];
                  $direction_id = $trip_info_by_shape_id[$res->shape_id]['direction_id'];

                  if (
                    !empty($route_id) &&
                    !empty($direction_id) &&
                    isset($routes[$route_id])
                  ) {
                    $routes[$route_id]['shapes'][$direction_id][$res->shape_id][] = [
                      'lat' => (double) $res->shape_pt_lat,
                      'lng' => (double) $res->shape_pt_lon,
                    ];
                  }
                }
              }
            }
            else {
              $this->sendDrushLog('-- No data returned from query', 'warning', $is_drush);
              $this->sendDrushLog('-- Skipping all following queries', 'warning', $is_drush);
              $skip_query_and_insert = TRUE;
            }
          }
          $this->sendDrushLog('-------------------', 'success', $is_drush);

          /******************************
           * Insert 1: Routes
           * - Route ID
           * - Route Name
           ******************************/
          $this->sendDrushLog('Running Insert 1: Routes - Route ID and Route Name', 'success', $is_drush);
          $table_key = 'vta_routes_id_name';

          if (isset($routes) && !empty($routes) && !$skip_query_and_insert) {
            // Truncate the table.
            $this->sendDrushLog('-- Truncating', 'success', $is_drush);
            $this->databaseConnection->truncate($table_key)->execute();

            // Insert the new data.
            $this->sendDrushLog('-- Inserting', 'success', $is_drush);
            $query = $this->databaseConnection->insert($table_key);
            $query->fields([
              'id',
              'data',
              'created',
            ]);
            ksort($routes);
            foreach ($routes as $route_id => $route) {
              $query->values([
                'id' => $route_id,
                'data' => serialize($route['route_name']),
                'created' => strtotime('now'),
              ]);
            }
            $query->execute();
          }
          else {
            $this->sendDrushLog('-- Insert not run', 'warning', $is_drush);
          }

          /******************************
           * Insert 2: Routes
           * - All information
           ******************************/
          $this->sendDrushLog('Running Insert 2: Routes - All information', 'success', $is_drush);
          $table_key = 'vta_routes_all' . $database_table_version_suffix;

          // Truncate the table.
          $this->sendDrushLog('-- Truncating', 'success', $is_drush);
          $this->databaseConnection->truncate($table_key)->execute();

          if (isset($routes) && !empty($routes) && !$skip_query_and_insert) {
            // Insert the new data.
            $this->sendDrushLog('-- Inserting', 'success', $is_drush);
            $query = $this->databaseConnection->insert($table_key);
            $query->fields([
              'id',
              'data',
              'created',
            ]);
            ksort($routes);
            foreach ($routes as $route_id => $route) {
              $query->values([
                'id' => $route_id,
                'data' => serialize($route),
                'created' => strtotime('now'),
              ]);
            }
            $query->execute();
          }
          else {
            $this->sendDrushLog('-- Insert not run', 'warning', $is_drush);
          }

          /******************************
           * Insert 3: Routes
           * - Next Ride
           ******************************
           * Note:
           * - Only runs for Current
           ******************************/
          $this->sendDrushLog('Running Insert 3: Routes - Next Ride', 'success', $is_drush);
          $table_key = 'vta_routes_next_ride';

          if (isset($routes) && !empty($routes) && !$skip_query_and_insert && $version != 'upcoming') {
            // Truncate the table.
            $this->sendDrushLog('-- Truncating', 'success', $is_drush);
            $this->databaseConnection->truncate($table_key)->execute();

            // Insert the new data.
            $this->sendDrushLog('-- Inserting', 'success', $is_drush);
            $query = $this->databaseConnection->insert($table_key);
            $query->fields([
              'id',
              'data',
              'created',
            ]);
            ksort($routes);
            foreach ($routes as $route_id => $route) {
              if (isset($route['schedule'])) {
                $route_next_ride['direction_options'] = $route['direction_options'];
                foreach ($route['schedule'] as $direction_id => $direction_info) {
                  $route_next_ride['stops_by_direction'][$direction_id]['stops'] = $direction_info['stops'];
                }
                $query->values([
                  'id' => $route_id,
                  'data' => serialize($route_next_ride),
                  'created' => strtotime('now'),
                ]);
              }
            }
            $query->execute();
          }
          else {
            $this->sendDrushLog('-- Insert not run', 'warning', $is_drush);
          }

          /******************************
           * Insert 4: Stops
           * - All information
           ******************************/
          $this->sendDrushLog('Running Insert 4: Stops - All information', 'success', $is_drush);
          $table_key = 'vta_stops_all';

          if (isset($routes) && !empty($routes) && !$skip_query_and_insert) {
            // Truncate the table.
            $this->sendDrushLog('-- Truncating', 'success', $is_drush);
            $this->databaseConnection->truncate($table_key)->execute();

            // Insert the new data.
            $this->sendDrushLog('-- Inserting', 'success', $is_drush);
            $query = $this->databaseConnection->insert($table_key);
            $query->fields([
              'id',
              'data',
              'created',
            ]);
            ksort($stops);
            foreach ($stops as $stop_id => $stop) {
              $query->values([
                'id' => $stop_id,
                'data' => serialize($stop),
                'created' => strtotime('now'),
              ]);
            }
            $query->execute();
          }
          else {
            $this->sendDrushLog('-- Insert not run', 'warning', $is_drush);
          }

          /******************************
           * Insert 5: Service Options
           * - All information
           ******************************/
          $this->sendDrushLog('Running Insert 5: Service Options - All information', 'success', $is_drush);
          $table_key = 'vta_service_options' . $database_table_version_suffix;

          // Truncate the table.
          $this->sendDrushLog('-- Truncating', 'success', $is_drush);
          $this->databaseConnection->truncate($table_key)->execute();

          if (isset($routes) && !empty($routes) && !$skip_query_and_insert) {
            // Insert the new data.
            $this->sendDrushLog('-- Inserting', 'success', $is_drush);
            $query = $this->databaseConnection->insert($table_key);
            $query->fields([
              'id',
              'data',
              'created',
            ]);
            foreach ($service_options as $day_of_travel => $service) {
              $query->values([
                'id' => $day_of_travel,
                'data' => serialize($service),
                'created' => strtotime('now'),
              ]);
            }
            $query->execute();
          }
          else {
            $this->sendDrushLog('-- Insert not run', 'warning', $is_drush);
          }

          $this->sendDrushLog('--------------------------------------', 'success', $is_drush);
        }
        catch (\Exception $e) {
          throw $e;
        }
        finally {
          /******************************
           * Validate
           * - Expected Result:
           * -- Table is EMPTY     -- TRUE
           * -- Table is NOT EMPTY -- FALSE
           ******************************/
          $validation_result = TRUE;
          $insert_tables_expected_results = [
            'vta_routes_id_name' => FALSE,
            'vta_routes_all' . $database_table_version_suffix => FALSE,
            'vta_routes_next_ride' => FALSE,
            'vta_stops_all' => FALSE,
            'vta_service_options' . $database_table_version_suffix => FALSE,
          ];
          if ($skip_query_and_insert) {
            $insert_tables_expected_results['vta_routes_all' . $database_table_version_suffix] = TRUE;
            $insert_tables_expected_results['vta_service_options' . $database_table_version_suffix] = TRUE;
          }

          foreach ($insert_tables_expected_results as $table_key => $expected_result) {
            $query = $this->databaseConnection->select($table_key, 'it');
            $query->addExpression('COUNT(it.id)', 'count');
            $results = $query->execute()->fetchAll();
            if (!empty($results)) {
              $actual_result = intval($results[0]->count) === 0 ? TRUE : FALSE;

              if ($actual_result !== $expected_result) {
                $validation_result = FALSE;
                break;
              }
            }
          }

          /******************************
           * Logging
           ******************************/
          $logging_info = [
            'step' => 'Generate - ' . ucfirst($version),
            'result' => $validation_result ? 'passed' : 'failed',
            'summary' => '',
          ];
          if ($skip_query_and_insert) {
            $logging_info['summary'] = $validation_result ? ucfirst($version) . ' data not generated.' : 'Successfully generated ' . ucfirst($version) . ' data.';
          }
          else {
            $logging_info['summary'] = $validation_result ? 'Successfully generated ' . ucfirst($version) . ' data.' : ucfirst($version) . ' data not generated.';
          }
          $this->logMessage($is_drush, $logging_info);
        }
      }
    }
    catch (\Exception $e) {
      throw $e;
    }
  }

  /******************************************************************************************
   * GTFS Import support helper functions.
   */

  /**
   * Helper function to remove or copy GTFS files.
   *
   * @param array $mapping
   *   Mapping for the files.
   * @param bool $is_drush
   *   Flag to determine if Drush log must be sent.
   */
  protected function prepareFiles(array $mapping, $is_drush = FALSE) {
    // Get array of all source files.
    $files = scandir($mapping['source']);
    $files_to_delete = [];

    $copy_file_count = 0;
    $remove_file_count = 0;

    // Cycle through all source files.
    foreach ($files as $file) {
      // Don't include the current ('.')
      // and previous ('..') directories.
      if (in_array($file, ['.', '..', 'helper']) || substr($file, 0, 1) == '.') {
        continue;
      }

      switch ($mapping['action']) {
        case 'remove':
          $files_to_delete[] = $mapping['source'] . $file;
          break;

        case 'copy':
          // If we copied the file successfully,
          // mark it for deletion.
          if (copy($mapping['source'] . $file, $mapping['destination'] . $file)) {
            $files_to_delete[] = $mapping['source'] . $file;
            $copy_file_count++;
          }
          break;
      }
    }

    // Delete all successfully-copied files.
    foreach ($files_to_delete as $file_to_delete) {
      unlink($file_to_delete);
      $remove_file_count++;
    }

    $this->sendDrushLog('-- Files copied: ' . $copy_file_count, 'success', $is_drush);
    $this->sendDrushLog('-- Files removed: ' . $remove_file_count, 'success', $is_drush);
  }

  /**
   * Helper function to upload archived GTFS files.
   *
   * @param string $file
   *   Uploaded archivied GTFS file.
   * @param string $file_version
   *   File version (gtfs_files|gtfs_helper_files)
   * @param string $gtfs_version
   *   GTFS version (current|upcoming)
   * @param bool $manual_import
   *   If this function is being called for the manual import.
   */
  public function retrieveFiles($file, $file_version, $gtfs_version, $manual_import = FALSE) {
    $file_mime_type = $file->getMimeType();

    if ($file_mime_type === 'application/zip') {
      $file_uri = $file->getFileUri();
      $file_path = $this->fileSystem->realpath($file_uri);

      // Check if file exists and if it is readable.
      // If so, open Zip archive and extract content.
      if (is_readable($file_path)) {
        $zip = new \ZipArchive();
        $res = $zip->open($file_path);
        if ($res === TRUE) {
          // Determine directory path and directory.
          switch ($file_version) {
            case 'gtfs_files':
              $directory_path = 'private://vta_gtfs_import_files/' . $gtfs_version;
              break;

            case 'gtfs_helper_files':
              $directory_path = 'private://vta_gtfs_import_files/' . $gtfs_version . '/helper';
              break;
          }
          $directory = $this->fileSystem->realpath($directory_path);
          file_prepare_directory($directory, FILE_CREATE_DIRECTORY);

          // Remove all files in directory.
          $this->handleRetrievedFiles($directory, 'remove');

          // Extract files from the zip to directory.
          $zip->extractTo($directory);
          $zip->close();

          // Move all unzipped files to directory.
          if ($manual_import) {
            $this->handleRetrievedFiles($directory, 'move');
          }
        }
      }
      else {
        if ($manual_import) {
          $this->messenger->deleteAll();
          $this->messenger->addMessage('Error while reading file. Please try again.', 'error', FALSE);
        }
      }
    }
    // Delete uploaded file.
    $file->delete();
  }

  /**
   * Helper function to remove or move uploaded archived GTFS files.
   *
   * @param string $directory
   *   Directory path.
   * @param string $action
   *   Action to be performed (remove/move)
   */
  protected function handleRetrievedFiles($directory, $action) {
    $directory_content = opendir($directory);

    while (($temp_file = readdir($directory_content)) !== FALSE) {
      if (in_array($temp_file, ['.', '..', 'helper']) || substr($temp_file, 0, 1) == '.') {
        continue;
      }
      else {
        $temp_file_path = $directory . '/' . $temp_file;

        switch ($action) {
          /******************************
           * Remove
           *****************************/
          case 'remove':
            // Remove file.
            if (is_file($temp_file_path)) {
              unlink($temp_file_path);
            }
            // Traverse into sub-directories to remove files.
            elseif (is_dir($temp_file_path)) {
              self::handleRetrievedFiles($temp_file_path, 'remove');
            }

            break;

          /******************************
           * Move
           *****************************/
          case 'move':
            // Traverse into sub-directories to move files.
            if (is_dir($temp_file_path)) {
              self::handleRetrievedFiles($temp_file_path, 'move');
            }

            break;
        }
      }
    }
    closedir($directory_content);
  }

  /******************************************************************************************
   * Logger helper functions.
   */

  /**
   * Helper function to send Drush log message if running from Drush command.
   *
   * @param string $message
   *   Message to be displayed.
   * @param string $type
   *   Type of message.
   * @param bool $is_drush
   *   Flag to determine if Drush log must be sent.
   */
  protected function sendDrushLog($message, $type, $is_drush = FALSE) {
    if ($is_drush) {
      drush_log($message, $type);
    }
    else {
      if (empty($this->state->get(VtaGtfsImportService::GTFS_IMPORT_LOG))) {
        $this->state->set(VtaGtfsImportService::GTFS_IMPORT_LOG, $message);
      }
      else {
        $this->state->set(VtaGtfsImportService::GTFS_IMPORT_LOG, $this->state->get(VtaGtfsImportService::GTFS_IMPORT_LOG) . "\n" . $message);
      }
    }
  }

  /**
   * Helper function write log message if import not running from Drush.
   *
   * @param bool $is_drush
   *   Flag to determine if origin is Drush.
   * @param array $logging_info
   *   Information to use when creating the log.
   */
  protected function logMessage(bool $is_drush, array $logging_info) {
    if (!$is_drush && !empty($this->state->get(VtaGtfsImportService::GTFS_IMPORT_LOG))) {
      /******************************
       * Create GTFS > Log
       ******************************/
      $gtfs_log = $this->entityManager->getStorage('gtfs_import')->create([
        'type' => 'log',
        'title' => $logging_info['step'],
        'field_log_result' => $logging_info['result'],
        'field_log_summary' => [
          'summary' => $logging_info['summary'],
          'value' => '<pre>' . $this->state->get(VtaGtfsImportService::GTFS_IMPORT_LOG) . '</pre>',
          'format' => 'full_html',
        ],
      ]);
      $gtfs_log->save();

      // Clear state.
      $this->state->delete(VtaGtfsImportService::GTFS_IMPORT_LOG);
    }
  }

}
