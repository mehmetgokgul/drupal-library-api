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
    id: "movie_ingest",
    title: new TranslatableMarkup("Movie Ingest Worker"),
    )]

class MovieIngestWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
            \Drupal::logger('library_graphql')->warning("Movie @id title is empty. Skipping ingestion.", ['@id' => $data['id']]);
            return;
        }
        if ($data['director_name'] === null) {
            \Drupal::logger('library_graphql')->warning("Movie @id director is empty. Skipping ingestion.", ['@id' => $data['id']]);
            return;
        }
        if (!is_numeric($data['release_year'])) {
            \Drupal::logger('library_graphql')->warning("Movie @id release year is not a valid number. Skipping ingestion.", ['@id' => $data['id']]);
            return;
        }
        if (!is_numeric($data['duration_minutes'])) {
            \Drupal::logger('library_graphql')->warning("Movie @id duration is not a valid number. Skipping ingestion.", ['@id' => $data['id']]);
            return;
        }

        $normalizedIncoming = TitleNormalizer::normalize($data['title']);
        $storage = $this->entityTypeManager->getStorage('node');
        $ids = $storage->getQuery()
            ->condition('type', 'movie')
            ->accessCheck(FALSE)
            ->execute();

        $movies = $storage->loadMultiple($ids);

        $node = NULL;
        foreach ($movies as $movie) {
            $normalizedExisting = TitleNormalizer::normalize($movie->getTitle());
            if ($normalizedIncoming === $normalizedExisting) {
                $node = $movie;
                break;
            }
        }

        if ($node === NULL) {
            $node = Node::create(['type' => 'movie']);
            $node->setTitle($data['title']);
        }

        $director = $this->nodeByNameResolver->findOrCreate('director', $data['director_name']);
        if ($director === NULL) {
            \Drupal::logger('library_graphql')->warning("Movie @id: could not resolve or create director '@director'. Skipping ingestion.", ['@id' => $data['id'], '@director' => $data['director_name']]);
            return;
        }
        $node->set('field_director', $director);

        $termName = GenreSlugMapper::mapToTermName($data['genre']);
        if ($termName !== NULL) {
            $term = $this->nodeByNameResolver->findTermByName('genre', $termName);
            if ($term !== NULL) {
                $node->set('field_movie_genre', [$term]);
            }
        }

        $node->set('field_release_year', (int) $data['release_year']);
        $node->set('field_duration', (int) $data['duration_minutes']);
        $node->set('field_movie_summary', $data['summary']);

        $violations = $node->validate();
        if ($violations->count() > 0) {
            \Drupal::logger('library_graphql')->warning("Movie @id: validation failed, not saved. @errors", [
                '@id' => $data['id'],
                '@errors' => (string) $violations,
            ]);
            return;
        }

        $node->save();
    }
}
