<?php

namespace Drupal\tally;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\tally\Entity\Client;
use Drupal\tally\Entity\Invoice;
use Drupal\tally\Entity\InvoiceItem;
use Drupal\tally\Entity\Project;
use Drupal\tally\Entity\TimeEntry;
use Drupal\user\EntityOwnerInterface;

/**
 * Read-only admin table for a Tally entity type: ID, label, the columns
 * listed in columns() for that type, and owner.
 */
class TallyListBuilder extends EntityListBuilder {

  /**
   * Extra columns for the entity type: header => callable returning the cell.
   *
   * @return array<string, callable>
   */
  protected function columns(): array {
    $yes_no = fn (bool $value) => $value ? $this->t('Yes') : $this->t('No');
    $time = fn (TimeEntry $entry, string $field) => $entry->get($field)->isEmpty() ? '' : \Drupal::service('date.formatter')->format((int) $entry->get($field)->value, 'custom', 'Y-m-d H:i');
    return match ($this->entityTypeId) {
      'tally_client' => [
        'Contact' => fn (Client $client) => $client->getContactName(),
        'Email' => fn (Client $client) => $client->get('billing_contact')->value,
        'Rate' => fn (Client $client) => $client->getHourlyRate() > 0 ? Money::rate($client->getHourlyRate(), $client->getCurrency()) : '',
        'Currency' => fn (Client $client) => $client->getCurrency(),
        'Archived' => fn (Client $client) => $yes_no($client->isArchived()),
      ],
      'tally_invoice' => [
        'Client' => fn (Invoice $invoice) => $invoice->get('client')->entity?->label(),
        'Status' => fn (Invoice $invoice) => $invoice->getStatus(),
        'Issued' => fn (Invoice $invoice) => $invoice->getDate('issue_date'),
        'Due' => fn (Invoice $invoice) => $invoice->getDate('due_date'),
        'GST and QST' => fn (Invoice $invoice) => $yes_no((bool) $invoice->get('charge_taxes')->value),
        'Total' => fn (Invoice $invoice) => Money::format($invoice->getTotal(), $invoice->getCurrency()),
      ],
      'tally_time_entry' => [
        'Project' => fn (TimeEntry $entry) => $entry->get('project')->entity?->label(),
        'Start' => fn (TimeEntry $entry) => $time($entry, 'start'),
        'End' => fn (TimeEntry $entry) => $entry->isRunning() ? $this->t('Running') : $time($entry, 'end'),
        'Duration' => fn (TimeEntry $entry) => TimeTracker::formatDuration($entry->getDuration()),
        'Billable' => fn (TimeEntry $entry) => $yes_no((bool) $entry->get('billable')->value),
      ],
      'tally_project' => [
        'Client' => fn (Project $project) => $project->getClient()?->label(),
        'Color' => fn (Project $project) => $project->get('color')->value,
        'Billable' => fn (Project $project) => $yes_no($project->isBillable()),
        'Rate' => fn (Project $project) => match ($project->getRateMode()) {
          'custom' => Money::rate($project->getEffectiveRate(), $project->getClient()?->getCurrency() ?? 'CAD'),
          'client' => $this->t('Client default'),
          default => '',
        },
        'Archived' => fn (Project $project) => $yes_no($project->isArchived()),
      ],
      'tally_invoice_item' => [
        'Invoice' => fn (InvoiceItem $item) => $item->get('invoice')->entity?->label(),
        'Kind' => fn (InvoiceItem $item) => InvoiceItem::KINDS[$item->get('kind')->value] ?? $item->get('kind')->value,
        'Project' => fn (InvoiceItem $item) => $item->get('project')->entity?->label(),
        'Detail' => fn (InvoiceItem $item) => match ($item->get('kind')->value) {
          'bank' => $item->get('hours')->value . ' h x ' . Money::rate((int) $item->get('rate')->value, $this->currencyOf($item)),
          'hosting' => $item->get('months')->value . ' mo x ' . Money::format((int) $item->get('monthly_price')->value, $this->currencyOf($item)),
          'line' => $item->get('quantity')->value . ' x ' . Money::format((int) $item->get('unit_price')->value, $this->currencyOf($item)),
          default => '',
        },
        'Total' => fn (InvoiceItem $item) => Money::format($item->getTotal(), $this->currencyOf($item)),
      ],
      default => [],
    };
  }

  /**
   * The currency of the invoice's client, CAD when there is none.
   */
  protected function currencyOf(InvoiceItem $item): string {
    return $item->get('invoice')->entity?->getCurrency() ?? 'CAD';
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = ['id' => $this->t('ID'), 'label' => $this->entityType->getLabel()];
    foreach (array_keys($this->columns()) as $title) {
      $header[$title] = $this->t($title);
    }
    return $header + ['owner' => $this->t('Owner')];
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row = ['id' => $entity->id(), 'label' => $entity->label()];
    foreach ($this->columns() as $title => $cell) {
      $row[$title] = $cell($entity);
    }
    return $row + ['owner' => $entity instanceof EntityOwnerInterface ? $entity->getOwner()?->getAccountName() : ''];
  }

}
