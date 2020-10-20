<?php

namespace Drupal\vta_gtfs_import\Plugin\QueueWorker;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\redirect\Entity\Redirect;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides base parsing and saving functionality for the GTFS information.
 */
abstract class VtaGtfsImportSaveBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * EntityTypeManagerInterface.
   *
   * @var Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * QueryFactory.
   *
   * @var Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entityManager, QueryFactory $entityQuery) {
    $this->entityManager = $entityManager;
    $this->entityQuery = $entityQuery;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.query')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (isset($data->key)) {
      $info = $data->info;
      $key = $data->key;
      $version = $data->version;
      $bundle = '';

      switch ($key) {
        // Route content type.
        case 'gtfs_routes':
          $bundle = 'route';
          break;

        // Station content type.
        case 'gtfs_stations':
          $bundle = 'station';
          break;
      }

      if (!empty($bundle)) {
        foreach ($info as $row) {
          // Ensure that the stop is a station.
          if ($bundle == 'station') {
            $row = $this->getStationRelations($row);
          }

          // Check if the entity already exists.
          $existing_entity_id = $this->checkEntity($row, $bundle);

          if (!$existing_entity_id) {
            // Create a new entity.
            $this->createEntity($row, $bundle, $version);
          }
          else {
            // Update an existing entity.
            $this->updateEntity($row, $bundle, $version, $existing_entity_id);
          }
        }
      }
      else {
        $this->createDatabaseEntries($key, $info);
      }
    }
  }

  /**
   * Helper function to check for an existing entity.
   *
   * @param array $mapped_values
   *   Import mapped values.
   * @param string $bundle
   *   Bundle type.
   *
   * @return int|false
   *   Existing entity id or false.
   */
  protected function checkEntity(array $mapped_values, $bundle) {
    $db = Database::getConnection('default', 'default');

    $query = $db->select('node', 'n');
    $query->fields('n', ['nid']);

    switch ($bundle) {
      // Route content type.
      case 'route':
        $query->leftJoin('node__field_route_id', 'rid', 'n.nid = rid.entity_id');
        $query->condition('rid.bundle', $bundle);
        $query->condition('rid.field_route_id_value', $mapped_values['field_route_id']);
        break;

      // Station content type.
      case 'station':
        $query->leftJoin('node__field_stop_id', 'sid', 'n.nid = sid.entity_id');
        $query->condition('sid.bundle', $bundle);
        $query->condition('sid.field_stop_id_value', $mapped_values['field_stop_id']);
        break;
    }

    $res = $query->execute()->fetchAll();

    if (!empty($res)) {
      $res = reset($res);
      return $res->nid;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Helper function to create an entity.
   *
   * @param array $mapped_values
   *   Import mapped values.
   * @param string $bundle
   *   Entity bundle.
   * @param string $version
   *   Import version.
   */
  protected function createEntity(array $mapped_values, $bundle, $version) {
    /******************************
     * Add any additional fields that require processing.
     ******************************/
    switch ($bundle) {
      // Route content type.
      case 'route':
        // Route Status.
        $mapped_values['field_route_status'] = 'new';
        // Route Category.
        if (isset($mapped_values['field_route_category']) && !empty($mapped_values['field_route_category'])) {
          $mapped_values['field_route_category'] = $this->parseTaxonomyTerm('route_category', $mapped_values['field_route_category']);
        }
        // Tracking ID.
        $tracking_id = $mapped_values['field_route_id'];
        break;

      // Station content type.
      case 'station':
        // Tracking ID.
        $tracking_id = $mapped_values['field_stop_id'];
        break;
    }

    /******************************
     * Create Node
     ******************************/
    $mapped_values = array_merge($mapped_values, [
      'nid' => NULL,
      'type' => $bundle,
      'uid' => 1,
      'status' => 1,
      'promote' => 0,
      'sticky' => 0,
    ]);

    $node = $this->entityManager->getStorage('node')->create($mapped_values);
    $node->save();

    /******************************
     * Redirects
     ******************************/
    if ($bundle == 'route' && !empty($mapped_values['field_route_url'])) {
      $redirect_alias = explode('/', $mapped_values['field_route_url']);
      $redirect_alias = $redirect_alias[3] . '/' . $redirect_alias[4];

      // Check if redirect exists.
      $redirect_exists = $this->checkRedirect($redirect_alias);

      // Create the redirect if it doesn't exist.
      if (!$redirect_exists) {
        $this->createRedirect($node->id(), $redirect_alias);
      }
    }

    /******************************
     * Track the import
     ******************************/
    if ($bundle == 'route') {
      $db = Database::getConnection('default', 'default');
      $table_key = 'vta_gtfs_import_route_tracking';
      $query = $db->insert($table_key);
      $query->fields([
        'id',
        'file',
        'last_updated',
      ]);
      $query->values([
        $tracking_id,
        $version,
        strtotime('now'),
      ]);
      $query->execute();
    }
  }

  /**
   * Helper function to update an existing entity.
   *
   * @param array $mapped_values
   *   Import mapped values.
   * @param string $bundle
   *   Entity bundle.
   * @param string $version
   *   Import version.
   * @param int $existing_entity_id
   *   Entity id for the existing entity.
   */
  protected function updateEntity(array $mapped_values, $bundle, $version, $existing_entity_id) {
    $entity = $this->entityManager->getStorage('node')->load($existing_entity_id);

    if (isset($entity)) {
      /******************************
       * Add any additional fields that require processing.
       ******************************/
      switch ($bundle) {
        // Route content type.
        case 'route':
          // Route Status.
          $mapped_values['field_route_status'] = 'active';
          // Route Category.
          if (isset($mapped_values['field_route_category']) && !empty($mapped_values['field_route_category'])) {
            $mapped_values['field_route_category'] = $this->parseTaxonomyTerm('route_category', $mapped_values['field_route_category']);
          }
          // Tracking ID.
          $tracking_id = $mapped_values['field_route_id'];
          break;

        // Station content type.
        case 'station':
          // Don't update the Station title.
          unset($mapped_values['title']);
          // Tracking ID.
          $tracking_id = $mapped_values['field_stop_id'];
          break;
      }

      if ($version === 'current' && ($bundle === 'route' || $bundle === 'station')) {
        /******************************
         * Update Node
         ******************************/
        foreach ($mapped_values as $key => $value) {
          $entity->$key->setValue($value);
        }
        $entity->save();

        /******************************
         * Redirects
         ******************************/
        if ($bundle == 'route' && !empty($mapped_values['field_route_url'])) {
          $matches = [];
          preg_match('/.*\/\/.*?\/(.*)/', $mapped_values['field_route_url'], $matches);
          $redirect_alias = $matches[1];

          // Check if redirect exists.
          $redirect_exists = $this->checkRedirect($redirect_alias);

          // Create the redirect if it doesn't exist.
          if (!$redirect_exists) {
            $this->createRedirect($entity->id(), $redirect_alias);
          }
        }
      }

      /******************************
       * Track the import
       ******************************/
      if ($bundle == 'route') {
        $db = Database::getConnection('default', 'default');
        $table_key = 'vta_gtfs_import_route_tracking';
        $query = $db->merge($table_key);
        $query->insertFields([
          'id' => $tracking_id,
          'file' => $version,
          'last_updated' => strtotime('now'),
        ]);
        $query->updateFields([
          'file' => $version,
          'last_updated' => strtotime('now'),
        ]);
        $query->key('id', $tracking_id);
        $query->execute();
      }
    }
  }

  /**
   * Helper function to parse the taxonomy for an entity.
   *
   * @param string $term_vid
   *   Taxonomy term vocabulary ID.
   * @param string $term_name
   *   Taxonomy term name.
   *
   * @return string
   *   Taxonomy term ID.
   */
  protected function parseTaxonomyTerm($term_vid, $term_name) {
    $term_name = trim($term_name);

    // Check if the taxonomy term already exists.
    $taxonomy_term = $this->entityManager->getStorage('taxonomy_term')->loadByProperties(['name' => $term_name]);

    // Create the taxonomy term if it doesn't already exist.
    if (empty($taxonomy_term)) {
      $entity_values = [
        'name' => $term_name,
        'vid' => $term_vid,
      ];

      $taxonomy_term = $this->entityManager->getStorage('taxonomy_term')->create($entity_values);
      $taxonomy_term->save();
    }
    else {
      $taxonomy_term = reset($taxonomy_term);
    }

    return $taxonomy_term->id();
  }

  /**
   * Helper function to see if there is already a redirect for this node.
   *
   * @param string $redirect_alias
   *   Node alias from the old site.
   */
  protected function checkRedirect($redirect_alias) {
    $db = Database::getConnection('default', 'default');
    $query = $db->select('redirect', 'r');
    $query->fields('r', ['rid']);
    $query->condition('r.redirect_source__path', $redirect_alias);
    $results = $query->execute()->fetchAll();

    if (isset($results[0]->rid)) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Helper function to create a redirect for node.
   *
   * @param int $nid
   *   Node id.
   * @param string $redirect_alias
   *   Node alias from the old site.
   */
  protected function createRedirect($nid, $redirect_alias) {
    Redirect::create([
      'redirect_source' => $redirect_alias,
      'redirect_redirect' => 'internal:/node/' . $nid,
      'status_code' => '302',
    ])->save();
  }

  /**
   * Helper function to get the related stops and routes for a Station entity.
   *
   * @param array $mapped_values
   *   Import mapped values.
   *
   * @return array
   *   Import mapped values with relations added.
   */
  protected function getStationRelations(array $mapped_values) {
    $db = Database::getConnection('default', 'default');
    $stop_id = $mapped_values['field_stop_id'];

    /******************************
     * Related Stops
     ******************************/
    $mapped_values['field_related_stops'] = [];
    $mapped_values['field_related_routes'] = [];

    $query = $db->select('gtfs_stops', 'gs');
    $query->fields('gs', ['stop_id']);
    $query->condition('gs.parent_station', $stop_id);
    $results = $query->execute()->fetchAll();

    if (!empty($results)) {
      foreach ($results as $res) {
        $mapped_values['field_related_stops'][] = $res->stop_id;
      }
    }

    /******************************
     * Related Routes
     ******************************/
    if (!empty($mapped_values['field_related_stops'])) {
      $query = $db->select('vta_stops_all', 'vsa');
      $query->fields('vsa', ['data']);
      $query->condition('vsa.id', $mapped_values['field_related_stops'], 'IN');
      $results = $query->execute()->fetchAll();

      $related_route_ids = [];
      if (!empty($results)) {
        foreach ($results as $res) {
          $related_route_ids = array_merge($related_route_ids, array_keys(unserialize($res->data)['routes']));
        }

        if (!empty($related_route_ids)) {
          $query = $db->select('node', 'n');
          $query->fields('n', ['nid']);
          $query->leftJoin('node__field_route_id', 'rid', 'n.nid = rid.entity_id');
          $query->condition('rid.bundle', 'route');
          $query->condition('rid.field_route_id_value', $related_route_ids, 'IN');
          $results = $query->execute()->fetchAll();

          if (!empty($results)) {
            foreach ($results as $res) {
              $mapped_values['field_related_routes'][] = $res->nid;
            }
          }
        }
      }
    }

    return $mapped_values;
  }

  /**
   * Helper function to create a database entry.
   *
   * @param string $key
   *   Import key (database table).
   * @param array $info
   *   Import mapped values.
   */
  protected function createDatabaseEntries($key, array $info) {
    // Add entries to the database.
    $db = Database::getConnection('default', 'default');
    $query = $db->insert($key);
    // Fields.
    $fields = array_keys(reset($info));
    $query->fields($fields);
    // Values.
    foreach ($info as $row) {
      $query->values($row);
    }
    $query->execute();
  }

}
