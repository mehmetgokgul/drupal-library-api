<?php

declare(strict_types=1);

namespace Drupal\library_graphql\Plugin\GraphQL\DataProducer;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\graphql\Attribute\DataProducer;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Drupal\library_graphql\GraphQL\Response\BookResponse;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a new Book entity.
 */
#[DataProducer(
  id: 'create_book',
  name: new TranslatableMarkup('Create Book'),
  description: new TranslatableMarkup('Creates a new Book.'),
  produces: new ContextDefinition(
    data_type: 'any',
    label: new TranslatableMarkup('Book'),
  ),
  consumes: [
    'data' => new ContextDefinition(
      data_type: 'any',
      label: new TranslatableMarkup('Book data'),
    ),
  ],
)]
class CreateBook extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity.repository'),
    );
  }

  /**
   * CreateBook constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository, used to resolve UUIDs to entities.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected AccountInterface $currentUser,
    protected EntityRepositoryInterface $entityRepository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Creates a Book.
   *
   * @param array $data
   *   The submitted values for the book (BookInput).
   *
   * @return \Drupal\library_graphql\GraphQL\Response\BookResponse
   *   The response, carrying either the created book or violations.
   */
  public function resolve(array $data): BookResponse {
    $response = new BookResponse();

    if (!$this->currentUser->hasPermission('create book content')) {
      $response->addViolation($this->t('You do not have permission to create books.'));
      return $response;
    }

    $author = $this->entityRepository->loadEntityByUuid('node', $data['authorId']);
    if (!$author || $author->bundle() !== 'author') {
      $response->addViolation($this->t('Author @id was not found.', ['@id' => $data['authorId']]));
      return $response;
    }

    $genreIds = [];
    foreach ($data['genre'] ?? [] as $genreUuid) {
      $term = $this->entityRepository->loadEntityByUuid('taxonomy_term', $genreUuid);
      if (!$term || $term->bundle() !== 'genre') {
        $response->addViolation($this->t('Genre @id was not found.', ['@id' => $genreUuid]));
        return $response;
      }
      $genreIds[] = $term->id();
    }

    $values = [
      'type' => 'book',
      'title' => $data['title'],
      'field_author' => $author->id(),
      'field_genre' => $genreIds,
      'field_publish_year' => $data['publishYear'],
    ];
    if (!empty($data['summary'])) {
      $values['field_summary'] = $data['summary'];
    }

    $node = Node::create($values);

    $violations = $node->validate();
    if (count($violations) > 0) {
      foreach ($violations as $violation) {
        $response->addViolation($violation->getMessage());
      }
      return $response;
    }

    $node->save();
    $response->setBook($node);
    return $response;
  }

}
