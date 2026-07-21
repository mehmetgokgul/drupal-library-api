<?php
declare(strict_types=1);

namespace Drupal\library_graphql\GraphQL\Response;

use Drupal\Core\Entity\EntityInterface;
use Drupal\graphql\GraphQL\Response\Response;

/**
 * Response returned by Book mutations.
 *
 * Carries the created Book on success, or the inherited violations list
 * when the mutation was rejected.
 */
class BookResponse extends Response {

  /**
   * The book to be served.
   */
  protected ?EntityInterface $book = NULL;

  /**
   * Sets the book.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $book
   *   The book to be served.
   */
  public function setBook(?EntityInterface $book): void {
    $this->book = $book;
  }

  /**
   * Gets the book to be served.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The book to be served.
   */
  public function book(): ?EntityInterface {
    return $this->book;
  }

}