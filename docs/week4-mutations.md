# Week 4 — Mutations

## Mutation pattern: decision & implementation

**Requirement:** add a `createBook` mutation — the first write operation in
the API. Everything before Week 4 was read-only.

**Finding:** GraphQL Compose (3.0.0-alpha4) has no generic mutation support
for regular content types — its own issue queue confirms this is
comments-only / internals-heavy, not a public API to build on.

**Decision:** hand-write the mutation using base `drupal/graphql`'s own
documented pattern instead of waiting on Compose:

- `SdlSchemaExtensionPluginBase` (schema extension plugin)
- `.graphqls` SDL files (schema-first type declarations)
- `DataProducer` plugins (the resolver logic, one per field)

Demonstrated in `web/modules/contrib/graphql/examples/graphql_composable/`
and the module's own `doc/` folder.

**Key finding:** `GraphQLComposeSchema.php` already declares
`schema { query mutation subscription }` plus a placeholder
`type Mutation { _: Boolean! }`. Custom mutation SDL must use
`extend type Mutation { ... }` — a second `type Mutation` declaration
fails the schema build with a duplicate-type error.

## Files added (`library_graphql` module)

- `graphql/library_graphql.base.graphqls` — `BookInput`, `BookResponse`,
  `scalar Violation`
- `graphql/library_graphql.extension.graphqls` — `extend type Mutation { createBook }`
- `src/Plugin/GraphQL/SchemaExtension/LibraryGraphqlSchemaExtension.php` —
  wires SDL fields to producers via `ResolverBuilder`
- `src/GraphQL/Response/BookResponse.php` — response object
- `src/Plugin/GraphQL/DataProducer/CreateBook.php` — the write logic
- `src/Plugin/GraphQL/DataProducer/BookResponseBook.php` — resolves `BookResponse.book`
- `src/Plugin/GraphQL/DataProducer/BookResponseErrors.php` — resolves `BookResponse.errors`

## Response/Violation design

`BookResponse` carries either the created `book`, or a list of `errors` —
never an exception thrown to the client. Modeled on the module's own
`Response`/`Violation` base classes.

**Gotcha:** the `book` property must be declared `?EntityInterface $book = NULL`
with a setter — not a required constructor parameter. A non-nullable
constructor-required property breaks the pattern, since the response is
always constructed first and filled in afterward, on both the success and
failure paths.

## Identifying entities: UUID, not machine name

`authorId` and `genre` in `BookInput` both identify entities by **UUID**,
consistent with the existing `nodeBook(id:)` query convention. A
machine-name alternative was considered for `genre` (since Genre is a fixed
taxonomy) and rejected, to keep the whole input consistent — one
identification scheme, not two.

Resolved via `entity.repository`'s `loadEntityByUuid('node' | 'taxonomy_term', $uuid)`
— note the first argument is the entity **type ID** (`node`, `taxonomy_term`),
not the bundle (`author`, `genre`).

## Validation

`CreateBook::resolve()`, in order:

1. Permission check — `create book content` — first, before touching any input.
2. Resolve `authorId` → node, check `bundle() === 'author'`.
3. Resolve each `genre` UUID → taxonomy term, check `bundle() === 'genre'`.
4. Build the node with `Node::create()`, then call `$node->validate()` —
   reusing Drupal's own field constraints (required fields, ranges,
   reference validity) instead of re-implementing them.
5. Only on zero violations: `$node->save()`.

Any failure at steps 1–4 returns immediately with an `errors` entry and
saves nothing.

## Runtime verification: NodeBook reuse (open risk from schema-build stage)

`BookResponse.book` is typed as Compose's own `NodeBook` type (not a
hand-defined duplicate), but the `book_response_book` producer returns a
plain entity from `Node::create()` — never touched by Compose's own node
loading/wrapping. Whether Compose's `NodeBook` type would resolve a bare
entity like that was unconfirmed until actually running a mutation.

**Verified**, via a real `createBook` call returning `book { id title }`:

```json
{
  "data": {
    "createBook": {
      "book": {
        "id": "0d36fddd-954f-425d-9342-eb1b1aade1f4",
        "title": "GraphiQL Verify Book"
      },
      "errors": []
    }
  }
}
```

`NodeBook` reuse works at runtime with no fallback needed. (Test node
deleted after verification — not real content.)

## End-to-end verification (GraphiQL, as admin)

Three cases run against the real HTTP mutation, via
`/admin/config/graphql/servers/manage/graphql_compose_server/explorer`:

- **Valid input** → book created, `errors: []`. Confirmed above.
- **Invalid input** (nonexistent `authorId`) → `book: null`,
  `errors: [{ "message": "Author 00000000-... was not found." }]`.
  Nothing written — confirmed via `sql:query`, no matching node exists.
- **Unauthenticated** → request never reaches `CreateBook::resolve()` at
  all. Drupal's own `graphql` module rejects it first, at the `/graphql`
  endpoint itself:

  ```json
  {"message":"The 'execute graphql_compose_server arbitrary graphql requests' permission is required."}
  ```

**Distinction worth keeping straight:** `CreateBook`'s own
`create book content` permission check is a real, working second line of
defense — but it only matters for an *authenticated* user who lacks that
permission. A fully anonymous request never gets that far; the module's
own endpoint-level access check (`execute ... arbitrary graphql requests`)
blocks it earlier. Both checks exist and both are correct — they just
guard different layers.

## Known bugs / gotchas (mutation-specific)

- DataProducer `consumes`/`produces` `ContextDefinition` `data_type` is a
  typed-data plugin id string (`'any'`, `'string'`, `'entity'`) — not a PHP
  class name. Passing a FQCN (e.g. `BookResponse::class`) looks plausible
  but is wrong.
- Response-reading producers (`book_response_errors`) type-hint the base
  `Response` class, not `BookResponse` specifically — keeps the producer
  reusable for future mutations (e.g. a future `MovieResponse`).
- Verification technique for schema-shape checks without touching the
  blocked anonymous `/graphql` endpoint: build the schema in-process via
  `\Drupal::service('plugin.manager.graphql.schema')`, in a throwaway
  `drush scr` script, deleted after use.
