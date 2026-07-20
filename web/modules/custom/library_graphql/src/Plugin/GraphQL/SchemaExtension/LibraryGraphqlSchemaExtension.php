<?php

declare(strict_types=1);

namespace Drupal\library_graphql\Plugin\GraphQL\SchemaExtension;

use Drupal\graphql\Attribute\SchemaExtension;
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
    // Resolver wiring for createBook comes in the next step.
  }

}
