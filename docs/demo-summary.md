# Demo / Project Summary — Book & Movie Library API

5-week case study: a Drupal 11 + GraphQL Compose API for a book/movie
library, ending with automated ingestion of external JSON data via
Drupal's Queue API.

## Stack

Drupal 11.4.4, DDEV, `graphql` 5.0.0 + `graphql_compose` 3.0.0
(`webonyx/graphql-php` 15.34.0), one custom module (`library_graphql`).
No contrib module was needed for mutations, reverse references, or
ingestion — all hand-written against `drupal/graphql`'s own documented
patterns and Drupal core's Queue API.

## Content model

- **Genre** — shared taxonomy (8 terms: Fiction, Sci-Fi, Biography,
  History, Poetry, Drama, Comedy, Crime), used by both Book and Movie.
- **Author** / **Director** — content types (Biography, Birth Year,
  Photo), same shape, built independently to rehearse the pattern twice.
- **Book** — Title, Author (entity reference), Publish Year, Cover Image,
  Genre (unlimited), Summary.
- **Movie** — Title, Director (entity reference), Release Year, Duration,
  Poster Image, Genre (unlimited), Summary.

Field storages were kept separate per content type deliberately (e.g.
`field_publish_year` vs `field_release_year`) instead of reused, so the
two types stay fully independent — this shaped several later decisions,
including the Week 5 choice of two ingestion queues instead of one.

## Week-by-week

**Week 1 — Setup & content model.** Fresh Drupal 11 on DDEV, GraphQL
Compose installed (after a security-driven decision to use the modern
`graphql` 5.x stack instead of bypassing an advisory block on 4.x).
Content model built by hand: taxonomy vs. content-type distinction,
entity reference fields, config export as the mechanism that makes
structure (not content) git-trackable.

**Week 2 — Queries.** ([docs/week2-queries.md](week2-queries.md)) Closed a
real two-day debugging session first: the schema appeared empty
(`[node, info]` only), which turned out to be two config settings working
exactly as configured (`simple_queries: true` collapsing everything into
a union, and Compose's opt-in-per-field `field_config`) — not a bug. Once
resolved: 4 list/detail queries proven against real data, plus a
documented decision to filter genre client-side (no server-side filter
argument exists in this Compose alpha).

**Week 3 — Relations & pagination.** ([docs/week3-reverse-references-pagination.md](week3-reverse-references-pagination.md))
Drupal has no built-in reverse-reference field, so Author→books and
Director→movies were built as standard Drupal **computed fields**
(`hook_entity_bundle_field_info` + `ComputedItemListTrait`), not a
Compose plugin — Compose just reads whatever Drupal's own field system
reports. Cursor-based (Relay-style) pagination proven across a full
3-page walk of the dataset; a real N+1-style performance cost measured
and documented (37% slower on a "fat" query with nested reverse
references), not just asserted.

**Week 4 — Mutations.** ([docs/week4-mutations.md](week4-mutations.md))
Compose has no generic mutation support for regular content types, so
`createBook` was hand-written on base `drupal/graphql`'s own
Response/Violation pattern: a `BookInput` (author/genre identified by
UUID, consistent with existing query conventions), a `BookResponse` that
carries either the created book or a list of violations — never a thrown
exception — and validation that reuses Drupal's own `$node->validate()`
instead of re-implementing field constraints by hand. Verified against 3
real cases over HTTP: valid input, invalid input (no write occurs),
and unauthenticated (blocked at a different, earlier layer than the
mutation's own permission check — two real defenses, not one).

**Week 5 — External ingestion.** ([docs/week5-ingestion.md](week5-ingestion.md))
A dummy JSON source (Turkish genre slugs, name-based author/director
identification, deliberately malformed entries) is ingested via two
Drupal Queue API workers (`book_ingest`, `movie_ingest`), triggered by a
custom Drush command (`library:ingest`). Three shared helper classes
(genre slug mapping, title normalization, name-based find-or-create)
keep the two workers thin. Verified end to end multiple times: correct
creates/updates split, all 4 deliberately broken/decoy entries handled
correctly, full idempotency on repeated runs, and — as of today — a real
GraphQL query proving the complete chain from external JSON to a live
API response.

## End-to-end proof (the project's core claim)

```
mocky-kitap-dummy-data.json
        │  ddev drush library:ingest
        ▼
   Queue API (book_ingest / movie_ingest)
        │  BookIngestWorker / MovieIngestWorker
        ▼
   Drupal node (e.g. "Kozmos", author Carl Sagan, genre Sci-Fi)
        │
        ▼
   GraphQL query (nodeBook) over /graphql
        →  { "title": "Kozmos", "publishYear": 1980, "author": { "title": "Carl Sagan" }, "genre": [{ "name": "Sci-Fi" }] }
```

Run and confirmed live on 2026-07-27 (see
[docs/week5-ingestion.md](week5-ingestion.md#end-to-end-graphql-proof-july-27)
for the full query and response).

## Recurring lessons across all 5 weeks

- **Config-driven modules fail silently, not loudly** — "not exposing my
  data" was configuration (Week 2), not a bug, twice.
- **Read the library's own source before guessing its shape** — settled
  the Week 3 reverse-reference approach, the Week 4 mutation pattern, and
  several Week 5 bugs (Drush's instantiator, a queue's `claimItem()`
  semantics).
- **NULL, never a placeholder string, is the correct "no result" signal**
  for any lookup/mapper — used consistently from `GenreSlugMapper` through
  `NodeByNameResolver`.
- **Nullability should be justified by consequence, not just possibility**
  — `findOrCreate()` returns `?NodeInterface` not only because "no match"
  is a real case, but because skipping the check would permanently write
  a bad row to the database, a much more expensive mistake than a wrong
  return value.
- **Don't clean your own data to dodge a real integration problem** —
  the mismatch between the messy external JSON and curated sample titles
  (Week 5) is exactly the scenario ingestion code is supposed to handle;
  removing it would remove the lesson.
- **Verify claims against real state, not notes** — caught twice this
  project (a module skeleton that a prior day's notes claimed existed but
  didn't; a Week 2 doc that claimed to be closed but was still
  placeholder text) and fixed both times before building further on top.
