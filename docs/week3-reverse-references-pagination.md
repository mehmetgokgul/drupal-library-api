# Week 3 — Reverse References & Pagination

## Reverse reference: decision & implementation

**Requirement:** query a book's author normally (forward reference — already
exists via `field_author`), but also query an author's list of books
(reverse reference — an entity reference field only points one direction;
Drupal has no built-in "give me everything that points at me" field).

**Options considered:**
- A GraphQL Compose plugin/resolver — rejected after reading Compose
  source. `GraphQLComposeFieldTypeManager::getBundleFields()` delegates
  field enumeration to Drupal's own field system
  (`entityFieldManager->getFieldDefinitions()` + per-field `enabled`
  config). There's no Compose-level hook for inventing a field that isn't
  a real Drupal field — a Compose plugin would fight the architecture.
- Standard Drupal **computed field** — adopted. If Compose just reads
  whatever Drupal's field system reports, a computed Drupal field named
  `books` on the Author bundle gets picked up automatically, same as any
  stored field.

**Implementation** (`library_graphql` module):
- `hook_entity_bundle_field_info()` declares a computed `entity_reference`
  field: `books` on `node/author`, `movies` on `node/director`
  (`BaseFieldDefinition`, `setComputed(TRUE)`, `setClass(...)`).
- `ComputedBooksFieldItemList.php` / `ComputedMoviesFieldItemList.php` —
  extend `EntityReferenceFieldItemList`, use `ComputedItemListTrait`.
  `computeValue()` runs an entity query against `field_author`
  (respectively `field_director`) with `accessCheck(TRUE)`.

**Known bug, workaround in place:** the Compose admin SchemaForm filters
out anything `instanceof BaseFieldDefinition`, so computed bundle fields
never appear in the field-enable checkbox UI, even though the schema
engine uses a different, correct check and the fields work. Enabled via
config directly:

    ddev drush config:set graphql_compose.settings.graphql_compose_server \
      field_config.node.<bundle>.<field>.enabled true

Re-saving the Author/Director section in the admin form may wipe this
config, since the form can't see the entry it would be overwriting. If
reverse references vanish from the schema, check this first.

**Verified:** Frank Herbert → *Dune* + *Dune Messiah* (below). Director →
`movies` applied independently on the same pattern (Christopher Nolan →
*Interstellar* + *Inception*), confirming the approach generalizes past
the Book/Author case it was built for.

## Nested query example

```graphql
{
  nodeBooks(first: 10) {
    edges {
      node {
        title
        author {
          ... on NodeAuthor {
            title
            books {
              ... on NodeBook {
                title
              }
            }
          }
        }
        genre {
          ... on TermGenre {
            name
          }
        }
        summary
      }
    }
  }
}
```

Reverse reference resolves correctly for every author in the set:

- Frank Herbert → Dune, Dune Messiah
- Fyodor Dostoyevski → Suç ve Ceza, Karamazov Kardeşler
- Yuval Noah Harari → Sapiens, Homo Deus
- Walter Isaacson → Steve Jobs, Einstein: His Life and Universe
- Ray Bradbury → Fahrenheit 451, The Martian Chronicles

`summary` returned `null` across the board — expected, field isn't
populated in sample data yet, not a bug.

## Pagination proof

Cursor-based pagination (Relay connection spec: `edges { cursor node }` +
`pageInfo { hasNextPage endCursor }`), using `first`/`after`.

- **Page 1** — `nodeBooks(first: 3)` → Dune, Dune Messiah, Suç ve Ceza ·
  `hasNextPage: true`
- **Page 2** — `nodeBooks(first: 3, after: <page 1 endCursor>)` →
  Karamazov Kardeşler, Sapiens, Homo Deus · `hasNextPage: true`
- **Page 3** — `nodeBooks(first: 10, after: <page 2 endCursor>)` →
  Steve Jobs, Einstein: His Life and Universe, Fahrenheit 451,
  The Martian Chronicles · `hasNextPage: false`

10 books total, covered across 3 pages, no duplicates, no gaps. Cursors
decode from base64 to `{backingType, backingId, sortValue, filters}` —
useful to know it's not opaque magic, but treat it as an implementation
detail, not something client code should parse.

## Performance observation

Compared two queries against `nodeBooks(first: 10)` using Chrome DevTools Network tab:

- **Slim query** (title only): 38 ms, 0.3 kB response
- **Fat query** (title + author with nested reverse-reference `books` list +
  genre + summary): 52 ms, 0.5 kB response

The fat query took ~37% longer and returned roughly 1.7x the data. The
difference comes from GraphQL resolving each book's `author` reference,
then for each author running the computed `books` field (an entity query
against `field_author`) — so the reverse-reference resolution is happening
once per book in the result set, not once total. With 10 books this is
negligible; it's a pattern worth watching if the dataset or nesting depth
grows (N+1-style resolution cost), but not a concern at this scale.


