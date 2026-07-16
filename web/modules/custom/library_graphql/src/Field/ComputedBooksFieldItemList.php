<?php

namespace Drupal\library_graphql\Field;

use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Computed field: books referencing this author via field_author.
 */
class ComputedBooksFieldItemList extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $author = $this->getEntity();

    if ($author->isNew()) {
      return;
    }

    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'book')
      ->condition('field_author', $author->id())
      ->accessCheck(TRUE)
      ->execute();

    $delta = 0;
    foreach ($nids as $nid) {
      $this->list[$delta] = $this->createItem($delta, ['target_id' => $nid]);
      $delta++;
    }
  }

}