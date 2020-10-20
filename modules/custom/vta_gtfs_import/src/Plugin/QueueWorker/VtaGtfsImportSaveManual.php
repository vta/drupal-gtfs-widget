<?php

namespace Drupal\vta_gtfs_import\Plugin\QueueWorker;

/**
 * Provides save functionality for content.
 *
 * @QueueWorker(
 *   id = "vta_gtfs_import_save_manual",
 *   title = @Translation("VTA GTFS import - manually save data")
 * )
 */
class VtaGtfsImportSaveManual extends VtaGtfsImportSaveBase {}
