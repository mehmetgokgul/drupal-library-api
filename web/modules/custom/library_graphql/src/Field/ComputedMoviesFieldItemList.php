<?php

namespace Drupal\library_graphql\Field;

use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Computed field: movie referencing this director via field_director.
 */
class ComputedMoviesFieldItemList extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue() {
    $director = $this->getEntity();

    if ($director->isNew()) {
      return;
    }

    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'movie')
      ->condition('field_director', $director->id())
      ->accessCheck(TRUE)
      ->execute();

    $delta = 0;
    foreach ($nids as $nid) {
      $this->list[$delta] = $this->createItem($delta, ['target_id' => $nid]);
      $delta++;
    }
  }

}