<?php

namespace Drupal\tally;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\tally\Entity\Client;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the render arrays for the Clients screen, its edit dialog and its report.
 */
class ClientScreenBuilder extends ListScreenBuilder {

  protected const ROUTE = 'tally.clients';

  public function __construct(
    protected ProjectReport $report,
    protected ProjectScreenBuilder $projects,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected Settings $settings,
    protected OwnerScope $owner,
  ) {}

  /**
   * Loads one of the current user's clients, or NULL.
   */
  public function loadClient(?string $id): ?Client {
    $client = $this->owner->load('tally_client', $id);
    return $client instanceof Client && $client->access('update') ? $client : NULL;
  }

  /**
   * The edit form's values as posted, or as stored when nothing was posted.
   *
   * @return array{name: string, first_name: string, last_name: string, email: string, attention: string, street: string, city: string, region: string, postal_code: string, rate: string, currency: string, language: string}
   */
  public function draft(Request $request, ?Client $client): array {
    $input = $request->request->all() + $request->query->all();
    $draft = $client ? [
      'name' => (string) $client->label(),
      'first_name' => (string) $client->get('contact_first_name')->value,
      'last_name' => (string) $client->get('contact_last_name')->value,
      'email' => (string) $client->get('billing_contact')->value,
      'attention' => (string) $client->get('attention')->value,
      'street' => (string) $client->get('address_street')->value,
      'city' => (string) $client->get('address_city')->value,
      'region' => (string) $client->get('address_region')->value,
      'postal_code' => (string) $client->get('address_postal_code')->value,
      'rate' => $client->getHourlyRate() > 0 ? Money::plain($client->getHourlyRate()) : '',
      'currency' => $client->getCurrency(),
      'language' => $client->getLanguage(),
    ] : ['name' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'attention' => '', 'street' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'rate' => '', 'currency' => $this->settings->get('currency'), 'language' => InvoiceLanguage::normalize($this->settings->get('invoice_language'))];
    if (isset($input['form'])) {
      foreach (array_keys($draft) as $key) {
        $draft[$key] = is_string($input[$key] ?? NULL) ? trim($input[$key]) : $draft[$key];
      }
      $draft['language'] = InvoiceLanguage::normalize($draft['language']);
      if (!isset(Client::CURRENCIES[$draft['currency']])) {
        $draft['currency'] = 'CAD';
      }
    }
    return $draft;
  }

  /**
   * Checks a draft. Returns messages keyed by field, empty when it is fine.
   */
  public function validate(array $draft): array {
    $errors = [];
    if ($draft['name'] === '' || mb_strlen($draft['name']) > 255) {
      $errors['name'] = $this->t('Give the client a name.');
    }
    foreach (['first_name', 'last_name', 'attention', 'street', 'city', 'region', 'postal_code'] as $key) {
      if (mb_strlen($draft[$key]) > 255) {
        $errors[$key] = $this->t('Keep it under 256 characters.');
      }
    }
    if ($draft['email'] !== '' && !filter_var($draft['email'], FILTER_VALIDATE_EMAIL)) {
      $errors['email'] = $this->t('Enter an email address like name@company.com.');
    }
    if ($draft['rate'] !== '' && Money::parse($draft['rate']) === NULL) {
      $errors['rate'] = $this->t('Enter a rate like 105 or 105.50, or leave it empty.');
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
    foreach ($this->loadClients($options) as $client) {
      $rows[] = $this->row($client, $options);
    }
    $shown = count($rows);
    $screen = [
      'options' => $options,
      'rows' => $rows,
      'count_label' => $options['status'] === 'archived'
        ? $this->formatPlural($shown, '@count archived client', '@count archived clients')
        : $this->formatPlural($shown, '@count active client', '@count active clients'),
      'empty' => $options['q'] !== '' ? $this->t('No client matches “@q”.', ['@q' => $options['q']]) : ($options['status'] === 'archived' ? $this->t('Nothing is archived.') : $this->t('No clients yet. Create your first one.')),
      'urls' => [
        'list' => $this->url($options, ['edit' => NULL, 'report' => NULL]),
        'base' => Url::fromRoute(static::ROUTE)->toString(),
        'new' => $this->url($options, ['edit' => 'new', 'report' => NULL]),
        'active' => $this->url($options, ['status' => NULL, 'edit' => NULL, 'report' => NULL]),
        'archived' => $this->url($options, ['status' => 'archived', 'edit' => NULL, 'report' => NULL]),
      ],
      'panel' => NULL,
      'report' => NULL,
    ];
    if ($options['edit'] !== NULL) {
      $client = $this->loadClient($options['edit']);
      if ($client || $options['edit'] === 'new') {
        $screen['panel'] = $this->panel($options, $client, $draft, $errors);
        // Only the first open grabs focus. Redraws (a live preview, a failed
        // save) must not steal it back from the field being used.
        $screen['panel']['autofocus'] = $screen['panel']['is_new'] && $draft === NULL;
      }
    }
    elseif ($options['report'] !== NULL && ($client = $this->loadClient((string) $options['report']))) {
      $screen['report'] = $this->reportPanel($options, $client);
    }

    return [
      '#theme' => 'tally_clients_screen',
      '#screen' => $screen,
      '#attached' => ['library' => ['core/drupal.htmx']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Just the edit dialog, for the live preview swap.
   */
  public function buildPanel(array $options, ?Client $client, array $draft): array {
    return [
      '#theme' => 'tally_client_panel',
      '#panel' => $this->panel($options, $client, $draft, []),
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The user's clients for the current tab and search.
   *
   * @return \Drupal\tally\Entity\Client[]
   */
  protected function loadClients(array $options): array {
    $storage = $this->entityTypeManager->getStorage('tally_client');
    $ids = $this->owner->query('tally_client')
      ->condition('archived', $options['status'] === 'archived' ? 1 : 0)
      ->sort('name')
      ->execute();
    $clients = [];
    foreach ($storage->loadMultiple($ids) as $client) {
      if ($options['q'] !== '' && mb_stripos((string) $client->label(), $options['q']) === FALSE && mb_stripos($client->getContactName(), $options['q']) === FALSE && mb_stripos((string) $client->get('billing_contact')->value, $options['q']) === FALSE) {
        continue;
      }
      $clients[] = $client;
    }
    return $clients;
  }

  /**
   * A client's projects, optionally leaving out archived ones.
   *
   * @return \Drupal\tally\Entity\Project[]
   */
  protected function projectsOf(Client $client, bool $with_archived): array {
    $storage = $this->entityTypeManager->getStorage('tally_project');
    $query = $this->owner->query('tally_project')->condition('client', $client->id())->sort('name');
    if (!$with_archived) {
      $query->condition('archived', 0);
    }
    return array_values($storage->loadMultiple($query->execute()));
  }

  /**
   * One row of the client table.
   */
  protected function row(Client $client, array $options): array {
    $id = (int) $client->id();
    $projects = $this->projectsOf($client, FALSE);
    $banks = array_filter(array_map(fn ($project) => $this->report->bank($project), $projects));
    $over = count(array_filter($banks, fn ($bank) => $bank['over']));
    $bank = NULL;
    if (count($banks) === 1) {
      $one = reset($banks);
      $bank = ['text' => $this->t('Bank · @left', ['@left' => $one['short']]), 'state' => $one['state']];
    }
    elseif ($banks) {
      $text = $this->formatPlural(count($banks), '1 bank', '@count banks') . ($over ? ' · ' . $this->t('@count over', ['@count' => $over]) : '');
      $worst = $over ? 'over' : (in_array('low', array_column($banks, 'state'), TRUE) ? 'low' : 'ok');
      $bank = ['text' => $text, 'state' => $worst];
    }
    $rate = $client->getHourlyRate();
    return [
      'id' => $id,
      'name' => (string) $client->label(),
      'contact' => $client->getContactName() ?: (string) $client->get('billing_contact')->value ?: NULL,
      'avatar' => $this->avatar($client),
      'rate' => $rate > 0 ? Money::rate($rate, $client->getCurrency()) : $this->t('No default rate'),
      'has_rate' => $rate > 0,
      'projects' => $this->formatPlural(count($projects), '1 project', '@count projects'),
      'bank' => $bank,
      'editing' => $options['edit'] === (string) $id,
      'reporting' => $options['report'] === $id,
      'edit_url' => $this->url($options, ['edit' => (string) $id, 'report' => NULL]),
      'report_url' => $this->url($options, ['report' => $id, 'edit' => NULL]),
    ];
  }

  /**
   * Initials and one of five tones, steady for a given client.
   */
  protected function avatar(?Client $client, string $name = ''): array {
    $name = $name !== '' ? $name : (string) $client?->label();
    $words = array_filter(preg_split('/\s+/', trim($name)) ?: [], fn ($word) => mb_strlen($word) > 2 || $word === $name);
    $initials = mb_strtoupper(implode('', array_map(fn ($word) => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
    return [
      'initials' => $initials !== '' ? $initials : mb_strtoupper(mb_substr($name, 0, 2)),
      'tone' => $client ? (int) $client->id() % 5 : 0,
    ];
  }

  /**
   * Everything the edit dialog template needs.
   */
  protected function panel(array $options, ?Client $client, ?array $draft, array $errors): array {
    $draft ??= $this->draft(new Request(), $client);
    $projects = $client ? $this->projectsOf($client, FALSE) : [];
    $rows = array_map(fn ($project) => $this->projects->describe($project), $projects);

    // Projects that follow the client's rate, for the help text under it.
    $inheriting = count(array_filter($projects, fn ($project) => $project->getRateMode() === 'client'));
    $has_rate = $draft['rate'] !== '' && (Money::parse($draft['rate']) ?? 0) > 0;
    if (!$has_rate) {
      $help = $inheriting > 0
        ? $this->formatPlural($inheriting, '1 project needs this rate. Without it, its time has no billable rate.', '@count projects need this rate. Without it, their time has no billable rate.')
        : $this->t('Leave empty if each project sets its own rate.');
    }
    else {
      $help = $inheriting > 0
        ? $this->formatPlural($inheriting, 'Used by 1 project without a rate of its own. Changing it updates it right away.', 'Used by @count projects without a rate of their own. Changing it updates them right away.')
        : $this->t('No project uses it yet. Projects with their own rate are not affected.');
    }

    $title = $draft['name'] !== '' ? $draft['name'] : ($client ? (string) $client->label() : $this->t('New client'));
    return [
      'id' => $client ? (string) $client->id() : 'new',
      'is_new' => !$client,
      'title' => $title,
      'avatar' => $this->avatar($client, $title),
      'draft' => $draft,
      'errors' => $errors,
      'currencies' => array_keys(Client::CURRENCIES),
      'languages' => InvoiceLanguage::LANGUAGES,
      'symbol' => Money::symbol($draft['currency']),
      'rate_help' => $help,
      'projects' => $rows,
      'archived' => $client?->isArchived() ?? FALSE,
      'status' => $options['status'],
      'q' => $options['q'],
      'urls' => [
        'close' => $this->url($options, ['edit' => NULL, 'report' => NULL]),
        'preview' => Url::fromRoute('tally.clients.preview')->toString(),
        'new_project' => $client ? Url::fromRoute('tally.projects', [], ['query' => ['edit' => 'new', 'client' => $client->id()]])->toString() : NULL,
      ],
    ];
  }

  /**
   * Everything the report dialog template needs.
   */
  protected function reportPanel(array $options, Client $client): array {
    $projects = $this->projectsOf($client, TRUE);
    $report = $this->report->buildFor($projects, (string) $client->label(), $options['period'], $options['from'], $options['to'], $options['kinds'], $client->getCurrency());
    $periods = [];
    foreach (ProjectReport::PERIODS as $key => $label) {
      $periods[] = ['label' => $this->t($label), 'pressed' => $key === $options['period'], 'url' => $this->url($options, ['period' => $key])];
    }
    $banks = [];
    foreach ($projects as $project) {
      if ($bank = $this->report->bank($project)) {
        $banks[] = $bank + ['name' => (string) $project->label(), 'color' => $this->projects->describe($project)['color']];
      }
    }
    return [
      'id' => 'client-report',
      'eyebrow' => $this->t('Report'),
      'name' => (string) $client->label(),
      'avatar' => $this->avatar($client),
      'report' => $report,
      'periods' => $periods,
      'banks' => $banks,
      'invoice_url' => $this->invoiceUrl(),
      'options' => $options,
      'custom' => $options['period'] === 'custom',
      'urls' => [
        'close' => $this->url($options, ['edit' => NULL, 'report' => NULL]),
        'base' => Url::fromRoute(static::ROUTE)->toString(),
      ],
    ];
  }

}
