<?php

namespace Drupal\tally\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\tally\TallyAccessControlHandler;
use Drupal\tally\TallyListBuilder;

/**
 * Defines the Project entity.
 */
#[ContentEntityType(
  id: 'tally_project',
  label: new TranslatableMarkup('Project'),
  label_collection: new TranslatableMarkup('Projects'),
  label_singular: new TranslatableMarkup('project'),
  label_plural: new TranslatableMarkup('projects'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => TallyAccessControlHandler::class,
    'list_builder' => TallyListBuilder::class,
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: ['collection' => '/admin/content/tally/projects'],
  admin_permission: 'administer tally',
  base_table: 'tally_project',
  label_count: [
    'singular' => '@count project',
    'plural' => '@count projects',
  ],
)]
class Project extends TallyEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);
    $fields['client'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Client'))
      ->setSetting('target_type', 'tally_client');
    $fields['color'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Color'))
      ->setSetting('max_length', 7)
      ->setDefaultValue('#3451D1');
    // Whether the project is billed at all. Off means "Not billable".
    $fields['billable'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Billable'))
      ->setDefaultValue(TRUE);
    // Empty means "use the client's default rate".
    $fields['hourly_rate'] = static::centsField('Hourly rate (cents)');
    // On means the project's hours stay out of the Today / This week totals
    // (lunch, a personal project). Entries are still tracked and listed.
    $fields['exclude_from_totals'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Leave out of totals'))
      ->setDefaultValue(FALSE);
    $fields['archived'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Archived'))
      ->setDefaultValue(FALSE);

    return $fields;
  }

  /**
   * The client this project belongs to.
   */
  public function getClient(): ?Client {
    return $this->get('client')->entity;
  }

  /**
   * Whether the project is billed at all.
   */
  public function isBillable(): bool {
    return (bool) $this->get('billable')->value;
  }

  /**
   * Whether the project's hours are left out of the daily and weekly totals.
   */
  public function isExcludedFromTotals(): bool {
    return (bool) $this->get('exclude_from_totals')->value;
  }

  /**
   * How the rate is set: 'none', 'custom' (project rate) or 'client'.
   */
  public function getRateMode(): string {
    if (!$this->isBillable()) {
      return 'none';
    }
    return $this->get('hourly_rate')->isEmpty() ? 'client' : 'custom';
  }

  /**
   * Rate in cents: the project's own, else the client's default.
   *
   * 0 means there is no rate, either because the project is not billable or
   * because nothing has been set yet.
   */
  public function getEffectiveRate(): int {
    return match ($this->getRateMode()) {
      'custom' => (int) $this->get('hourly_rate')->value,
      'client' => $this->getClient()?->getHourlyRate() ?? 0,
      default => 0,
    };
  }

  /**
   * Whether the project is archived.
   */
  public function getColor(): string {
    return static::safeColor((string) $this->get('color')->value);
  }

  /**
   * A color that is safe to print into CSS: "#RRGGBB", else the default indigo.
   */
  public static function safeColor(string $color): string {
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#3451D1';
  }

  public function isArchived(): bool {
    return (bool) $this->get('archived')->value;
  }

}
