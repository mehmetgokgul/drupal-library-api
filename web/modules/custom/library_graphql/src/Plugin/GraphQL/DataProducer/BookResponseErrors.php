<?php
declare(strict_types=1);
namespace Drupal\library_graphql\Plugin\GraphQL\DataProducer;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\graphql\Attribute\DataProducer;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Drupal\graphql\GraphQL\Response\Response;

#[DataProducer(
  id: 'book_response_errors',
  name: new TranslatableMarkup('Book Response Errors'),
  description: new TranslatableMarkup('Returns the errors from a BookResponse.'),
  produces: new ContextDefinition(
    data_type: 'any',
    label: new TranslatableMarkup('Errors'),
    ),
    consumes: [
        'response' => new ContextDefinition(
            data_type: 'any',
            label: new TranslatableMarkup('Book Response'),
        ),
    ],
)]
class BookResponseErrors extends DataProducerPluginBase {
    
  /**
   * Resolves the errors from a BookResponse.
   *
   * @param \Drupal\graphql\GraphQL\Response\Response $response
   *   The BookResponse object.
   *
   * @return array
   *   An array of errors or an empty array if no errors are present.
   */
  public function resolve(Response $response): array {
    return $response->getViolations();
  }

}
