<?php

namespace Drupal\tally;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tally\Entity\Client;
use Drupal\tally\Entity\Invoice;
use Drupal\tally\Entity\InvoiceItem;
use Drupal\tally\Entity\Project;

/**
 * Creates and changes invoices and their items.
 *
 * Only drafts can be edited. Status moves are checked here, so the screen
 * can show the buttons and trust the answer.
 */
class InvoiceManager {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected Settings $settings,
    protected OwnerScope $owner,
  ) {}

  /**
   * Loads one of the current user's invoices, or NULL.
   */
  public function load(?string $id): ?Invoice {
    $invoice = $this->owner->load('tally_invoice', $id);
    return $invoice instanceof Invoice && $invoice->access('update') ? $invoice : NULL;
  }

  /**
   * The sent invoice behind a public link token, or NULL.
   *
   * Not scoped to the current user: the visitor has none. A draft never
   * answers, whatever its token.
   */
  public function loadByToken(string $token): ?Invoice {
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('tally_invoice');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('share_token', $token)->range(0, 1)->execute();
    $invoice = $ids ? $storage->load(reset($ids)) : NULL;
    return $invoice instanceof Invoice && $invoice->getStatus() !== 'draft' ? $invoice : NULL;
  }

  /**
   * All of the user's invoices, newest number first.
   *
   * @return \Drupal\tally\Entity\Invoice[]
   */
  public function loadAll(): array {
    $storage = $this->entityTypeManager->getStorage('tally_invoice');
    $ids = $this->owner->query('tally_invoice')->sort('number', 'DESC')->sort('id', 'DESC')->execute();
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * The items of several invoices in one query.
   *
   * @param \Drupal\tally\Entity\Invoice[] $invoices
   *
   * @return array<int|string, \Drupal\tally\Entity\InvoiceItem[]>
   *   Items keyed by invoice id, in the order Invoice::getItems() gives.
   */
  public function loadItems(array $invoices): array {
    $grouped = [];
    foreach ($invoices as $invoice) {
      $grouped[$invoice->id()] = [];
    }
    if (!$grouped) {
      return $grouped;
    }
    $storage = $this->entityTypeManager->getStorage('tally_invoice_item');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('invoice', array_keys($grouped), 'IN')->sort('weight')->sort('id')->execute();
    foreach ($storage->loadMultiple($ids) as $id => $item) {
      $grouped[$item->get('invoice')->target_id][$id] = $item;
    }
    return $grouped;
  }

  /**
   * The user's clients, optionally leaving out archived ones.
   *
   * @return \Drupal\tally\Entity\Client[]
   */
  public function loadClients(string $keep = ''): array {
    $storage = $this->entityTypeManager->getStorage('tally_client');
    $ids = $this->owner->query('tally_client')->sort('name')->execute();
    return array_filter($storage->loadMultiple($ids), fn (Client $client) => !$client->isArchived() || (string) $client->id() === $keep);
  }

  /**
   * The client's projects that are not archived.
   *
   * @return \Drupal\tally\Entity\Project[]
   */
  public function projectsOf(?Client $client): array {
    if (!$client) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('tally_project');
    $ids = $this->owner->query('tally_project')->condition('client', $client->id())->condition('archived', 0)->sort('name')->execute();
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * Today in the user's time zone, as 'Y-m-d'.
   */
  public function today(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone(new \DateTimeZone($this->currentUser->getTimeZone()))->setTime(0, 0);
  }

  /**
   * Creates an empty draft for the first client, or NULL when there is none.
   */
  public function createDraft(): ?Invoice {
    $clients = $this->loadClients();
    $client = $clients ? reset($clients) : NULL;
    if (!$client) {
      return NULL;
    }
    $today = $this->today();
    $invoice = $this->entityTypeManager->getStorage('tally_invoice')->create([
      'uid' => $this->currentUser->id(),
      'client' => $client->id(),
      'issue_date' => $today->format('Y-m-d'),
      'due_date' => $today->modify('+' . (int) $this->settings->get('payment_days') . ' days')->format('Y-m-d'),
      'status' => 'draft',
      'charge_taxes' => TRUE,
      'gst_rate' => $this->settings->get('gst_rate'),
      'qst_rate' => $this->settings->get('qst_rate'),
      'language' => $client->getLanguage(),
    ]);
    $invoice->save();
    return $invoice;
  }

  /**
   * The next invoice number of a year: "2026-019".
   */
  public function nextNumber(string $year): string {
    $storage = $this->entityTypeManager->getStorage('tally_invoice');
    $ids = $this->owner->query('tally_invoice')->condition('number', $year . '-', 'STARTS_WITH')->execute();
    $max = 0;
    foreach ($storage->loadMultiple($ids) as $invoice) {
      $max = max($max, (int) substr((string) $invoice->get('number')->value, strlen($year) + 1));
    }
    return $year . '-' . sprintf('%03d', $max + 1);
  }

  /**
   * The rate to offer for a bank item: the project's own, else the client's.
   *
   * @return array{rate: int, source: string}
   *   Rate in cents (0 when none) and where it comes from: 'project',
   *   'client' or 'none'.
   */
  public function suggestRate(?Project $project, ?Client $client): array {
    if ($project && $project->getRateMode() === 'custom') {
      return ['rate' => (int) $project->get('hourly_rate')->value, 'source' => 'project'];
    }
    $client = $project?->getClient() ?? $client;
    if ($client && $client->getHourlyRate() > 0) {
      return ['rate' => $client->getHourlyRate(), 'source' => 'client'];
    }
    return ['rate' => 0, 'source' => 'none'];
  }

  /**
   * Loads one of an invoice's items, or NULL.
   */
  public function loadItem(Invoice $invoice, ?string $id): ?InvoiceItem {
    foreach ($invoice->getItems() as $item) {
      if ((string) $item->id() === (string) $id) {
        return $item;
      }
    }
    return NULL;
  }

  /**
   * Applies the editor form to a draft: header fields, then each item.
   *
   * Values that are not valid are left as they were.
   *
   * @param array $input
   *   The posted form: number, client, issue, due, tax_set/tax, items[id][field].
   *
   * @return string|null
   *   A message when the number was refused, NULL otherwise.
   */
  public function apply(Invoice $invoice, array $input): ?string {
    if ($invoice->getStatus() !== 'draft') {
      return NULL;
    }
    $this->applyDiscount($invoice, $input);
    if (is_string($input['subject'] ?? NULL)) {
      $invoice->set('subject', mb_substr(trim($input['subject']), 0, 255));
    }
    if (is_string($input['notes'] ?? NULL)) {
      $invoice->set('notes', mb_substr(trim(str_replace("\r\n", "\n", $input['notes'])), 0, 2000));
    }
    $problem = is_string($input['number'] ?? NULL) ? $this->applyNumber($invoice, trim($input['number'])) : NULL;
    $client = $invoice->get('client')->entity;
    $posted_client = is_string($input['client'] ?? NULL) ? $input['client'] : '';
    $new_client = $posted_client !== '' && $posted_client !== (string) $invoice->get('client')->target_id ? $this->ownedClient($posted_client) : NULL;
    if ($new_client) {
      $invoice->set('client', $new_client->id());
      // A client brings its own language; it can still be changed on the draft.
      $invoice->set('language', $new_client->getLanguage());
      $client = $new_client;
    }
    foreach (['issue' => 'issue_date', 'due' => 'due_date'] as $key => $field) {
      $value = $input[$key] ?? '';
      if (is_string($value) && Dates::parse($value)) {
        $invoice->set($field, $value);
      }
    }
    // Choosing another client already took that client's language.
    if (!$new_client && isset($input['language'])) {
      $invoice->set('language', InvoiceLanguage::normalize($input['language']));
    }
    if (isset($input['tax_set'])) {
      $invoice->set('charge_taxes', isset($input['tax']));
    }
    $this->useCurrentRates($invoice);
    // The entity's own constraints, behind the checks above.
    if ($message = EntityValidation::first($invoice->validate())) {
      return $problem ?? $message;
    }
    $invoice->save();

    $posted_items = is_array($input['items'] ?? NULL) ? $input['items'] : [];
    $first_project = $this->projectsOf($client)[0] ?? NULL;
    foreach ($invoice->getItems() as $item) {
      $kind = $item->get('kind')->value;
      if ($new_client) {
        // The old projects belong to the old client.
        $project = $kind === 'bank' ? $first_project : NULL;
        $item->set('project', $project?->id());
        if ($kind === 'bank') {
          $item->set('rate', $this->suggestRate($project, $client)['rate']);
        }
        $item->save();
        continue;
      }
      // "Use project rate" on a bank item: back to the suggested rate.
      if ($kind === 'bank' && (string) ($input['reset'] ?? '') === (string) $item->id()) {
        $item->set('rate', $this->suggestRate($item->get('project')->entity, $client)['rate']);
        $item->save();
        continue;
      }
      $data = $posted_items[$item->id()] ?? NULL;
      if (!is_array($data)) {
        continue;
      }
      $this->applyItem($item, $data, $client);
      if ($message = EntityValidation::first($item->validate())) {
        $problem ??= $message;
        continue;
      }
      $item->save();
    }
    return $problem;
  }

  /**
   * Sets the discount from the posted type and value. Not saved here.
   *
   * A value that cannot be read leaves the discount as it was.
   */
  protected function applyDiscount(Invoice $invoice, array $input): void {
    $kind = $input['discount_kind'] ?? NULL;
    $value = is_string($input['discount_value'] ?? NULL) ? trim($input['discount_value']) : NULL;
    if (!is_string($kind) || !isset(Invoice::DISCOUNT_KINDS[$kind]) || $value === NULL) {
      return;
    }
    if ($value === '') {
      $parsed = 0;
    }
    elseif ($kind === 'percent') {
      $parsed = preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $value, $m) ? (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0') : NULL;
      $parsed = $parsed !== NULL && $parsed <= 10000 ? $parsed : NULL;
    }
    else {
      $parsed = Money::parse($value);
    }
    if ($parsed !== NULL) {
      $invoice->set('discount_kind', $kind)->set('discount_value', $parsed);
    }
  }

  /**
   * Sets a number typed by hand (for invoices made before Tally), or clears it
   * so that sending takes the next one. Not saved here.
   *
   * @return string|null
   *   A message when the number is refused and the old one stays.
   */
  protected function applyNumber(Invoice $invoice, string $number): ?string {
    if ($number === (string) $invoice->get('number')->value) {
      return NULL;
    }
    if (mb_strlen($number) > 64) {
      return (string) $this->t('An invoice number can have at most 64 characters.');
    }
    if ($number !== '') {
      $taken = $this->owner->query('tally_invoice')
        ->condition('number', $number)
        ->condition('id', $invoice->id(), '<>')
        ->count()
        ->execute();
      if ($taken) {
        return (string) $this->t('Invoice number @number is already used.', ['@number' => $number]);
      }
    }
    $invoice->set('number', $number === '' ? NULL : $number);
    return NULL;
  }

  /**
   * Adds an empty item of a kind to a draft.
   */
  public function addItem(Invoice $invoice, string $kind): void {
    if ($invoice->getStatus() !== 'draft' || !isset(InvoiceItem::KINDS[$kind])) {
      return;
    }
    $client = $invoice->get('client')->entity;
    $weight = 0;
    foreach ($invoice->getItems() as $item) {
      $weight = max($weight, (int) $item->get('weight')->value + 1);
    }
    $values = ['uid' => $this->currentUser->id(), 'invoice' => $invoice->id(), 'kind' => $kind, 'weight' => $weight];
    if ($kind === 'bank') {
      $project = $this->projectsOf($client)[0] ?? NULL;
      $values += ['project' => $project?->id(), 'hours' => '0.00', 'rate' => $this->suggestRate($project, $client)['rate']];
    }
    elseif ($kind === 'hosting') {
      $values += ['description' => (string) $this->t('Web hosting'), 'months' => 12, 'monthly_price' => 0];
    }
    elseif ($kind === 'line') {
      $values += ['description' => '', 'item_type' => 'service', 'quantity' => '1.00', 'unit_price' => 0];
    }
    else {
      $values += ['description' => '', 'price' => 0];
    }
    $this->entityTypeManager->getStorage('tally_invoice_item')->create($values)->save();
  }

  /**
   * Removes an item from a draft.
   */
  public function removeItem(Invoice $invoice, string $item_id): void {
    if ($invoice->getStatus() === 'draft' && ($item = $this->loadItem($invoice, $item_id))) {
      $item->delete();
    }
  }

  /**
   * Sets the order of a draft's items from a comma-separated list of item ids.
   *
   * Items missing from the list keep their relative order after the listed ones.
   */
  public function reorder(Invoice $invoice, string $order): void {
    if ($invoice->getStatus() !== 'draft') {
      return;
    }
    $items = $invoice->getItems();
    $ids = array_filter(array_unique(explode(',', $order)), 'ctype_digit');
    $position = 0;
    foreach (array_merge($ids, array_diff(array_keys($items), $ids)) as $id) {
      if (!isset($items[$id])) {
        continue;
      }
      if ((int) $items[$id]->get('weight')->value !== $position) {
        $items[$id]->set('weight', $position)->save();
      }
      $position++;
    }
  }

  /**
   * Moves an invoice along: 'send', 'pay', 'unpay', 'reopen', 'cancel' or 'uncancel'.
   *
   * @param string|null $paid_date
   *   For 'pay': the day it was paid as 'Y-m-d', today when missing or invalid.
   *
   * @return string|null
   *   A message when the move is not allowed, NULL when it was made.
   */
  public function transition(Invoice $invoice, string $action, ?string $paid_date = NULL): ?string {
    $status = $invoice->getStatus();
    $to = match (TRUE) {
      $action === 'send' && $status === 'draft' => 'issued',
      $action === 'pay' && $status === 'issued' => 'paid',
      $action === 'unpay' && $status === 'paid' => 'issued',
      $action === 'reopen' && $status === 'issued' => 'draft',
      $action === 'cancel' && $status === 'issued' => 'cancelled',
      $action === 'uncancel' && $status === 'cancelled' => 'issued',
      default => NULL,
    };
    if ($to === NULL) {
      return (string) $this->t('That is not possible for this invoice.');
    }
    if ($action === 'send' && ($problem = $this->sendProblem($invoice))) {
      return $problem;
    }
    if ($action === 'send') {
      // Freeze the rates in force today; only drafts follow Settings. Before
      // the status changes, since a sent invoice is no longer touched.
      $this->useCurrentRates($invoice);
      $invoice->set('currency', $invoice->getCurrency());
      $invoice->ensureShareToken();
    }
    $invoice->set('status', $to);
    if ($action === 'pay') {
      $valid = is_string($paid_date) && Dates::parse($paid_date);
      $invoice->set('paid_date', $valid ? $paid_date : $this->today()->format('Y-m-d'));
    }
    elseif ($action === 'unpay') {
      $invoice->set('paid_date', NULL);
    }
    if ($action === 'send' && $invoice->get('number')->isEmpty()) {
      $this->saveWithNextNumber($invoice);
    }
    else {
      $invoice->save();
    }
    return NULL;
  }

  /**
   * Saves an invoice that is being sent for the first time, giving it the next
   * number of its issue year.
   *
   * The unique key on (owner, number) rejects a number taken in the meantime,
   * so a clash picks the next number and tries again.
   */
  protected function saveWithNextNumber(Invoice $invoice): void {
    $year = substr($invoice->getDate('issue_date'), 0, 4) ?: $this->today()->format('Y');
    for ($attempt = 1; ; $attempt++) {
      $invoice->set('number', $this->nextNumber($year));
      try {
        $invoice->save();
        return;
      }
      catch (EntityStorageException $e) {
        // Only a number clash is worth another try.
        if ($attempt >= 3 || !$e->getPrevious() instanceof IntegrityConstraintViolationException) {
          throw $e;
        }
      }
    }
  }

  /**
   * Why a draft cannot be sent yet, or NULL when it can.
   */
  public function sendProblem(Invoice $invoice): ?string {
    $items = $invoice->getItems();
    if (!$items) {
      return (string) $this->t('Add at least one item first.');
    }
    foreach ($items as $item) {
      if ($item->get('kind')->value === 'bank' && $item->get('project')->isEmpty()) {
        return (string) $this->t('Pick the project of each bank of hours.');
      }
    }
    // Charging GST and QST makes it a tax invoice: it must carry the registration numbers.
    if ($invoice->get('charge_taxes')->value && (!$this->settings->get('gst_number') || !$this->settings->get('qst_number'))) {
      return (string) $this->t('Add your GST and QST numbers in Settings first.');
    }
    return NULL;
  }

  /**
   * Deletes a draft and its items.
   */
  public function delete(Invoice $invoice): void {
    if ($invoice->getStatus() !== 'draft') {
      return;
    }
    foreach ($invoice->getItems() as $item) {
      $item->delete();
    }
    $invoice->delete();
  }

  /**
   * Applies one item's posted fields.
   */
  protected function applyItem(InvoiceItem $item, array $data, ?Client $client): void {
    $text = fn (string $key) => is_string($data[$key] ?? NULL) ? trim($data[$key]) : NULL;
    $cents = fn (string $key) => $text($key) === NULL ? NULL : ($text($key) === '' ? 0 : Money::parse($text($key)));
    $kind = $item->get('kind')->value;

    if (($description = $text('description')) !== NULL && $kind !== 'bank') {
      $item->set('description', mb_substr($description, 0, 255));
    }
    if (($details = $text('details')) !== NULL) {
      $item->set('details', mb_substr($details, 0, 5000));
    }
    if ($text('project') !== NULL) {
      $project = $this->ownedProject($text('project'));
      $changed = (string) $item->get('project')->target_id !== (string) $project?->id();
      if ($kind !== 'hosting') {
        $item->set('project', $project?->id());
      }
      // Choosing another project for a bank offers that project's rate.
      if ($kind === 'bank' && $changed) {
        $item->set('rate', $this->suggestRate($project, $client)['rate']);
        return;
      }
    }
    if ($kind === 'fixed' && ($price = $cents('price')) !== NULL) {
      $item->set('price', $price);
    }
    if ($kind === 'bank') {
      $hours = $text('hours');
      if ($hours === '') {
        $item->set('hours', '0.00');
      }
      elseif ($hours !== NULL && preg_match('/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $hours, $m)) {
        $item->set('hours', $m[1] . '.' . str_pad($m[2] ?? '', 2, '0'));
      }
      if (($rate = $cents('rate')) !== NULL) {
        $item->set('rate', $rate);
      }
    }
    if ($kind === 'line') {
      if (isset(InvoiceItem::TYPES[$text('item_type')])) {
        $item->set('item_type', $text('item_type'));
      }
      $quantity = $text('quantity');
      if ($quantity !== NULL && preg_match('/^(\d{1,6})(?:[.,](\d{1,2}))?$/', $quantity, $m)) {
        $item->set('quantity', $m[1] . '.' . str_pad($m[2] ?? '', 2, '0'));
      }
      if (($price = $cents('price')) !== NULL) {
        $item->set('unit_price', $price);
      }
    }
    if ($kind === 'hosting') {
      $months = $text('months');
      if ($months !== NULL && ctype_digit($months) && (int) $months > 0 && (int) $months < 1000) {
        $item->set('months', (int) $months);
      }
      if (($price = $cents('price')) !== NULL) {
        $item->set('monthly_price', $price);
      }
    }
  }

  /**
   * Copies the rates from Settings onto a draft, so a draft opened before a
   * rate change is not sent at the old rates. Does nothing once it is sent.
   */
  protected function useCurrentRates(Invoice $invoice): void {
    if ($invoice->getStatus() === 'draft') {
      $invoice->set('gst_rate', $this->settings->get('gst_rate'));
      $invoice->set('qst_rate', $this->settings->get('qst_rate'));
    }
  }

  protected function ownedClient(string $id): ?Client {
    $client = $this->owner->load('tally_client', $id);
    return $client instanceof Client ? $client : NULL;
  }

  protected function ownedProject(string $id): ?Project {
    $project = $this->owner->load('tally_project', $id);
    return $project instanceof Project ? $project : NULL;
  }

}
