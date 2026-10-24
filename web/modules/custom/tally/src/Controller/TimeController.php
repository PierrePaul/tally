<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tally\TimeScreenBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The Time screen, and the calendar fragment HTMX refreshes.
 */
class TimeController extends ControllerBase {

  public function __construct(protected TimeScreenBuilder $builder) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.time_screen'));
  }

  /**
   * The full screen. HTMX navigation asks for it too and takes #time-screen.
   */
  public function page(Request $request): array {
    return $this->builder->build($this->builder->options($request));
  }

  /**
   * Just the day calendar, so the running block and now-line can refresh.
   */
  public function calendar(Request $request): array {
    return $this->builder->buildCalendar($this->builder->options($request));
  }

}
