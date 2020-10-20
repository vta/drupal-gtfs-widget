<?php

namespace Drupal\vta_route_schedule\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides 'Route Navigation Block' block.
 *
 * @Block(
 *   id = "route_navigation_block",
 *   admin_label = @Translation("Route Navigation Block")
 * )
 */
class RouteNavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Provides an interface for form building and processing.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormBuilderInterface $form_builder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $build['#cache']['max-age'] = 0;
    $build['form'] = $this->formBuilder->getForm('Drupal\vta_route_schedule\Form\VtaRouteNavigationForm');

    return $build;
  }

}
