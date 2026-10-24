<?php

namespace Drupal\tally;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\tally\Entity\Client;
use Drupal\tally\Entity\Project;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the render arrays for the Projects screen, its edit dialog and its report.
 *
 * What is open (the edit dialog, the report) lives in the URL query, so a
 * reload or a shared link shows the same thing, and every HTMX swap is just
 * "fetch this URL, keep #projects-screen".
 */
class ProjectScreenBuilder extends ListScreenBuilder {

  protected const ROUTE = 'tally.projects';

  /**
   * Project color choices from the design.
   */
  public const SWATCHES = [
    '#0E7490' => 'Teal',
    '#B45309' => 'Amber',
    '#7C3AED' => 'Violet',
    '#BE185D' => 'Raspberry',
    '#15803D' => 'Green',
    '#475569' => 'Slate',
  ];

  public function __construct(
    protected ProjectReport $report,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected OwnerScope $owner,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Adds 'client': the client a new project is being created for, if any.
   */
  public function options(Request $request): array {
    return parent::options($request) + ['client' => (string) $request->query->get('client')];
  }

  /**
   * Loads one of the current user's projects, or NULL.
   */
  public function loadProject(?string $id): ?Project {
    $project = $this->owner->load('tally_project', $id);
    return $project instanceof Project && $project->access('update') ? $project : NULL;
  }

  /**
   * The edit form's values as posted, or as stored when nothing was posted.
   *
   * @return array{name: string, color: string, client: string, mode: string, rate: string}
   */
  public function draft(Request $request, ?Project $project): array {
    $input = $request->request->all() + $request->query->all();
    $draft = $project ? [
      'name' => (string) $project->label(),
      'color' => $project->getColor(),
      'client' => (string) ($project->get('client')->target_id ?? ''),
      'mode' => $project->getRateMode(),
      'rate' => $project->get('hourly_rate')->isEmpty() ? '' : Money::plain((int) $project->get('hourly_rate')->value),
      'exclude' => $project->isExcludedFromTotals() ? '1' : '',
    ] : ['name' => '', 'color' => '#0E7490', 'client' => '', 'mode' => 'none', 'rate' => '', 'exclude' => ''];
    // "+ New project for this client" links here with ?client=ID.
    $preset = (string) $request->query->get('client');
    if (!$project && !isset($input['form']) && $preset !== '' && $this->loadClient($preset)) {
      $draft['client'] = $preset;
      $draft['mode'] = 'client';
    }
    if (isset($input['form'])) {
      foreach (array_keys($draft) as $key) {
        $draft[$key] = is_string($input[$key] ?? NULL) ? trim($input[$key]) : $draft[$key];
      }
      $draft['color'] = Project::safeColor($draft['color']);
      // A checkbox posts nothing when it is off.
      $draft['exclude'] = !empty($input['exclude']) ? '1' : '';
      if (!in_array($draft['mode'], ['client', 'custom', 'none'], TRUE)) {
        $draft['mode'] = 'none';
      }
    }
    return $draft;
  }

  /**
   * Keeps the rate mode sensible when the client changes, like the design.
   *
   * "Use client rate" makes no sense without a client, and picking a client
   * for a project that was not billable offers its rate.
   */
  public function adjustMode(array $draft, bool $client_just_changed): array {
    if ($draft['client'] === '' && $draft['mode'] === 'client') {
      $draft['mode'] = 'none';
    }
    elseif ($draft['client'] !== '' && $draft['mode'] === 'none' && $client_just_changed) {
      $draft['mode'] = 'client';
    }
    return $draft;
  }

  /**
   * Checks a draft. Returns messages keyed by field, empty when it is fine.
   */
  public function validate(array $draft): array {
    $errors = [];
    if ($draft['name'] === '' || mb_strlen($draft['name']) > 255) {
      $errors['name'] = $this->t('Give the project a name.');
    }
    if ($draft['client'] !== '' && !$this->loadClient($draft['client'])) {
      $errors['client'] = $this->t('Choose one of your clients.');
    }
    if ($draft['mode'] === 'client' && $draft['client'] === '') {
      $errors['mode'] = $this->t('Choose a client to use its rate.');
    }
    if ($draft['mode'] === 'custom' && (Money::parse($draft['rate']) ?? 0) <= 0) {
      $errors['rate'] = $this->t('Enter a project rate, like 105 or 105.50.');
    }
    return $errors;
  }

  /**
   * The whole screen.
   *
   * @param array|null $draft
   *   Unsaved values for the edit dialog (a failed save or a live preview).
   * @param array $errors
   *   Messages keyed by field name.
   */
  public function build(array $options, ?array $draft = NULL, array $errors = []): array {
    $rows = [];
    foreach ($this->loadProjects($options) as $project) {
      $rows[] = $this->row($project, $options);
    }
    $shown = count($rows);
    $count_label = $options['status'] === 'archived'
      ? $this->formatPlural($shown, '@count archived project', '@count archived projects')
      : $this->formatPlural($shown, '@count active project', '@count active projects');

    $screen = [
      'options' => $options,
      'rows' => $rows,
      'count_label' => $count_label,
      'empty' => $options['q'] !== '' ? $this->t('No project matches “@q”.', ['@q' => $options['q']]) : ($options['status'] === 'archived' ? $this->t('Nothing is archived.') : $this->t('No projects yet. Create your first one.')),
      'urls' => [
        'list' => $this->url($options, ['edit' => NULL, 'report' => NULL]),
        'base' => Url::fromRoute('tally.projects')->toString(),
        'new' => $this->url($options, ['edit' => 'new', 'report' => NULL]),
        'active' => $this->url($options, ['status' => NULL, 'edit' => NULL, 'report' => NULL]),
        'archived' => $this->url($options, ['status' => 'archived', 'edit' => NULL, 'report' => NULL]),
      ],
      'panel' => NULL,
      'report' => NULL,
    ];
    if ($options['edit'] !== NULL) {
      $project = $this->loadProject($options['edit']);
      if ($project || $options['edit'] === 'new') {
        $screen['panel'] = $this->panel($options, $project, $draft, $errors);
        // Only the first open grabs focus. Redraws (a live preview, a failed
        // save) must not steal it back from the field being used.
        $screen['panel']['autofocus'] = $screen['panel']['is_new'] && $draft === NULL;
      }
    }
    elseif ($options['report'] !== NULL && ($project = $this->loadProject((string) $options['report']))) {
      $screen['report'] = $this->reportPanel($options, $project);
    }

    return [
      '#theme' => 'tally_projects_screen',
      '#screen' => $screen,
      '#attached' => ['library' => ['core/drupal.htmx']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Just the edit dialog, for the live preview swap.
   */
  public function buildPanel(array $options, ?Project $project, array $draft): array {
    return [
      '#theme' => 'tally_project_panel',
      '#panel' => $this->panel($options, $project, $draft, []),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The user's projects for the current tab and search.
   *
   * @return \Drupal\tally\Entity\Project[]
   */
  protected function loadProjects(array $options): array {
    $storage = $this->entityTypeManager->getStorage('tally_project');
    $ids = $this->owner->query('tally_project')
      ->condition('archived', $options['status'] === 'archived' ? 1 : 0)
      ->sort('name')
      ->execute();
    $projects = [];
    foreach ($storage->loadMultiple($ids) as $project) {
      if ($options['q'] !== '') {
        $client = $project->getClient();
        if (mb_stripos((string) $project->label(), $options['q']) === FALSE && mb_stripos((string) $client?->label(), $options['q']) === FALSE) {
          continue;
        }
      }
      $projects[] = $project;
    }
    return $projects;
  }

  /**
   * One row of the project table.
   */
  protected function row(Project $project, array $options): array {
    $id = (int) $project->id();
    return $this->describe($project) + [
      'id' => $id,
      'editing' => $options['edit'] === (string) $id,
      'reporting' => $options['report'] === $id,
      'edit_url' => $this->url($options, ['edit' => (string) $id, 'report' => NULL]),
      'report_url' => $this->url($options, ['report' => $id, 'edit' => NULL]),
    ];
  }

  /**
   * What a project looks like in a list: color, client, rate, source, bank.
   *
   * The client dialog reuses this for its list of projects.
   */
  public function describe(Project $project): array {
    $client = $project->getClient();
    $currency = $client ? $client->getCurrency() : 'CAD';
    $mode = $project->getRateMode();
    $rate = $project->getEffectiveRate();
    $kind = $mode === 'none' ? 'none' : ($rate > 0 ? $mode : 'missing');
    $badges = [
      'custom' => $this->t('Project rate'),
      'client' => $this->t('From client'),
      'none' => $this->t('Not billable'),
      'missing' => $this->t('Needs a rate'),
    ];
    $bank = $this->report->bank($project);
    return [
      'name' => (string) $project->label(),
      'color' => $project->getColor(),
      'client' => $client ? (string) $client->label() : NULL,
      'rate' => $rate > 0 ? Money::rate($rate, $currency) : '-',
      'badge' => $badges[$kind],
      'badge_kind' => $kind,
      'bank' => $bank ? ['text' => $this->t('Bank · @left', ['@left' => $bank['short']]), 'state' => $bank['state'], 'over' => $bank['over']] : NULL,
    ];
  }

  /**
   * Everything the edit dialog template needs.
   */
  protected function panel(array $options, ?Project $project, ?array $draft, array $errors): array {
    $draft ??= $this->draft(new Request(['client' => $options['client'] ?? '']), $project);
    $client = $draft['client'] !== '' ? $this->loadClient($draft['client']) : NULL;
    $client_rate = $client ? $client->getHourlyRate() : 0;

    $client_options = [['value' => '', 'label' => $this->t('No client'), 'selected' => $draft['client'] === '']];
    foreach ($this->loadClients($draft['client']) as $candidate) {
      $client_options[] = ['value' => (string) $candidate->id(), 'label' => (string) $candidate->label(), 'selected' => $draft['client'] === (string) $candidate->id()];
    }

    $modes = [
      ['key' => 'client', 'title' => $this->t('Use client rate'), 'disabled' => !$client,
        'desc' => $client ? ($client_rate > 0 ? $client->label() . ' · ' . Money::rate($client_rate, $client->getCurrency()) : $this->t('@client has no default rate yet', ['@client' => $client->label()])) : $this->t('Choose a client first')],
      ['key' => 'custom', 'title' => $this->t('Project rate'), 'disabled' => FALSE,
        'desc' => $client ? $this->t('Overrides the client rate for this project only') : $this->t('A rate set on this project only')],
      ['key' => 'none', 'title' => $this->t('Not billable'), 'disabled' => FALSE,
        'desc' => $this->t('Time is tracked but never billed')],
    ];
    foreach ($modes as &$mode) {
      $mode['checked'] = $draft['mode'] === $mode['key'];
    }
    unset($mode);

    $swatches = [];
    $colors = static::SWATCHES;
    if (!isset($colors[$draft['color']])) {
      $colors[$draft['color']] = $this->t('Current color');
    }
    foreach ($colors as $color => $name) {
      $swatches[] = ['color' => $color, 'label' => $this->t((string) $name), 'checked' => strcasecmp($color, $draft['color']) === 0];
    }

    $id = $project ? (string) $project->id() : 'new';
    return [
      'id' => $id,
      'is_new' => !$project,
      'title' => $draft['name'] !== '' ? $draft['name'] : ($project ? (string) $project->label() : $this->t('New project')),
      'draft' => $draft,
      'errors' => $errors,
      'swatches' => $swatches,
      'client_options' => $client_options,
      'modes' => $modes,
      'show_rate' => $draft['mode'] === 'custom',
      'currency' => $client ? $client->getCurrency() : 'CAD',
      'symbol' => Money::symbol($client ? $client->getCurrency() : 'CAD'),
      'summary' => $this->summary($draft, $client),
      'archived' => $project?->isArchived() ?? FALSE,
      'status' => $options['status'],
      'q' => $options['q'],
      'urls' => [
        'close' => $this->url($options, ['edit' => NULL, 'report' => NULL]),
        'preview' => Url::fromRoute('tally.projects.preview')->toString(),
      ],
    ];
  }

  /**
   * The "Billed at" box: what the draft's rate settings come to.
   */
  protected function summary(array $draft, ?Client $client): array {
    $client_rate = $client ? $client->getHourlyRate() : 0;
    $rate = Money::parse($draft['rate']);
    if ($draft['mode'] === 'custom' && $rate !== NULL && $rate > 0) {
      $text = $client
        ? ($client_rate > 0 ? $this->t('Project rate. Overrides @client at @rate.', ['@client' => $client->label(), '@rate' => Money::rate($client_rate, $client->getCurrency())]) : $this->t('Project rate. @client has no default rate.', ['@client' => $client->label()]))
        : $this->t('Project rate. No client bound.');
      return ['state' => 'custom', 'title' => Money::rate($rate, $client?->getCurrency() ?? 'CAD'), 'text' => $text];
    }
    if ($draft['mode'] === 'client' && $client && $client_rate > 0) {
      return ['state' => 'client', 'title' => Money::rate($client_rate, $client->getCurrency()), 'text' => $this->t('Inherited from @client. If the client rate changes, this project follows.', ['@client' => $client->label()])];
    }
    if ($draft['mode'] === 'none') {
      return ['state' => 'none', 'title' => $this->t('Not billable'), 'text' => $this->t('Hours are tracked but left out of billable totals.')];
    }
    return [
      'state' => 'missing',
      'title' => $this->t('No rate yet'),
      'text' => $draft['mode'] === 'custom' ? $this->t('Enter a project rate above.') : $this->t('@client has no default rate. Add one on the client, or set a project rate.', ['@client' => $client ? $client->label() : $this->t('This client')]),
    ];
  }

  /**
   * Everything the report dialog template needs.
   */
  protected function reportPanel(array $options, Project $project): array {
    $client = $project->getClient();
    $report = $this->report->buildFor([$project], (string) $project->label(), $options['period'], $options['from'], $options['to'], $options['kinds'], $client ? $client->getCurrency() : 'CAD');
    $periods = [];
    foreach (ProjectReport::PERIODS as $key => $label) {
      $periods[] = [
        'label' => $this->t($label),
        'pressed' => $key === $options['period'],
        'url' => $this->url($options, ['period' => $key]),
      ];
    }
    $bank = $this->report->bank($project);
    return [
      'id' => 'project-report',
      'eyebrow' => $this->t('Report'),
      'name' => (string) $project->label(),
      'avatar' => NULL,
      'report' => $report,
      'periods' => $periods,
      'banks' => $bank ? [$bank + ['name' => NULL, 'color' => NULL]] : [],
      'invoice_url' => $this->invoiceUrl(),
      'options' => $options,
      'custom' => $options['period'] === 'custom',
      'urls' => [
        'close' => $this->url($options, ['edit' => NULL, 'report' => NULL]),
        'base' => Url::fromRoute('tally.projects')->toString(),
      ],
    ];
  }

  protected function loadClient(string $id): ?Client {
    $client = $this->owner->load('tally_client', $id);
    return $client instanceof Client ? $client : NULL;
  }

  /**
   * The user's clients, hiding archived ones unless one is already chosen.
   *
   * @return \Drupal\tally\Entity\Client[]
   */
  protected function loadClients(string $keep): array {
    $storage = $this->entityTypeManager->getStorage('tally_client');
    $ids = $this->owner->query('tally_client')->sort('name')->execute();
    return array_filter($storage->loadMultiple($ids), fn (Client $client) => !$client->isArchived() || (string) $client->id() === $keep);
  }


}
