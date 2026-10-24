<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Url;

/**
 * Says what an htmx response does to the address bar.
 *
 * The route is an htmx route (_htmx_route), so core renders the returned build
 * array as a bare fragment, resolves CSRF placeholders and sends the headers.
 * htmx swaps in the element it asked for; HX-Push-Url tells it which address to
 * show: a URL to push it, or FALSE to leave the address bar alone.
 */
trait PushUrlTrait {

  /**
   * Adds the HX-Push-Url header to a build array.
   *
   * @param string|false $push_url
   *   A path with its query (what the builders' url() methods return), or FALSE.
   */
  protected function withPushUrl(array $build, string|false $push_url): array {
    (new Htmx())->pushUrlHeader($push_url === FALSE ? FALSE : Url::fromUserInput($push_url))->applyTo($build);
    return $build;
  }

}
