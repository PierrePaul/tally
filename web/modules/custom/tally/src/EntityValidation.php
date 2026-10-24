<?php

namespace Drupal\tally;

use Drupal\Core\Entity\EntityConstraintViolationListInterface;

/**
 * Turns an entity's constraint violations into the messages the dialogs show.
 *
 * The dialogs check what the person typed with friendly messages. This is the
 * backstop behind them: the constraints on the entity's own fields (allowed
 * values, lengths, required) run before every save.
 */
final class EntityValidation {

  /**
   * The first message per dialog field.
   *
   * @param array<string, string> $field_to_key
   *   Entity field name => the dialog's field key.
   * @param string $fallback_key
   *   Where a violation on a field with no entry in the map is shown.
   *
   * @return array<string, string>
   */
  public static function byField(EntityConstraintViolationListInterface $violations, array $field_to_key, string $fallback_key): array {
    $errors = [];
    foreach ($violations as $violation) {
      $field = explode('.', $violation->getPropertyPath())[0];
      $key = $field_to_key[$field] ?? $fallback_key;
      $errors[$key] ??= (string) $violation->getMessage();
    }
    return $errors;
  }

  /**
   * The first message, or NULL when the entity is valid.
   */
  public static function first(EntityConstraintViolationListInterface $violations): ?string {
    return count($violations) ? (string) $violations[0]->getMessage() : NULL;
  }

}
