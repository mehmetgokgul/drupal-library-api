<?php

declare(strict_types=1);

namespace Drupal\library_graphql\Ingest;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

class NodeByNameResolver {

    public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

    public function findOrCreate(string $bundle, string $name): ?NodeInterface {

        $name = trim($name);

        if ($name === '') {
            return NULL;
        }

        $storage = $this->entityTypeManager->getStorage('node');

        $ids = $storage->getQuery()
            ->condition('type', $bundle)
            ->condition('title', $name)
            ->accessCheck(FALSE)
            ->range(0, 1)
            ->execute();

        if (!empty($ids)) {
            $node_id = reset($ids);
            return $storage->load($node_id);
        }

        $node = $storage->create([
            'type' => $bundle,
            'title' => $name,
        ]);

        $violations = $node->validate();
        if ($violations->count() > 0) {
            return NULL;
        }

        $node->save();
        return $node;
    }

    public function findTermByName(string $vocabulary, string $name): ?TermInterface {

        $name = trim($name);

        if ($name === '') {
            return NULL;
        }

        $storage = $this->entityTypeManager->getStorage('taxonomy_term');

        $ids = $storage->getQuery()
            ->condition('vid', $vocabulary)
            ->condition('name', $name)
            ->accessCheck(FALSE)
            ->range(0, 1)
            ->execute();

        if (empty($ids)) {
            return NULL;
        }

        $term_id = reset($ids);
        return $storage->load($term_id);
    }
}
