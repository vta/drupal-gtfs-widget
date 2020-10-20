<?php

namespace Drupal\vta_route_schedule\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * VTA Route Navigation Form.
 */
class VtaRouteNavigationForm extends ConfigFormBase {

  /**
   * A route matcher to get information about the current route.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * VtaRouteNavigationForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   *   A route matcher to get information about the current route.
   */
  public function __construct(ConfigFactoryInterface $config_factory, CurrentRouteMatch $current_route_match) {
    parent::__construct($config_factory);
    $this->currentRouteMatch = $current_route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [self::CONFIG_OBJECT_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'vta_route_navigation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /******************************
     * Route Navigation
     * - Route
     ******************************/
    $form['route_navigation'] = [
      '#type' => 'container',
    ];

    /******************************
     * Route
     ******************************/
    $form['route_navigation']['route'] = [
      '#type' => 'select',
      '#title' => $this->t('Switch Route:'),
      '#options' => $this->getRouteOptions(),
      '#empty_option' => $this->t('- Select a Route -'),
      '#prefix' => '<div class="route-navigation-route-wrapper">',
      '#suffix' => '</div>',
    ];

    // Attach JS.
    $form['#attached']['library'][] = 'vta_route_schedule/vta_route_navigation';

    /******************************
     * Submit
     ******************************/
    $form['schedules']['actions']['submit_route_navigation'] = [
      '#type' => 'submit',
      '#value' => $this->t('Go'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => [
          'btn-primary',
          'visually-hidden',
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!empty($form_state->getValue('route'))) {
      $route_options = [
        'node' => $form_state->getValue('route'),
      ];

      $path = Url::fromRoute($this->currentRouteMatch->getRouteName(), $route_options);
      $path = $path->toString();
      $response = new RedirectResponse($path);
      $response->send();
    }
  }

  /**
   * Helper function to get Route Options.
   *
   * @return array|false
   *   Route options or false.
   */
  protected function getRouteOptions() {
    $db = Database::getConnection('default', 'default');
    $route_options = [];

    /******************************
     * Get collection of Route ID - Route Name
     ******************************/
    $query = $db->select('vta_routes_id_name', 'vrin');
    $query->fields('vrin', ['id', 'data']);
    $results = $query->execute()->fetchAll();

    if (!empty($results)) {
      foreach ($results as $res) {
        $routes[$res->id] = unserialize($res->data);
      }
    }

    if (isset($routes)) {
      $null_offset_value = 999;
      $query = $db->select('node__field_route_id', 'nfri');
      $query->fields('nfri', ['entity_id', 'field_route_id_value']);
      $query->fields('nfrso', ['field_route_sort_order_value']);
      $query->leftJoin('node__field_route_sort_order', 'nfrso', 'nfrso.entity_id = nfri.entity_id');
      $query->leftJoin('node_field_data', 'nfd', 'nfd.nid = nfri.entity_id');
      $query->condition('nfd.status', 1);
      $query->addExpression("CASE WHEN field_route_sort_order_value IS NULL THEN " . $null_offset_value . " ELSE field_route_sort_order_value END", 'route_order');
      $query->orderBy('route_order', 'ASC');
      $results = $query->execute()->fetchAll();

      if (!empty($results)) {
        foreach ($results as $res) {
          if (isset($routes[$res->field_route_id_value])) {
            $route_options[$res->entity_id] = $routes[$res->field_route_id_value];
          }
        }
      }
    }

    return $route_options;
  }

}
