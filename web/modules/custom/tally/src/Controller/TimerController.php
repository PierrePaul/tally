<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tally\TimeScreenBuilder;
use Drupal\tally\TimeTracker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTMX actions of the timer card. Each returns the refreshed Time screen.
 */
class TimerController extends ControllerBase {

  public function __construct(
    protected TimeScreenBuilder $builder,
    protected TimeTracker $tracker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.time_screen'), $container->get('tally.time_tracker'));
  }

  /**
   * Saves a change to the card.
   *
   * A running entry is edited in place. With no timer running the card only
   * holds a draft for the next entry, so nothing is saved: the screen is just
   * drawn again from the posted values.
   */
  public function update(Request $request): array {
    $draft = $this->builder->draftFromRequest($request);
    if ($running = $this->tracker->getRunning($this->currentUser())) {
      foreach ($draft as $field => $value) {
        $running->set($field, $value);
      }
      $running->save();
    }
    return $this->screen($request, $draft);
  }

  /**
   * Starts a timer from the card (Start, and Resume which repeats the last).
   */
  public function start(Request $request): array {
    $draft = $this->builder->draftFromRequest($request);
    if (!$this->tracker->getRunning($this->currentUser())) {
      $this->tracker->start($this->currentUser(), $draft['description'], $draft['project'], $draft['billable'], $draft['notes']);
    }
    return $this->screen($request);
  }

  /**
   * Stops the running timer.
   */
  public function stop(Request $request): array {
    if ($running = $this->tracker->getRunning($this->currentUser())) {
      $this->tracker->stop($running);
    }
    return $this->screen($request);
  }

  /**
   * The screen plus the sidebar card, which HTMX swaps in out of band.
   */
  protected function screen(Request $request, ?array $draft = NULL): array {
    return $this->builder->build($this->builder->options($request), $draft, TRUE);
  }

}
