<?php

namespace Drupal\vta_route_schedule\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Route Schedule Form.
 */
class VtaRouteScheduleForm extends ConfigFormBase {

  /**
   * A route matcher to get information about the current route.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * A request stack to get information about the current request.
   *
   * @var Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * VtaRouteScheduleForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   *   A route matcher to get information about the current route.
   * @param Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   A request stack to get information about the current request.
   */
  public function __construct(ConfigFactoryInterface $config_factory, CurrentRouteMatch $current_route_match, RequestStack $request_stack) {
    parent::__construct($config_factory);
    $this->currentRouteMatch = $current_route_match;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('current_route_match'),
      $container->get('request_stack')
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
    return 'vta_route_schedule_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $route = NULL, $direction_id = NULL, $service_id = NULL, $service_description = NULL, $origin = NULL, $destination = NULL, $schedule_table_id = NULL, $is_active = NULL) {
    $form = [];

    $form['#attributes']['class'][] = 'schedule-travel-options-wrapper';
    if ($is_active) {
      $form['#attributes']['class'][] = 'active';
    }
    $form['#attributes']['data-schedule-table-id'][] = $schedule_table_id;

    // Ensure that this Route exists in the routes list.
    if (isset($route) && isset($route['direction_options']) && isset($route['day_of_service_options'])) {
      $form['travel_options_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'travel-options-wrapper',
          ],
          'role' => 'tabpanel',
          'id' => $schedule_table_id,
        ],
      ];

      /******************************
       * Direction
       ******************************/
      $form['travel_options_wrapper']['direction'] = [
        '#type' => 'hidden',
        '#value' => $direction_id,
      ];

      /******************************
       * Day of Travel
       ******************************/
      $form['travel_options_wrapper']['day_of_travel'] = [
        '#type' => 'hidden',
        '#value' => $service_description,
      ];

      /******************************
       * Origin
       ******************************/
      $origin_options = [];
      if (!empty($route) && !empty($direction_id)) {
        $origin_options = $route['schedule'][$direction_id]['stops'][$service_id];
      }

      $form['travel_options_wrapper']['origin'] = [
        '#type' => 'select',
        '#title' => $this->t('Departing from'),
        '#options' => $origin_options,
        '#empty_option' => $this->t('Select origin'),
        '#default_value' => $origin,
        '#validated' => TRUE,
      ];

      /******************************
       * Destination
       ******************************/
      $destination_options = [];
      if (!empty($route) && !empty($direction_id)) {
        $destination_options = $route['schedule'][$direction_id]['stops'][$service_id];
      }
      $form['travel_options_wrapper']['destination'] = [
        '#type' => 'select',
        '#title' => $this->t('Arriving at'),
        '#options' => $destination_options,
        '#empty_option' => $this->t('Select destination'),
        '#default_value' => $destination,
        '#validated' => TRUE,
      ];

      /******************************
       * Submit
       ******************************/
      $form['travel_options_wrapper']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Submit'),
        '#button_type' => 'primary',
        '#attributes' => [
          'class' => [
            'btn-primary',
          ],
        ],
      ];
    }

    $form['#cache']['max-age'] = 0;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $input = $form_state->getUserInput();
    $route_options = [
      'node' => $this->currentRouteMatch->getParameter('node')->id(),
      'version' => $this->requestStack->getCurrentRequest()->get('version'),
      'direction' => $input['direction'],
      'day_of_travel' => $input['day_of_travel'],
      'rs_origin' => $form_state->getValue('origin'),
      'rs_destination' => $form_state->getValue('destination'),
    ];

    if (empty($route_options['rs_origin'])) {
      unset($route_options['rs_destination']);
    }

    foreach ($route_options as $key => $route_option) {
      if (empty($route_option)) {
        unset($route_options[$key]);
      }
    }

    $path = Url::fromRoute($this->currentRouteMatch->getRouteName(), $route_options);
    $path = $path->toString();
    $response = new RedirectResponse($path);
    $response->send();
  }

}
