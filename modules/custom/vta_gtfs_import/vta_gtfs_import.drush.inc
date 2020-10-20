<?php

/**
 * @file
 * Contains the code to generate the custom drush commands.
 */

/**
 * Implements hook_drush_command().
 */
function vta_gtfs_import_drush_command() {
  $items = [];

  $items['gtfs-import'] = [
    'description' => 'Runs VTA GTFS import',
    'options' => [
      'retrieve' => 'Retrieve GTFS import files',
      'prepare' => 'Prepare for switch over (if necessary)',
      'clean' => 'Clean queues',
      'populate' => 'Populate Getter queues',
      'get' => 'Process Get queues',
      'save' => 'Process Save queues',
      'generate' => 'Generate refined route data',
      'check' => 'Check GTFS import tracking',
    ],
    'drupal dependencies' => ['vta_gtfs_import'],
    'aliases' => ['gtfs_import'],
    'examples' => [
      'GTFS Import - Part 1' => 'drush gtfs_import --retrieve --prepare --clean --populate --get --save',
      'GTFS Import - Part 2' => 'drush gtfs_import --generate',
    ],
  ];

  return $items;
}

/**
 * Callback function for the run-import drush command.
 */
function drush_vta_gtfs_import_gtfs_import() {
  $import_manager = \Drupal::service('vta_gtfs_import.manager');

  $option_retrieve = drush_get_option('retrieve', FALSE);
  $option_prepare = drush_get_option('prepare', FALSE);
  $option_clean = drush_get_option('clean', FALSE);
  $option_populate = drush_get_option('populate', FALSE);
  $option_get = drush_get_option('get', FALSE);
  $option_save = drush_get_option('save', FALSE);
  $option_generate = drush_get_option('generate', FALSE);
  $option_check = drush_get_option('check', FALSE);

  /******************************
   * Retrieve
   *****************************/
  if ($option_retrieve) {
    $import_manager->retrieve(TRUE);
  }

  /******************************
   * Prepare
   *****************************/
  if ($option_prepare) {
    $import_manager->prepare(TRUE);
  }

  /******************************
   * Clean queues:
   * - Get
   * - Save
   ******************************/
  if ($option_clean) {
    $import_manager->clean(TRUE);
  }

  /******************************
   * Populate Getter queues
   ******************************/
  if ($option_populate) {
    $import_manager->populate(TRUE);
  }

  /******************************
   * Process queues:
   * - Get
   * - Save
   ******************************/
  /******************************
   * Get Queue
   ******************************/
  if ($option_get) {
    $import_manager->process('vta_gtfs_import_get_manual', TRUE);
  }

  /******************************
   * Save Queue
   ******************************/
  if ($option_save) {
    $import_manager->process('vta_gtfs_import_save_manual', TRUE);
  }

  /******************************
   * Check Import Tracking.
   ******************************/
  if ($option_check) {
    $import_manager->check(TRUE);
  }

  /******************************
   * Generate refined route data.
   ******************************/
  if ($option_generate) {
    $import_manager->generate(TRUE);
  }
}
