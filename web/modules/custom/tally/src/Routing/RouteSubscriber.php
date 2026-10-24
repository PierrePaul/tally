<?php

namespace Drupal\tally\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Renames the core user screens to match the designs.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('user.login')) {
      $route->setDefault('_title', 'Sign in');
    }
    if ($route = $collection->get('user.pass')) {
      $route->setDefault('_title', 'Reset password');
    }
  }

}
