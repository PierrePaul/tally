<?php

namespace Drupal\tally;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Reads only the signed-in user's own entities.
 *
 * Every screen is private to its owner, so every query and every load by id
 * goes through here: one place holds the owner condition, and a screen cannot
 * forget it.
 */
class OwnerScope {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * An entity query limited to one user's entities (the current user by default).
   *
   * Access checks are off on purpose: ownership is the rule here, and the
   * owner condition below is what enforces it.
   */
  public function query(string $entity_type_id, ?int $uid = NULL): QueryInterface {
    return $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid ?? $this->currentUser->id());
  }

  /**
   * One entity by id, or NULL when the id is not a number or the entity
   * belongs to someone else.
   */
  public function load(string $entity_type_id, ?string $id, ?int $uid = NULL): ?EntityInterface {
    if ($id === NULL || !ctype_digit($id)) {
      return NULL;
    }
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    return $entity && $entity->getOwnerId() == ($uid ?? $this->currentUser->id()) ? $entity : NULL;
  }

}
