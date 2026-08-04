<?php

namespace Drupal\order_status_url\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters the guest order status route to use the configured path segment.
 *
 * The route is defined in order_status_url.routing.yml with a hard-coded
 * default path of '/order-status/{uuid}'. This subscriber rewrites that
 * path at route-build time using the value stored in
 * order_status_url.settings:path, falling back to 'order-status' if no
 * value has been configured (matching the module's shipped default).
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * Default path segment, used when no configuration value is set.
   */
  const DEFAULT_PATH = 'order-status';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new RouteSubscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $route = $collection->get('order_status_url.guest_order_status');

    if (!$route) {
      return;
    }

    $path_segment = $this->configFactory
      ->get('order_status_url.settings')
      ->get('path');

    if (empty($path_segment)) {
      $path_segment = self::DEFAULT_PATH;
    }

    $path_segment = trim($path_segment, '/');
    $route->setPath('/' . $path_segment . '/{uuid}');
  }

}
