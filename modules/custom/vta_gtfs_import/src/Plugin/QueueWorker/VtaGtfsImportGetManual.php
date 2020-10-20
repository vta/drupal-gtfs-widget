<?php

namespace Drupal\vta_gtfs_import\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides Base parsing functionality for the CSV files.
 *
 * @QueueWorker(
 *   id = "vta_gtfs_import_get_manual",
 *   title = @Translation("Get data for import manually")
 * )
 */
class VtaGtfsImportGetManual extends VtaGtfsImportGetBase {

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
   * ReliableQueueInterface.
   *
   * @var Drupal\Core\Queue\ReliableQueueInterface
   */
  protected $queue;

  /**
   * {@inheritdoc}
   */
  public function __construct(QueueFactory $queue, QueueWorkerManagerInterface $queue_manager) {
    $this->queueFactory = $queue;
    $this->queueManager = $queue_manager;
    $this->queue = $this->queueFactory->get('vta_gtfs_import_save_manual', TRUE);
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

}
