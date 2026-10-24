<?php

namespace Drupal\tally;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Grants full access to the owner of a Tally entity, and to administrators.
 */
class TallyAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission('administer tally')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    if ($entity instanceof EntityOwnerInterface) {
      return AccessResult::allowedIf($account->hasPermission('use tally') && $entity->getOwnerId() == $account->id())
        ->cachePerPermissions()
        ->cachePerUser()
        ->addCacheableDependency($entity);
    }
    return AccessResult::neutral()->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, ['administer tally', 'use tally'], 'OR');
  }

}
