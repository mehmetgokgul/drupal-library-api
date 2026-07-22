<?php

declare(strict_types=1);
namespace Drupal\library_graphql\Plugin\GraphQL\DataProducer;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\graphql\Attribute\DataProducer;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Drupal\library_graphql\GraphQL\Response\BookResponse;

#[DataProducer(
  id: 'book_response_book',
  name: new TranslatableMarkup('Book Response Book'),
  description: new TranslatableMarkup('Returns the book from a BookResponse.'),
  produces: new ContextDefinition(
    data_type: 'any',
    label: new TranslatableMarkup('Book'),
  ),
  consumes: [
    'response' => new ContextDefinition(
      data_type: 'any',
      label: new TranslatableMarkup('Book Response'),
    ),
  ],
)]
class BookResponseBook extends DataProducerPluginBase {

  /**
   * Resolves the book from a BookResponse.
   *
   * @param \Drupal\library_graphql\GraphQL\Response\BookResponse $response
   *   The BookResponse object.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The book entity or NULL if not available.
   */
  public function resolve(BookResponse $response): ?EntityInterface {
    return $response->book();
  }

}