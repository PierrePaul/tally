<?php

namespace Drupal\tally;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Storage schema of the Invoice entity: one invoice number per owner, one share token per invoice.
 */
class InvoiceStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE) {
    $schema = parent::getEntitySchema($entity_type, $reset);
    // Drafts have no number yet (NULL), and NULLs do not collide.
    $schema['tally_invoice']['unique keys']['tally_invoice__uid_number'] = ['uid', 'number'];
    // The public link's token. Drafts that never had one are NULL.
    $schema['tally_invoice']['unique keys']['tally_invoice__share_token'] = ['share_token'];
    return $schema;
  }

}
