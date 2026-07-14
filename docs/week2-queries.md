# Week 2 — Basic Queries

## 1. Books list (title + year)
[query + 2-3 lines of sample response]

## 2. Books list with author name (union inline fragment)
[query + note: why `... on NodeAuthor` is required]

## 3. Movies list with director name
[query + note: pattern applied independently from #2]

## 4. Single book detail — Dune (by UUID)
[query + note: id takes UUID not nid, how you fetched the UUID]

## 5. Genre filtering — architectural decision
- Finding: no native filter argument in graphql_compose 3.0.0-alpha4
- Evidence: introspection (connections accept only after/before/first/last/reverse/sortKey), no config toggle, old filter syntax belongs to classic drupal/graphql
- Decision: client-side filtering for now
- Future option: graphql_compose_views + exposed filter View
- Ruled out: custom resolver (overkill at this stage)