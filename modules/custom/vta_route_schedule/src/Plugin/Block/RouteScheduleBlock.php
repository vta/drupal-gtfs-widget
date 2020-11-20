<?php

namespace Drupal\vta_route_schedule\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Renderer;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\vta\Services\VtaPurger;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a 'Route Schedule' block.
 *
 * @Block(
 *   id = "route_schedule",
 *   admin_label = @Translation("Route Schedule"),
 * )
 */
class RouteScheduleBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Provides an interface for entity type managers.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * Turns a render array into a HTML string.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * Purges Varnish entry for a specific URL.
   *
   * @var \Drupal\vta\Service\VtaPurger
   */
  protected $vtaPurger;

  /**
   * RouteScheduleBlock constructor.
   *
   * @param array $configuration
   *   The plugin configuration, i.e. an array with configuration values keyed
   *   by configuration option name. The special key 'context' may be used to
   *   initialize the defined contexts by setting it to an array of context
   *   values keyed by context names.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   *   A route matcher to get information about the current route.
   * @param Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   A request stack to get information about the current request.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   The renderer object.
   * @param \Drupal\vta\Service\VtaPurger $vta_purger
   *   URL purger.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CurrentRouteMatch $current_route_match, RequestStack $request_stack, FormBuilderInterface $form_builder, EntityTypeManagerInterface $entity_manager, Renderer $renderer, VtaPurger $vta_purger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentRouteMatch = $current_route_match;
    $this->requestStack = $request_stack;
    $this->formBuilder = $form_builder;
    $this->entityManager = $entity_manager;
    $this->renderer = $renderer;
    $this->purger = $vta_purger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('request_stack'),
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('vta.purger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $build['#cache']['max-age'] = 0;

    $node = $this->currentRouteMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      $build_schedule_toggle = FALSE;
      $route_id = $node->get('field_route_id')->getValue()[0]['value'];

      $db = Database::getConnection('default', 'default');
      // Get version of the Route Schedule, if specified.
      $version = $this->requestStack->getCurrentRequest()->get('version');
      $version = !empty($version) ? $version : 'current';
      $table_key = 'vta_routes_all' . (($version == 'upcoming') ? '__' . $version : '');
      $query = $db->select($table_key, 'vra');
      $query->fields('vra', ['data']);
      $query->condition('vra.id', $route_id);
      $results = $query->execute()->fetchAll();

      if (!empty($results)) {
        $route = unserialize($results[0]->data);

        // Check to see if the schedule toggle should be built.
        $table_key = 'vta_routes_all__upcoming';
        $query = $db->select($table_key, 'vra');
        $query->fields('vra', ['id']);
        $query->condition('vra.id', $route_id);
        $results = $query->execute()->fetchAll();

        if (!empty($results)) {
          $build_schedule_toggle = TRUE;
        }
      }
    }

    // Ensure that the route has trips and stops defined.
    $valid_schedule = TRUE;
    if (isset($route['schedule'])) {
      foreach ($route['schedule'] as $direction_id => $direction_schedule) {
        if (!isset($direction_schedule['trips']) || !isset($direction_schedule['stops'])) {
          $valid_schedule = FALSE;
          break;
        }
      }
    }

    // Ensure that this Route exists in the routes list.
    if (
      isset($route) &&
      isset($route['schedule']) &&
      $valid_schedule &&
      isset($route['direction_options']) &&
      isset($route['day_of_service_options']) &&
      (
        (
          $version === 'current' &&
          isset($route['schedule_status']['current']) &&
          $route['schedule_status']['current']
        ) ||
        (
          $version === 'upcoming' &&
          isset($route['schedule_status']['upcoming']) &&
          $route['schedule_status']['upcoming']
        )
      )
    ) {

      /******************************
       * Direction
       ******************************/
      $direction_options = $route['direction_options'];
      $direction = $this->requestStack->getCurrentRequest()->get('direction');
      if (empty($direction)) {
        $direction = array_keys($direction_options)[0];
      }

      /******************************
       * Day of Travel
       ******************************/
      foreach ($route['day_of_service_options'] as $day_of_service_option) {
        $day_of_travel_options[] = $day_of_service_option['description'];
      }
      $day_of_travel = $this->requestStack->getCurrentRequest()->get('day_of_travel');
      if (!in_array($day_of_travel, $day_of_travel_options)) {
        $day_of_travel = reset($day_of_travel_options);
      }

      /******************************
       * Origin & Destination
       ******************************/
      $rs_origin = $this->requestStack->getCurrentRequest()->get('rs_origin');
      $rs_destination = $this->requestStack->getCurrentRequest()->get('rs_destination');

      /******************************
       * Schedule Table.
       ******************************/
      // Set schedule PDF file properties.
      $pdf_info = [];
      $pdf_info['directory_path'] = 'public://route_schedule_pdfs/' . $version . '/route_' . $route_id . '/';
      $pdf_info['file_name'] = 'route_' . $route_id . '_schedule.pdf';
      $pdf_header = $this->t('<div lang="en" class="route-header"><h1>Route @route_id - @route_name</h1></div>', [
        '@route_id' => $route_id,
        '@route_name' => $route['route_name'],
      ]);
      $pdf_schedule_html = $pdf_header->render();

      /******************************
       * Header
       ******************************/
      $schedule_header = $this->buildHeader($route, $route_id, $version, $build_schedule_toggle, $pdf_info);
      $schedule_header_markup = '';
      foreach ($schedule_header as $item) {
        $schedule_header_markup .= $item;
      }
      $build['schedule_header'] = [
        '#type' => 'markup',
        '#markup' => $schedule_header_markup,
      ];
      $pdf_schedule_html .= '<div lang="en">' . $schedule_header['prefix'] . $schedule_header['effective_date'] . $schedule_header['suffix'] . '</div>';

      /******************************
       * Wrappers
       ******************************/
      $build['schedule_tab_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'schedule-tab-general-wrapper',
          ],
        ],
      ];
      $build['schedule_tab_wrapper']['tab-wrapper'] = [
        '#prefix' => '<ul role="tablist" class="tab-wrapper">',
        '#suffix' => '</ul>',
        '#markup' => '',
      ];
      $build['schedule_travel_options_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'schedule-travel-options-general-wrapper',
          ],
        ],
      ];
      $build['schedule_table_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'schedule-table-general-wrapper',
          ],
        ],
      ];

      $i = 0;
      $is_active = FALSE;
      $trip_planner_settings = [];
      foreach ($route['day_of_service_options'] as $service_id => $service) {
        if (!empty($service)) {
          foreach ($route['direction_options'] as $direction_id => $direction_value) {
            // Only display directions with set services.
            $service_variants = [];
            if (isset($service['variants']) && !empty($service['variants'])) {
              $service_variants = $service['variants'];
            }

            $schedule_table_id = strtolower($service['description']) . '-' . strtolower($direction_id);
            $schedule_table_id = str_replace(['_', '/'], '-', $schedule_table_id);

            $origin = NULL;
            $destination = NULL;
            $is_first = FALSE;
            if ($i === 0) {
              $is_first = TRUE;
            }
            // If Day of Travel and Direction query strings are set
            // check if they match with current foreach loop combination.
            if (!empty($day_of_travel) && !empty($direction)) {
              // If they match, set current combination as active.
              // Display it and set values.
              if ($service['description'] === $day_of_travel && $direction_id === $direction) {
                $origin = $rs_origin;
                $destination = $rs_destination;
                $is_active = TRUE;
              }
              // If they do not match, current combinantion is not active.
              // Hide it.
              else {
                $is_active = FALSE;
              }
            }
            // If Day of Travel and Direction are not set,
            // display and set the first combination as active.
            else {
              if ($is_first) {
                $is_active = TRUE;
              }
            }

            /******************************
             * Tabs
             ******************************/
            // Build tab.
            $tab_html = '<li class="tab tab-' . $schedule_table_id . (($is_active) ? ' active' : '') . '">';
            $tab_html .= $this->t('<a role="tab" id="@id" aria-controls="@id" aria-selected="@is_active" href="#0" data-schedule-table-id="@id" data-day="@day" data-direction="@dir_code">@day @direction</a>', [
              '@is_active' => ($is_active) ? 'true' : 'false',
              '@id' => $schedule_table_id,
              '@day' => $service['description'],
              '@dir_code' => $direction_id,
              '@direction' => $direction_value,
            ]);
            $tab_html .= '</li>';
            $build['schedule_tab_wrapper']['tab-wrapper']['#markup'] .= $tab_html;

            /******************************
             * Travel Options
             ******************************/
            $build['schedule_travel_options_wrapper']['schedule_travel_options_' . str_replace('-', '_', $schedule_table_id)] = $this->formBuilder->getForm('Drupal\vta_route_schedule\Form\VtaRouteScheduleForm', $route, $direction_id, $service_id, $service['description'], $origin, $destination, $schedule_table_id, $is_active);

            /******************************
             * Schedule Table
             ******************************/
            $schedule_info = $this->buildScheduleTable($route, $route_id, $direction_id, $service_id, $service['description'], $service_variants, $origin, $destination, $version, $build_schedule_toggle, $schedule_table_id, $is_active, $is_first);
            $build['schedule_table_wrapper']['schedule_table_' . str_replace('-', '_', $schedule_table_id)] = [
              '#type' => 'markup',
              '#markup' => $schedule_info['schedule_table_html'],
            ];

            // If current table is filtered, set Trip Information data.
            if (isset($schedule_info['origin']) && !empty($schedule_info['origin'])) {
              $trip_planner_settings = [
                'active_table' => $schedule_table_id,
                'schedule_timepoints' => $schedule_info['schedule_timepoints'],
                'trips' => $route['schedule'][$direction_id]['trips'][$service_id],
                'stops' => $route['schedule'][$direction_id]['stops'][$service_id],
                'stop_sequence' => $route['schedule'][$direction_id]['stop_sequence'][$service_id],
                'origin' => $schedule_info['origin'],
                'destination' => $schedule_info['destination'],
              ];
            }
            $pdf_schedule_html .= '<div lang="en">' . $schedule_info['schedule_pdf_html'] . '</div>';
            $i++;
          }
        }
      }

      /******************************
       * Create PDF
       ******************************/
      if (file_prepare_directory($pdf_info['directory_path'], FILE_CREATE_DIRECTORY) && !empty($pdf_schedule_html)) {
        $pdf_file_uri = $pdf_info['directory_path'] . $pdf_info['file_name'];
        if (!file_exists($pdf_file_uri)) {
          // Set PDF object.
          $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'orientation' => 'L',
            'format' => 'Letter-L',
            'default_font_size' => '9pt',
            'tempDir' => file_directory_temp(),
            'autoScriptToLang' => TRUE,
            'defaultPageNumStyle' => '1',
          ]);

          // Apply CSS.
          $css_file_uri = drupal_get_path('module', 'vta_route_schedule') . '/css/vta_route_schedule_pdf.css';
          if (file_exists($css_file_uri)) {
            $mpdf->WriteHTML(file_get_contents($css_file_uri), HTMLParserMode::HEADER_CSS);
          }

          // Create footer.
          $route_pdf_name = $route['route_name'];
          $mpdf->setFooter("Route $route_id - $route_pdf_name - Page {PAGENO}");

          // Write Route schedule tables into PDF.
          $mpdf->WriteHTML($pdf_schedule_html, HTMLParserMode::HTML_BODY);

          // Render and append fields from view mode PDF.
          // Change PDF page orientation to portrait.
          $view_mode = 'pdf' . ($version == 'upcoming' ? '_upcoming' : '');
          $pre_render_field = $this->entityManager->getViewBuilder('node')->view($node, $view_mode);
          $mpdf->AddPage('P');
          $mpdf->SetTitle('Route ' . $route_id . ' Schedule');
          $mpdf->WriteHTML($this->renderer->render($pre_render_field), HTMLParserMode::HTML_BODY);
          $mpdf->Output($pdf_file_uri, 'F');

          // Purges PDF file URL Varnish cache.
          $this->purger->purgeUrl(file_create_url($pdf_info['directory_path'] . $pdf_info['file_name']));
        }
      }

      $build['#attached']['library'][] = 'vta_route_schedule/vta_route_schedule_general';
      $build['#attached']['drupalSettings'] = [
        'schedule_time' => $this->requestStack->getCurrentRequest()->get('schedule_time'),
      ];
      if (!empty($rs_origin)) {
        $build['#attached']['library'][] = 'vta_route_schedule/moment';
        $build['#attached']['library'][] = 'vta_route_schedule/vta_route_schedule_trip_planner';
        $build['#attached']['drupalSettings'] = $trip_planner_settings;
      }
      $build['#attached']['drupalSettings']['lightbox_interactive'] = TRUE;
      $build['#attached']['drupalSettings']['route_shapes'] = $route['shapes'];
      $build['#attached']['drupalSettings']['route_color'] = $route['route_color'];
    }
    else {
      $schedule_table_html = '<div class="schedule-table-introduction-header-wrapper">';
      $schedule_table_html = '<div class="schedule-table-introduction-header">';
      $schedule_table_html .= '<h2 class="schedule-table-introduction">' . $this->t('Schedule') . '</h2>';
      if (isset($build_schedule_toggle) && $build_schedule_toggle) {
        $schedule_table_html .= $this->buildScheduleToggle($route, $version);
      }
      $schedule_table_html .= '<div class="disclaimer-without-information">';

      if (isset($version)) {
        if ($version === 'current') {
          $schedule_table_html .= '<p>' . $this->t("There is no current schedule information for this route.") . '</p>';
        }
        elseif ($version === 'upcoming') {
          $schedule_table_html .= '<p>' . $this->t("There is no upcoming schedule information for this route.") . '</p>';
        }
      }
      // Close disclaimer-without-information.
      $schedule_table_html .= '</div>';
      // Close schedule-table-introduction-header.
      $schedule_table_html .= '</div>';
      // Close schedule-table-introduction-header-wrapper.
      $schedule_table_html .= '</div>';
      $build['schedule_table'] = [
        '#markup' => $schedule_table_html,
      ];
    }

    return $build;
  }

  /**
   * Helper function to build Schedule header.
   *
   * @param array $route
   *   Route data.
   * @param string $route_id
   *   Route ID.
   * @param string $version
   *   Schedule version.
   * @param bool $build_schedule_toggle
   *   Whether or not to run the buildScheduleToggle() function.
   * @param array $pdf_info
   *   PDF information including path and file name.
   *
   * @return array
   *   Schedule header HTML.
   */
  protected function buildHeader(array $route, $route_id, $version, $build_schedule_toggle, array $pdf_info) {
    $schedule_header_html = [];

    /******************************
     * Header Wrapper
     * - Introduction
     * - Header
     * - PDF
     ******************************/
    $schedule_header_html['prefix'] = '<div class="schedule-table-introduction-header">';

    /******************************
     * Introduction
     ******************************/
    $schedule_header_html['main_title'] = '<h2 class="schedule-table-introduction">' . $this->t('Schedule') . '</h2>';

    if (
      isset($route['effective_dates']) &&
      isset($route['effective_dates']['start_date']) &&
      !empty($route['effective_dates']['start_date'])
    ) {
      $effective_date = $route['effective_dates']['start_date'];
      $schedule_header_html['effective_date'] = '<p class="schedule-effective-date">(' . $this->t('Effective Date:') . ' ' . date('F d, Y', $effective_date) . ')</p>';
    }

    /******************************
     * Schedule Toggle
     ******************************/
    if ($build_schedule_toggle) {
      $schedule_header_html['schedule_toggle'] = $this->buildScheduleToggle($route, $version);
    }

    /******************************
     * Disclaimer
     ******************************/
    $url = Url::fromRoute('entity.node.canonical', ['node' => 13371])->toString();
    $schedule_header_html['disclaimer'] = '<p class="disclaimer-with-information"><a href="' . $url . '">' . $this->t("Don't see your stop listed?") . '</a>';
    $schedule_header_html['disclaimer'] .= ' ' . $this->t('Plan to arrive at the stop or station at least five (5) minutes prior to the bus or train arrival time (all times are approximate).');
    $schedule_header_html['disclaimer'] .= ' ' . $this->t('Rapid buses may depart up to five minutes earlier than the time shown, if traffic allows.') . '</p>';

    /******************************
     * PDF
     ******************************/
    $schedule_header_html['pdf'] = '<div class="route-schedule-pdf-wrapper">';
    $file_url = file_create_url($pdf_info['directory_path'] . $pdf_info['file_name']);
    $schedule_header_html['pdf'] .= '<a id="route-schedule-pdf" class="btn btn-primary" href="' . $file_url . '" target="_blank">' . $this->t('PDF') . '</a>';
    $schedule_header_html['pdf'] .= '</div>';

    // Close the schedule-table-wrapper.
    $schedule_header_html['suffix'] = '</div>';

    return $schedule_header_html;
  }

  /**
   * Helper function to create the schedule toggle HTML.
   *
   * @param array $route
   *   Route data.
   * @param string $version
   *   Schedule version.
   *
   * @return string
   *   Schedule toggle HTML.
   */
  protected function buildScheduleToggle(array $route, $version) {
    $schedule_toggle_html = '';

    $node = $this->currentRouteMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      $schedule_toggle_html = '<div class="schedule-toggle-wrapper">';
      // Get path information.
      $path_node_id = $node->id();

      // Get the Route Mapping > Current - Node ID.
      if (!empty($route['route_mapping']['current']) && count($route['route_mapping']['current']) < 3) {
        foreach ($route['route_mapping']['current'] as $key => $current_route_id) {
          // Unset any empty current.
          if (empty($current_route_id)) {
            unset($route['route_mapping']['current'][$key]);
          }
          // Use the current node route ID if it's in the list.
          if ($current_route_id === $route['route_id']) {
            $route['route_mapping']['current'] = $current_route_id;
            break;
          }
        }

        $current_node = $this->entityManager->getStorage('node')->loadByProperties([
          'type' => 'route',
          'field_route_id' => $route['route_mapping']['current'],
          'status' => 1,
        ]);

        $current_node = reset($current_node);
        if ($current_node instanceof NodeInterface) {
          $current_node_id = $current_node->id();
        }
      }

      // Get the Route Mapping > Upcoming - Node ID.
      if (!empty($route['route_mapping']['upcoming'])) {
        $upcoming_node = $this->entityManager->getStorage('node')->loadByProperties([
          'type' => 'route',
          'field_route_id' => $route['route_mapping']['upcoming'],
          'status' => 1,
        ]);
        $upcoming_node = reset($upcoming_node);
        if ($upcoming_node instanceof NodeInterface) {
          $upcoming_node_id = $upcoming_node->id();
        }
      }

      $path_options = [
        'current' => [
          'node' => !empty($current_node_id) ? $current_node_id : $path_node_id,
        ],
        'upcoming' => [
          'node' => !empty($upcoming_node_id) ? $upcoming_node_id : $path_node_id,
          'version' => 'upcoming',
        ],
      ];
      // Create schedule toggle button attributes.
      $schedule_toggle_btn_attributes = [
        'current' => [
          'class' => 'btn btn-primary btn-route-schedule-toggle' . ($version == 'current' ? ' active' : ''),
          'href' => Url::fromRoute($this->currentRouteMatch->getRouteName(), $path_options['current'])->toString(),
        ],
        'upcoming' => [
          'class' => 'btn btn-primary btn-route-schedule-toggle' . ($version == 'upcoming' ? ' active' : ''),
          'href' => Url::fromRoute($this->currentRouteMatch->getRouteName(), $path_options['upcoming'])->toString(),
        ],
      ];
      $schedule_toggle_html .= '<a class="' . $schedule_toggle_btn_attributes['current']['class'] . '" href="' . $schedule_toggle_btn_attributes['current']['href'] . '">' . $this->t('Current') . '</a>';
      $schedule_toggle_html .= '<a class="' . $schedule_toggle_btn_attributes['upcoming']['class'] . '" href="' . $schedule_toggle_btn_attributes['upcoming']['href'] . '">' . $this->t('Upcoming') . '</a>';
      $schedule_toggle_html .= '</div>';
    }

    return $schedule_toggle_html;
  }

  /**
   * Helper function to update a database entry.
   *
   * @param array $route
   *   Route data.
   * @param string $route_id
   *   Route ID.
   * @param string $direction_id
   *   Travel direction ID.
   * @param string $service_id
   *   Travel service ID.
   * @param string $service_description
   *   Travel service description.
   * @param array $service_variants
   *   Travel service variants (if any).
   * @param string $origin
   *   Travel origin.
   * @param string $destination
   *   Travel destination.
   * @param string $version
   *   Schedule version.
   * @param bool $build_schedule_toggle
   *   Whether or not to run the buildScheduleToggle() function.
   * @param string $schedule_table_id
   *   Schedule table ID.
   * @param bool $is_active
   *   Whether or not the header is active.
   * @param bool $is_first
   *   Whether or not this is the first item.
   *
   * @return string
   *   Schedule table HTML.
   */
  protected function buildScheduleTable(array $route, $route_id, $direction_id, $service_id, $service_description, array $service_variants = NULL, $origin, $destination, $version, $build_schedule_toggle, $schedule_table_id, $is_active, $is_first) {
    // Handle situation that service has variants.
    $build_variant_schedule = FALSE;
    if (isset($service_variants) && !empty($service_variants)) {
      $build_variant_schedule = TRUE;
    }

    $trips = $route['schedule'][$direction_id]['trips'][$service_id];
    $services = $route['schedule'][$direction_id]['services'][$service_id];
    $stops = $route['schedule'][$direction_id]['stops'][$service_id];
    $stop_sequence = $route['schedule'][$direction_id]['stop_sequence'][$service_id];

    if (!empty($trips) && !empty($stops)) {
      $schedule_timepoints = $this->calculateScheduleTimepoints($trips, $stops, $stop_sequence);

      /******************************
       * Only display stops with timepoints by default.
       ******************************/
      if (!isset($origin)) {
        $stops = $schedule_timepoints['timepoints'];
      }

      /******************************
       * Filter the trips by the active service
       ******************************/
      $trips = $this->filterTrips($trips, $services, $route['effective_dates'], $version);

      /******************************
       * Sort the trips by arrival time
       ******************************/
      $trips = $this->sortTrips($trips, $stops, $stop_sequence);

      /******************************
       * Get last stop_id
       ******************************/
      end($stops);
      $last_stop_id = (string) key($stops);
      reset($stops);

      if (isset($origin) && !isset($destination)) {
        $schedule_type = 'TRIP_ORIGIN_ONLY';
      }
      elseif (isset($origin) && isset($destination)) {
        $schedule_type = 'TRIP_ORIGIN_AND_DESTINATION';
      }
      else {
        $schedule_type = 'FULL';
      }

      /******************************
       * Schedule Table HTML
       * - Table
       * -- Stops (Columns)
       * -- Trips (Rows)
       ******************************/
      $schedule_table_html = '';

      /******************************
       * Table
       ******************************/
      $schedule_table_html .= '<div lang="en" class="schedule-table-wrapper' . (($is_active) ? ' active' : '') . '" data-schedule-table-id="' . $schedule_table_id . '">';
      $schedule_table_html .= '<div role="tabpanel" tabindex="0" class="schedule-table-inner-wrapper">';
      $schedule_table_html .= '<div class="schedule-table-inner-deep-wrapper">';
      $schedule_table_html .= '<table class="route-schedule" aria-label="Route ' . $route_id . ' ' . $route['direction_options'][$direction_id] . ' ' . $service_id . ' schedule" role=”table”>';

      /******************************
       * Stops (Columns)
       ******************************/
      $schedule_table_html .= '<thead role="rowgroup" class="schedule-table-stops-wrapper">';
      $schedule_table_html .= '<tr role="row" class="schedule-table-stops">';

      if ($build_variant_schedule) {
        $schedule_table_html .= '<th role="columnheader" scope="col" class="service-variants"></th>';
      }

      if ($schedule_type == 'TRIP_ORIGIN_ONLY' || $schedule_type == 'TRIP_ORIGIN_AND_DESTINATION') {
        /******************************
         * Origin             specified
         * Destination    NOT specified
         * ----------------------------
         * Destination defaults to last stop
         ******************************/
        if (!isset($destination)) {
          $destination_stop = $last_stop_id;
        }
        /******************************
         * Origin             specified
         * Destination        specified
         ******************************/
        else {
          $destination_stop = $destination;
        }

        if (isset($stops[$origin]) && isset($stops[$destination_stop])) {
          $schedule_table_html .= '<th role="columnheader" scope="col" data-stop-id="' . $origin . '">' . $stops[$origin] . '</th>';
          $schedule_table_html .= '<th scope="col" data-stop-id="' . $destination_stop . '">' . $stops[$destination_stop] . '</th>';
        }
      }
      else {
        /******************************
         * Origin         NOT specified
         * Destination    NOT specified
         ******************************/
        foreach ($stops as $stop_id => $stop_name) {
          $schedule_table_html .= '<th role="columnheader" scope="col" data-stop-id="' . $stop_id . '">' . $stop_name . '</th>';
        }
      }
      $schedule_table_html .= '</tr>';
      $schedule_table_html .= '</thead>';

      /******************************
       * Trips (Rows)
       ******************************/
      $schedule_table_html .= '<tbody role="rowgroup" class="schedule-table-trips-wrapper">';

      foreach ($trips as $trip_id => $trip) {
        if ($schedule_type == 'TRIP_ORIGIN_ONLY' || $schedule_type == 'TRIP_ORIGIN_AND_DESTINATION') {
          /******************************
           * Origin             specified
           * - Empty (use arrival_time from timepoint BEFORE)
           * Destination    NOT specified
           * - Will not be empty
           * ----------------------------
           * Destination defaults to last stop
           ******************************/
          if (!isset($destination)) {
            // Origin Arrival Time.
            if (isset($trip[$origin])) {
              if (!empty($trip[$origin])) {
                $origin_arrival_time = $trip[$origin];
              }
              elseif (
                isset($schedule_timepoints['timepoint_adjustments'][$origin]) &&
                isset($trip[$schedule_timepoints['timepoint_adjustments'][$origin]['before']])
              ) {
                $origin_arrival_time = $trip[$schedule_timepoints['timepoint_adjustments'][$origin]['before']];
              }
              else {
                continue;
              }
            }
            else {
              continue;
            }

            // Destination Arrival Time.
            $destination_stop = $last_stop_id;
            if (isset($trip[$destination_stop])) {
              $destination_arrival_time = $trip[$destination_stop];
            }
            else {
              continue;
            }
          }
          /******************************
           * Origin             specified
           * - Empty (use arrival_time from timepoint BEFORE)
           * Destination        specified
           * - Empty (use arrival_time from timepoint AFTER)
           ******************************/
          else {
            // Origin Arrival Time.
            if (isset($trip[$origin])) {
              if (!empty($trip[$origin])) {
                $origin_arrival_time = $trip[$origin];
              }
              elseif (
                isset($schedule_timepoints['timepoint_adjustments'][$origin]) &&
                isset($trip[$schedule_timepoints['timepoint_adjustments'][$origin]['before']])
                ) {
                $origin_arrival_time = $trip[$schedule_timepoints['timepoint_adjustments'][$origin]['before']];
              }
              else {
                continue;
              }
            }
            else {
              continue;
            }

            // Destination Arrival Time.
            $destination_stop = $destination;
            if (isset($trip[$destination_stop])) {
              if (!empty($trip[$destination_stop])) {
                $destination_arrival_time = $trip[$destination_stop];
              }
              elseif (
                isset($schedule_timepoints['timepoint_adjustments'][$destination_stop]) &&
                isset($trip[$schedule_timepoints['timepoint_adjustments'][$destination_stop]['after']])
                ) {
                $destination_arrival_time = $trip[$schedule_timepoints['timepoint_adjustments'][$destination_stop]['after']];
              }
              else {
                continue;
              }
            }
            else {
              continue;
            }
          }

          $schedule_table_html .= '<tr role=row scope="row" class="schedule-table-trip" data-trip-id="' . $trip_id . '">';

          // Service Variant handling.
          if (
            $build_variant_schedule &&
            isset($service_variants[$trip_id])
          ) {
            $schedule_table_html .= '<td role="cell" class="service-variant" data-service-variant-id="' . $service_variants[$trip_id]['variant_service_id'] . '">' . $service_variants[$trip_id]['description'] . '</td>';
          }

          // Origin.
          $origin_timestamp = strtotime($this->timeConvertHelper($origin_arrival_time));
          $schedule_table_html .= '<td role=”cell” data-stop-id="' . $origin . '" data-timestamp="' . $origin_timestamp . '">' . date('g:i A', $origin_timestamp) . '</td>';

          // Destination.
          $destination_timestamp = strtotime($this->timeConvertHelper($destination_arrival_time));
          $schedule_table_html .= '<td role=”cell” data-stop-id="' . $destination_stop . '" data-timestamp="' . $destination_timestamp . '">' . date('g:i A', $destination_timestamp) . '</td>';
        }
        else {
          /******************************
           * Origin         NOT specified
           * Destination    NOT specified
           ******************************/
          $schedule_table_html .= '<tr role="row" scope="row" class="schedule-table-trip" data-trip-id="' . $trip_id . '">';

          // Service Variant handling.
          if (
            $build_variant_schedule &&
            isset($service_variants[$trip_id])
          ) {
            $schedule_table_html .= '<td role="cell" class="service-variant" data-service-variant-id="' . $service_variants[$trip_id]['variant_service_id'] . '">' . $service_variants[$trip_id]['description'] . '</td>';
          }

          // Filter the trip to only have stops with
          // non-empty times and stops that are timepoints.
          $filtered_trip = [];
          foreach ($trip as $stop_id => $arrival_time) {
            if (!empty($arrival_time)) {
              foreach ($stop_sequence as $stop_sequence_info) {
                if (
                  $stop_sequence_info['stop_id'] == $stop_id &&
                  $stop_sequence_info['stop_type'] === 'timepoint' &&
                  $stop_sequence_info['timepoint_availability'] === 1
                ) {
                  $filtered_trip[$stop_id] = $arrival_time;
                  break;
                }
              }
            }
          }
          $trip = $filtered_trip;

          // Add empty cells to make up for missing stops.
          if (count($trip) < count($stops)) {
            foreach (array_keys($stops) as $stop_id) {
              if (isset($trip[$stop_id])) {
                $arrival_timestamp = strtotime($this->timeConvertHelper($trip[$stop_id]));
                $schedule_table_html .= '<td role="cell" data-stop-id="' . $stop_id . '" data-timestamp="' . $arrival_timestamp . '">' . date('g:i A', $arrival_timestamp) . '</td>';
              }
              else {
                $schedule_table_html .= '<td role="cell" data-stop-id="' . $stop_id . '" class="empty-arrival-time">-</td>';
              }
            }
          }
          else {
            foreach ($trip as $stop_id => $arrival_time) {
              $arrival_timestamp = strtotime($this->timeConvertHelper($arrival_time));
              $schedule_table_html .= '<td role="cell" data-stop-id="' . $stop_id . '" data-timestamp="' . $arrival_timestamp . '">' . date('g:i A', $arrival_timestamp) . '</td>';
            }
          }
        }

        $schedule_table_html .= '</tr>';
      }
      $schedule_table_html .= '</tbody>';

      $schedule_table_html .= '</table>';

      // Close schedule-table-inner-deep-wrapper.
      $schedule_table_html .= '</div>';
      // Close schedule-table-inner-wrapper.
      $schedule_table_html .= '</div>';
      // Close schedule-table-wrapper.
      $schedule_table_html .= '</div>';
    }

    /******************************
     * Insert data into PDF.
     ******************************/
    $direction_header_html = $this->t('<div lang="en" class="route-direction" style="@page_break"><bookmark content="@day_of_service @direction" /><h2>@day_of_service @direction</h2></div>', [
      '@page_break' => ($is_first === FALSE) ? 'page-break-before:always;' : '',
      '@day_of_service' => $service_description,
      '@direction' => $route['direction_options'][$direction_id],
    ]);
    $schedule_pdf_html = $direction_header_html->render() . $schedule_table_html;

    if (!isset($destination)) {
      $destination = $last_stop_id;
    }

    $schedule_info = [
      'schedule_table_html' => $schedule_table_html,
      'schedule_timepoints' => $schedule_timepoints,
      'schedule_pdf_html' => $schedule_pdf_html,
      'origin' => $origin,
      'destination' => $destination,
    ];

    return $schedule_info;
  }

  /**
   * Helper function to calculate schedule timepoints.
   *
   * @param array $trips
   *   Route trips.
   * @param array $stops
   *   Route stops.
   * @param array $stop_sequence
   *   Route stop sequence.
   *
   * @return array
   *   Route timepoints.
   */
  protected function calculateScheduleTimepoints(array $trips, array $stops, array $stop_sequence) {
    $schedule_timepoints = [
      'timepoints' => [],
      'timepoint_adjustments' => [],
    ];
    $previous_timepoint = '';
    $timepoints_with_time = [];

    foreach ($stop_sequence as $stop_sequence_info) {
      /******************************
       * Timepoints
       ******************************/
      if (
        $stop_sequence_info['stop_type'] === 'timepoint' &&
        $stop_sequence_info['timepoint_availability'] === 1
      ) {
        foreach ($trips as $trip) {
          if (isset($trip[$stop_sequence_info['stop_id']]) && !empty($trip[$stop_sequence_info['stop_id']])) {
            $timepoints_with_time[] = $stop_sequence_info['stop_id'];
            break;
          }
        }
      }
    }

    foreach ($stop_sequence as $stop_sequence_info) {
      /******************************
       * Timepoints
       ******************************/
      if (
        $stop_sequence_info['stop_type'] === 'timepoint' &&
        $stop_sequence_info['timepoint_availability'] === 1 &&
        in_array($stop_sequence_info['stop_id'], $timepoints_with_time)
      ) {
        $schedule_timepoints['timepoints'][$stop_sequence_info['stop_id']] = $stops[$stop_sequence_info['stop_id']];
        $previous_timepoint = $stop_sequence_info['stop_id'];
      }
      /******************************
       * Timepoint Adjustments (BEFORE)
       ******************************/
      else {
        $schedule_timepoints['timepoint_adjustments'][$stop_sequence_info['stop_id']]['before'] = $previous_timepoint;
      }
    }

    /******************************
     * Timepoint Adjustments (AFTER)
     ******************************/
    $reversed_stop_sequence = array_reverse($stop_sequence, TRUE);
    foreach ($reversed_stop_sequence as $stop_sequence_info) {
      if (
        $stop_sequence_info['stop_type'] === 'timepoint' &&
        $stop_sequence_info['timepoint_availability'] === 1 &&
        in_array($stop_sequence_info['stop_id'], $timepoints_with_time)
      ) {
        $previous_timepoint = $stop_sequence_info['stop_id'];
      }
      else {
        $schedule_timepoints['timepoint_adjustments'][$stop_sequence_info['stop_id']]['after'] = $previous_timepoint;
      }
    }

    return $schedule_timepoints;
  }

  /**
   * Helper function to filter trips by the active service.
   *
   * @param array $trips
   *   Route trips.
   * @param array $services
   *   Route services.
   * @param array $effective_dates
   *   Route effective_dates.
   * @param string $version
   *   Route schedule version.
   *
   * @return array
   *   Route trips (filtered).
   */
  protected function filterTrips(array $trips, array $services, array $effective_dates, string $version) {
    $service_interval_threshold = 5;
    $trips_to_unset = [];
    $overall_trip_count = 0;
    $closest_future_service_info = [
      'date' => '',
      'keys' => [],
    ];

    foreach ($services as $service_key => $service) {
      $overall_trip_count += count($service['trips']);

      /******************************
       * Trip Rules to not be unset:
       * - General
       * -- Interval surpasses interval threshold
       * - Current:
       * -- Today is included in service date range
       * - Upcoming:
       * -- Service start date is within effective date range
       * -- Service starts before effective start date
       *    AND
       *    Service ends after effective start date
       ******************************/
      if (
        !(
          $service['interval'] > $service_interval_threshold &&
          (
            (
              $version === 'current' &&
              (
                ($service['start_date'] <= time() && time() <= $service['end_date'])
              )
            ) ||
            (
              $version === 'upcoming' &&
              (
                ($effective_dates['start_date'] <= $service['start_date'] && $service['start_date'] <= $effective_dates['end_date']) ||
                ($service['start_date'] <= $effective_dates['start_date'] && $effective_dates['start_date'] <= $service['end_date'])
              )
            )
          )
        )
      ) {
        $trips_to_unset = array_merge($trips_to_unset, $service['trips']);

        /******************************
         * If there are no active services for current
         * - Track future service(s) with the closest future date
         * -- Ensure that they end up not being unset
         ******************************/
        if (
          $version === 'current' &&
          time() <= $service['start_date']
        ) {
          if (
            (
              empty($closest_future_service_info['date']) &&
              empty($closest_future_service_info['keys'])
            ) ||
            $service['start_date'] < $closest_future_service_info['date']
          ) {
            $closest_future_service_info['date'] = $service['start_date'];
            $closest_future_service_info['keys'] = [$service_key];
          }
          elseif ($service['start_date'] === $closest_future_service_info['date']) {
            $closest_future_service_info['keys'][] = $service_key;
          }
        }
      }
    }

    if ($overall_trip_count === count($trips_to_unset)) {
      foreach ($closest_future_service_info['keys'] as $closest_future_service_key) {
        foreach ($services[$closest_future_service_key]['trips'] as $trip_id) {
          if ($trip_id_key = array_search($trip_id, $trips_to_unset)) {
            unset($trips_to_unset[$trip_id_key]);
          }
        }
      }
    }

    // Unset Trips.
    if (!empty($trips_to_unset)) {
      foreach ($trips_to_unset as $trip_id) {
        unset($trips[$trip_id]);
      }
    }

    return $trips;
  }

  /**
   * Helper function to sort trips by arrival time.
   *
   * @param array $trips
   *   Route trips.
   * @param array $stops
   *   Route stops.
   * @param array $stop_sequence
   *   Route stop sequence.
   *
   * @return array
   *   Route trips (sorted).
   */
  protected function sortTrips(array $trips, array $stops, array $stop_sequence) {
    /******************************
     * Separate trips into:
     * - Trips to sort
     * - Trips to insert
     ******************************/
    // Get first timepoint to use when separating trips.
    foreach ($stop_sequence as $stop_sequence_info) {
      $stop_type = $stop_sequence_info['stop_type'];
      $stop_id = $stop_sequence_info['stop_id'];

      if ($stop_type === 'timepoint') {
        foreach ($trips as $trip) {
          if (isset($trip[$stop_id]) && !empty($trip[$stop_id])) {
            $first_timepoint_stop_id = $stop_id;
            break 2;
          }
        }
      }
    }

    $trips_to_sort = [];
    $trips_to_insert = [];
    foreach ($trips as $trip_id => $trip) {
      if (isset($trip[$first_timepoint_stop_id]) && !empty($trip[$first_timepoint_stop_id])) {
        $trips_to_sort[$trip_id] = $trip;
      }
      else {
        $trips_to_insert[$trip_id] = $trip;
      }
    }

    /******************************
     * Sort trips by first arrival time
     ******************************/
    $sorted_trips = [];
    foreach ($trips_to_sort as $trip_id => $trip) {
      foreach ($trip as $arrival_time) {
        $sorted_trips[$arrival_time . '__' . $trip_id] = $trip;
        break;
      }
    }
    ksort($sorted_trips);
    $trips = [];
    foreach ($sorted_trips as $mixed_id => $trip) {
      $mixed_id = explode('__', $mixed_id);
      $trips[$mixed_id[1]] = $trip;
    }

    if (!empty($trips_to_insert)) {
      /******************************
       * Insert trips based on arrival time comparison
       ******************************/
      foreach ($trips_to_insert as $trip_to_insert_id => $trip_to_insert) {
        $trip_inserted = FALSE;
        foreach ($trips as $trip_id => $trip) {
          $trip_to_insert_stop_id_list = array_keys($trip_to_insert);

          foreach ($trip_to_insert_stop_id_list as $trip_to_insert_stop_id) {
            // If the comparing Trip does not have the stop, try the next stop.
            if (empty($trip_to_insert[$trip_to_insert_stop_id]) || !isset($trip[$trip_to_insert_stop_id])) {
              continue;
            }

            // Insert the Trip before another trip based on time comparisons.
            if ($trip_to_insert[$trip_to_insert_stop_id] < $trip[$trip_to_insert_stop_id]) {
              $trips = $this->arrayInsertBefore($trip_id, $trips, $trip_to_insert_id, $trip_to_insert);
              $trip_inserted = TRUE;
              break;
            }
          }
          // Insert the Trip at the end if it hasn't already been inserted.
          if (!$trip_inserted) {
            end($trips);
            if ($trip_id == key($trips)) {
              $trips = $this->arrayInsertAfter($trip_id, $trips, $trip_to_insert_id, $trip_to_insert);
            }
          }
          else {
            continue 2;
          }
        }
      }
    }

    return $trips;
  }

  /**
   * Inserts a new key/value before the key in the array.
   *
   * Reference - http://eosrei.net/comment/287
   *
   * @param string $key
   *   The key to insert before.
   * @param array $array
   *   An array to insert in to.
   * @param string $new_key
   *   The key to insert.
   * @param string $new_value
   *   An value to insert.
   *
   * @return array|false
   *   The new array if the key exists, FALSE otherwise.
   *
   * @see array_insert_after()
   */
  protected function arrayInsertBefore($key, array $array, $new_key, $new_value) {
    if (array_key_exists($key, $array)) {
      $new = [];
      foreach ($array as $k => $value) {
        if ($k === $key) {
          $new[$new_key] = $new_value;
        }
        $new[$k] = $value;
      }
      return $new;
    }
    return FALSE;
  }

  /**
   * Inserts a new key/value after the key in the array.
   *
   * Reference - http://eosrei.net/comment/287
   *
   * @param string $key
   *   The key to insert after.
   * @param array $array
   *   An array to insert in to.
   * @param string $new_key
   *   The key to insert.
   * @param string $new_value
   *   An value to insert.
   *
   * @return array|false
   *   The new array if the key exists, FALSE otherwise.
   *
   * @see array_insert_before()
   */
  protected function arrayInsertAfter($key, array $array, $new_key, $new_value) {
    if (array_key_exists($key, $array)) {
      $new = [];
      foreach ($array as $k => $value) {
        $new[$k] = $value;
        if ($k === $key) {
          $new[$new_key] = $new_value;
        }
      }
      return $new;
    }
    return FALSE;
  }

  /**
   * Converts time 25:00:00 or above to the correct 12h time.
   *
   * @param string $time
   *   Time to check for converting.
   *
   * @return string
   *   Time or converted time.
   */
  protected function timeConvertHelper($time) {
    if (!strtotime($time)) {
      $time_hour = substr($time, 0, 2);
      $new_time_hour = (string) (int) $time_hour - 24;
      $time = str_replace($time_hour, $new_time_hour, $time);
    }
    return $time;
  }

}
