<?php

declare(strict_types=1);

namespace Drupal\library_graphql\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\library_graphql\Ingest\NodeByNameResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\library_graphql\Ingest\TitleNormalizer;
use Drupal\library_graphql\Ingest\GenreSlugMapper;
use Drupal\node\Entity\Node;

#[QueueWorker(
    id: "book_ingest",
    title: new TranslatableMarkup("Book Ingest Worker"),
    )]

class BookIngestWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

    public function __construct(
        array $configuration,
        string $plugin_id,
        mixed $plugin_definition,
        protected EntityTypeManagerInterface $entityTypeManager,
        protected NodeByNameResolver $nodeByNameResolver,
    ) {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
    }
    
    public static function create( ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('entity_type.manager'),
            $container->get('library_graphql.node_by_name_resolver')
        );
    }

    public function processItem($data) {
        if ($data['title'] === '') { 
        \Drupal::logger('library_graphql')->warning("Book @id title is empty. Skipping ingestion.", ['@id' => $data['id']]);    
        return;
        }
        if ($data['author_name'] === null) {
            \Drupal::logger('library_graphql')->warning("Book @id author is empty. Skipping ingestion.", ['@id' => $data['id']]);
            return;
        }
        if (!is_numeric($data['first_publish_year'])) {
            \Drupal::logger('library_graphql')->warning("Book @id first publish year is not a valid number. Skipping ingestion.", ['@id' => $data['id']]);
            return; 
        }
        $normalizedIncoming = TitleNormalizer::normalize($data['title']);
        $storage = $this->entityTypeManager->getStorage('node');
        $ids = $storage->getQuery()
            ->condition('type', 'book')
            ->accessCheck(FALSE)
            ->execute();

        $books = $storage->loadMultiple($ids);

        $node = NULL;
        foreach ($books as $book) {
            $normalizedExisting = TitleNormalizer::normalize($book->getTitle());
            if ($normalizedIncoming === $normalizedExisting) {
                $node = $book;
                break;
            }
        }

        if ($node === NULL) {
            $node = Node::create(['type' => 'book']);
            $node->setTitle($data['title']);
        }

        $author = $this->nodeByNameResolver->findOrCreate('author', $data['author_name']);
        if ($author === NULL) {
            \Drupal::logger('library_graphql')->warning("Book @id: could not resolve or create author '@author'. Skipping ingestion.", ['@id' => $data['id'], '@author' => $data['author_name']]);
            return;
        }
        $node->set('field_author', $author);

        $termName = GenreSlugMapper::mapToTermName($data['genre']);
        if ($termName !== NULL) {
            $term = $this->nodeByNameResolver->findTermByName('genre', $termName);
            if ($term !== NULL) {
                $node->set('field_genre', [$term]);
            }
        }

        $node->set('field_publish_year', (int) $data['first_publish_year']);
        $node->set('field_summary', $data['summary']);

        $violations = $node->validate();
        if ($violations->count() > 0) {
            \Drupal::logger('library_graphql')->warning("Book @id: validation failed, not saved. @errors", [
                '@id' => $data['id'],
                '@errors' => (string) $violations,
            ]);
            return;
        }

        $node->save();
    }
}