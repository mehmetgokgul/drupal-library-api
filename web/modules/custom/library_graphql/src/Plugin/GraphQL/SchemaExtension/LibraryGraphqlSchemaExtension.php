<?php

declare(strict_types=1);

namespace Drupal\library_graphql\Plugin\GraphQL\SchemaExtension;

use Drupal\graphql\Attribute\SchemaExtension;
use Drupal\graphql\GraphQL\ResolverBuilder;
use Drupal\graphql\GraphQL\ResolverRegistryInterface;
use Drupal\graphql\Plugin\GraphQL\SchemaExtension\SdlSchemaExtensionPluginBase;

/**
 * Adds Library GraphQL mutations (createBook) to the Compose schema.
 */
#[SchemaExtension(
  id: "library_graphql",
  name: "Library GraphQL",
  description: "Custom mutations for the Library API.",
  schema: "graphql_compose",
)]
class LibraryGraphqlSchemaExtension extends SdlSchemaExtensionPluginBase {

  /**
   * {@inheritdoc}
   */
  public function registerResolvers(ResolverRegistryInterface $registry): void {
    $builder = new ResolverBuilder();
    $registry->addFieldResolver(
      'Mutation',
      'createBook',
      $builder->produce('create_book')
        ->map('data', $builder->fromArgument('data'))
    );  

    $registry->addFieldResolver(
      'BookResponse',
      'book',
      $builder->produce('book_response_book')
        ->map('response', $builder->fromParent())
    );

    $registry->addFieldResolver(
      'BookResponse',
      'errors',
      $builder->produce('book_response_errors')
        ->map('response', $builder->fromParent())
    );
  
    }
}
