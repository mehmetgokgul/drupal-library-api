# Week 2 — Basic Queries

## 1. Books list (title + year)

```graphql
{
  nodeBooks(first: 10) {
    edges {
      node {
        title
        publishYear
      }
    }
  }
}
```

Returns all 10 original sample books, e.g.:

```json
{ "title": "Dune", "publishYear": 1965 },
{ "title": "Dune Messiah", "publishYear": 1969 },
{ "title": "Sapiens", "publishYear": 2011 }
```

## 2. Books list with author name (union inline fragment)

`author` is a union type (`NodeUnion`) because an entity reference field
can target more than one node bundle — even though `field_author` only
ever points at Author in practice. Querying a field directly on a union
fails; the fragment names the concrete type:

```graphql
{
  nodeBooks(first: 5) {
    edges {
      node {
        title
        author {
          ... on NodeAuthor {
            title
          }
        }
      }
    }
  }
}
```

```json
{ "title": "Dune", "author": { "title": "Frank Herbert" } },
{ "title": "Suç ve Ceza (Crime and Punishment)", "author": { "title": "Fyodor Dostoyevski" } }
```

**Gotcha:** a wrong fragment type (e.g. `... on NodeMovie` on a book's
`author` field) doesn't error — it silently returns `{}`. Always check the
fragment type first when a reference field looks unexpectedly empty.

## 3. Movies list with director name

Same pattern applied independently to Movie/Director:

```graphql
{
  nodeMovies(first: 5) {
    edges {
      node {
        title
        director {
          ... on NodeDirector {
            title
          }
        }
      }
    }
  }
}
```

```json
{ "title": "Yıldızlararası (Interstellar)", "director": { "title": "Christopher Nolan" } },
{ "title": "Baba (The Godfather)", "director": { "title": "Francis Ford Coppola" } },
{ "title": "Baba 2 (The Godfather Part II)", "director": { "title": "Francis Ford Coppola" } }
```

## 4. Single book detail — Dune (by UUID)

`nodeBook(id:)` takes the entity **UUID**, not the sequential node ID —
passing `"10"` is a valid query that simply matches nothing. Fetched the
UUID via `ddev drush sql:query "SELECT nid, uuid FROM node WHERE nid = 10"`.

```graphql
{
  nodeBook(id: "99bdfb6b-1969-4c80-a525-9dd576a79bd0") {
    title
    publishYear
    author {
      ... on NodeAuthor {
        title
      }
    }
    genre {
      ... on TermGenre {
        name
      }
    }
  }
}
```

```json
{
  "title": "Dune",
  "publishYear": 1965,
  "author": { "title": "Frank Herbert" },
  "genre": [{ "name": "Sci-Fi" }]
}
```

## 5. Genre filtering — architectural decision

- **Finding:** no native `filter` argument exists on connection queries in
  `graphql_compose` 3.0.0-alpha4 — confirmed via introspection (connections
  accept only `after`/`before`/`first`/`last`/`reverse`/`sortKey`), no
  hidden config toggle on the Genre bundle or the `field_genre` block, and
  no reverse-reference field on `TermGenre` either.
- **Ecosystem mix-up ruled out:** the `filter: { conditions: [...] }`
  syntax seen in older tutorials belongs to the classic `drupal/graphql`
  EntityQuery integration, not Compose — a different module than the one
  installed here.
- **Decision:** client-side filtering for now — fetch the list including
  `genre`, filter on the consumer side.
- **Future option (not built):** the `graphql_compose_views` submodule +
  a View with an exposed filter would add server-side filtering without a
  custom resolver.
- **Ruled out:** a custom resolver (Option C) — overkill for the current
  scale and requirement.
