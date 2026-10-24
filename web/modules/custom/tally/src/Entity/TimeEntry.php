<?php

namespace Drupal\tally\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\tally\TallyAccessControlHandler;
use Drupal\tally\TallyListBuilder;

/**
 * Defines the Time entry entity.
 */
#[ContentEntityType(
  id: 'tally_time_entry',
  label: new TranslatableMarkup('Time entry'),
  label_collection: new TranslatableMarkup('Time entries'),
  label_singular: new TranslatableMarkup('time entry'),
  label_plural: new TranslatableMarkup('time entries'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'description',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => TallyAccessControlHandler::class,
    'list_builder' => TallyListBuilder::class,
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: ['collection' => '/admin/content/tally/time-entries'],
  admin_permission: 'administer tally',
  base_table: 'tally_time_entry',
  label_count: [
    'singular' => '@count time entry',
    'plural' => '@count time entries',
  ],
)]
class TimeEntry extends TallyEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['description'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setSetting('max_length', 255);
    $fields['project'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Project'))
      ->setSetting('target_type', 'tally_project');
    $fields['start'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Start'))
      ->setRequired(TRUE);
    // Empty while the timer is running.
    $fields['end'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('End'));
    $fields['billable'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Billable'))
      ->setDefaultValue(TRUE);
    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'));

    return $fields;
  }

  /**
   * Whether the timer is still running.
   */
  public function isRunning(): bool {
    return $this->get('end')->isEmpty();
  }

  /**
   * Duration in seconds; a running entry counts up to now.
   */
  public function getDuration(?int $now = NULL): int {
    $end = $this->isRunning() ? ($now ?? \Drupal::time()->getRequestTime()) : (int) $this->get('end')->value;
    return max(0, $end - (int) $this->get('start')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    if ($this->isRunning()) {
      $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $this->getOwnerId())
        ->notExists('end');
      if (!$this->isNew()) {
        $query->condition('id', $this->id(), '<>');
      }
      if ($query->range(0, 1)->execute()) {
        throw new EntityStorageException('Only one time entry can be running per user.');
      }
    }
  }

}
