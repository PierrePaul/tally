<?php

namespace Drupal\tally\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tally\EntityValidation;
use Drupal\tally\Money;
use Drupal\tally\ProjectScreenBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Projects screen and the HTMX requests that change it.
 */
class ProjectController extends ControllerBase {

  use PushUrlTrait;

  public function __construct(protected ProjectScreenBuilder $builder) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.project_screen'));
  }

  /**
   * The full screen. HTMX navigation asks for it too and takes #projects-screen.
   */
  public function page(Request $request): array {
    $options = $this->builder->options($request);
    $this->assertFound($options);
    return $this->builder->build($options);
  }

  /**
   * The edit dialog redrawn from the form's current values.
   *
   * The client and rate fields call this whenever they change, so the rate
   * options and the "Billed at" box stay in step without a full save.
   */
  public function preview(Request $request): array {
    $options = $this->builder->options($request);
    $project = $this->builder->loadProject($options['edit']);
    if ($options['edit'] !== 'new' && !$project) {
      throw new NotFoundHttpException();
    }
    // htmx names the element that made the request in this header.
    $client_changed = $request->headers->get('HX-Trigger-Name') === 'client';
    $draft = $this->builder->adjustMode($this->builder->draft($request, $project), $client_changed);
    return $this->builder->buildPanel($options, $project, $draft);
  }

  /**
   * Saves the edit dialog. Invalid values reopen it with messages.
   */
  public function save(Request $request): array {
    $options = $this->builder->options($request);
    $project = $this->builder->loadProject($options['edit']);
    if ($options['edit'] !== 'new' && !$project) {
      throw new NotFoundHttpException();
    }
    $draft = $this->builder->adjustMode($this->builder->draft($request, $project), FALSE);
    $errors = $this->builder->validate($draft);
    if ($errors) {
      // Stay on the dialog: keep the address bar as it is.
      return $this->withPushUrl($this->builder->build($options, $draft, $errors), FALSE);
    }

    if (!$project) {
      $project = $this->entityTypeManager()->getStorage('tally_project')->create(['uid' => $this->currentUser()->id()]);
    }
    $project->set('name', $draft['name']);
    $project->set('color', $draft['color']);
    $project->set('client', $draft['client'] !== '' ? $draft['client'] : NULL);
    $project->set('billable', $draft['mode'] !== 'none');
    $project->set('exclude_from_totals', $draft['exclude'] !== '');
    $project->set('hourly_rate', $draft['mode'] === 'custom' ? Money::parse($draft['rate']) : NULL);
    // The entity's own constraints, behind the friendlier checks above.
    $errors = EntityValidation::byField($project->validate(), ['name' => 'name', 'client' => 'client', 'hourly_rate' => 'rate'], 'name');
    if ($errors) {
      return $this->withPushUrl($this->builder->build($options, $draft, $errors), FALSE);
    }
    $project->save();

    $options['edit'] = NULL;
    return $this->withPushUrl($this->builder->build($options), $this->builder->url($options, []));
  }

  /**
   * Archives or restores the project open in the dialog.
   */
  public function archive(Request $request): array {
    $options = $this->builder->options($request);
    $project = $this->builder->loadProject($options['edit']);
    if (!$project) {
      throw new NotFoundHttpException();
    }
    $project->set('archived', !$project->isArchived())->save();
    $options['edit'] = NULL;
    return $this->withPushUrl($this->builder->build($options), $this->builder->url($options, []));
  }

  /**
   * An edit link to a project that isn't yours is a 404.
   */
  protected function assertFound(array $options): void {
    if ($options['edit'] !== NULL && $options['edit'] !== 'new' && !$this->builder->loadProject($options['edit'])) {
      throw new NotFoundHttpException();
    }
    if ($options['report'] !== NULL && !$this->builder->loadProject((string) $options['report'])) {
      throw new NotFoundHttpException();
    }
  }

}
