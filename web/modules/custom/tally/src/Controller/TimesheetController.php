<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tally\Dates;
use Drupal\tally\TimeScreenBuilder;
use Drupal\tally\TimeTracker;
use Drupal\tally\Timesheet;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Saves a timesheet cell. Returns the refreshed Time screen, from which the
 * cell's own HTMX attributes pick the parts that changed.
 */
class TimesheetController extends ControllerBase {

  public function __construct(
    protected TimeScreenBuilder $builder,
    protected TimeTracker $tracker,
    protected Timesheet $timesheet,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.time_screen'), $container->get('tally.time_tracker'), $container->get('tally.timesheet'));
  }

  /**
   * Sets one project's hours for one day.
   *
   * A value that is not a duration is not saved; the cell comes back marked
   * as invalid with what was typed.
   */
  public function cell(Request $request): array {
    $options = $this->builder->options($request);
    $project_id = (string) $request->request->get('project');
    $day_text = (string) $request->request->get('day');
    $value = (string) $request->request->get('value');
    $project = $project_id !== '' && $project_id !== '0' ? $this->builder->loadOwnedProject($project_id) : NULL;
    $day = Dates::parse($day_text, $options['day']->getTimezone()) ?? FALSE;
    $week_start = $options['day']->modify('monday this week');
    if (!$day || $day < $week_start || $day >= $week_start->modify('+7 days') || ($project_id !== '0' && !$project)) {
      throw new BadRequestHttpException();
    }

    $invalid = [];
    $seconds = Timesheet::parse($value);
    if ($seconds === NULL) {
      $invalid[($project ? $project->id() : 0) . '-' . $day->format('Y-m-d')] = $value;
    }
    else {
      $entries = $this->tracker->getEntries($this->currentUser(), $day->getTimestamp(), $day->modify('+1 day')->getTimestamp());
      $this->timesheet->setDayTotal($this->currentUser(), $project, $day, $seconds, $entries);
    }
    return $this->builder->build($options, NULL, TRUE, $invalid);
  }

}
