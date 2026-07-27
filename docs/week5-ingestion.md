# Week 5 — External JSON Ingestion via Queue API

## Requirement

Ingest book/movie data from an external JSON source (`mocky-kitap-dummy-data.json`,
a Mocky.io-style dummy dataset) into real Drupal nodes, using Drupal's
Queue API — not a synchronous script — so that one bad entry can never
stop the whole batch (fault tolerance).

## Trigger mechanism (decision)

**Chosen:** a custom Drush command (`library:ingest`), not cron or an
admin-form button.

**Reasoning:** this is a solo learning project. I am the one running
ingestion by hand, repeatedly, while debugging fault-tolerance behavior —
I want to trigger it on demand and read the output directly in the
terminal, not wait on a cron schedule. This also matches how the whole
project is already run day to day, via `ddev drush`.

**Style used:** Drush 13.7+'s current recommended "Console" style — a
plain Symfony `Command` (`#[AsCommand]`, `AutowireTrait` for zero-boilerplate
constructor injection) — confirmed correct for the installed Drush version
(13.7.4) by reading Drush's own `docs/commands.md` before writing anything.
The older `DrushCommands`/`#[CLI\Command]` style shown in older tutorials
is explicitly deprecated as of 13.7.

## Queue design (decision): two queues, not one

Two separate queues (`book_ingest`, `movie_ingest`) and two `QueueWorker`
plugins, instead of one queue with a type discriminator field.

**Reasoning:**
- The usual argument for a single worker is avoiding duplicated logic.
  That duplication was already removed by pulling the shared logic into
  three reusable helper classes (`GenreSlugMapper`, `TitleNormalizer`,
  `NodeByNameResolver`) — what's left in each worker is genuinely
  different, not copy-paste.
- Zero field machine names are shared between Book and Movie
  (`field_author` vs `field_director`, `field_publish_year` vs
  `field_release_year`, `field_genre` vs `field_movie_genre`,
  `field_summary` vs `field_movie_summary`, plus `field_duration`, which
  Book has no equivalent of) — a direct consequence of the Week 1 decision
  to use separate field storages per content type. A single worker would
  still need a per-type field map internally; it would just hide the
  branching inside a `switch` instead of removing it.
- Practical bonus: a poison item in one queue can never block the other,
  and each queue can be run/tested independently.

## Helper classes (shared logic)

| Class | Shape | Why |
|---|---|---|
| `GenreSlugMapper::mapToTermName(string $slug): ?string` | static | pure string lookup, no Drupal services needed |
| `TitleNormalizer::normalize(string $title): string` | static | pure string transform, no Drupal services needed |
| `NodeByNameResolver` (`findOrCreate()`, `findTermByName()`) | real service (`services.yml` + constructor injection) | needs `entity_type.manager` to query/create nodes |

The static-vs-service split follows one rule: does the code need to talk
to Drupal's container? Pure string-in/string-out logic doesn't, and
making it static means it can be tested without booting Drupal at all.

## JSON → field mapping: Book

| JSON key | Book field | Notes |
|---|---|---|
| `title` | `title` (core) | matched via normalized comparison, never overwritten on update |
| `author_name` | `field_author` | resolved via `NodeByNameResolver::findOrCreate('author', …)` — auto-creates a minimal (name-only) node on miss |
| `first_publish_year` | `field_publish_year` | guarded with `is_numeric()` before casting to int |
| `genre` (Turkish slug) | `field_genre` | `GenreSlugMapper` → English term name → `findTermByName('genre', …)` |
| `summary` | `field_summary` | direct copy |
| `cover_url` | *(not ingested)* | deferred — see Decision 12 below |

## JSON → field mapping: Movie

| JSON key | Movie field | Notes |
|---|---|---|
| `title` | `title` (core) | same normalized-match rule as Book |
| `director_name` | `field_director` | same `findOrCreate('director', …)` pattern |
| `release_year` | `field_release_year` | guarded with `is_numeric()` |
| `duration_minutes` | `field_duration` | **also required**, same silent-coercion risk as `first_publish_year` — guarded with its own `is_numeric()` check |
| `genre` (Turkish slug) | `field_movie_genre` | same mapper + `findTermByName('genre', …)` |
| `summary` | `field_movie_summary` | direct copy |
| `poster_url` | *(not ingested)* | deferred — see Decision 12 below |

## Key decisions and reasoning

**Decision — title matching uses normalized comparison, not exact match.**
Real DB titles and JSON titles don't line up character-for-character:
`"Suç ve Ceza"` (JSON) vs `"Suç ve Ceza (Crime and Punishment)"` (DB),
`"Sapiens: İnsan Türünün Kısa Bir Tarihi"` (JSON) vs `"Sapiens"` (DB).
`TitleNormalizer` cuts each title at its first `:` or `(`, whichever comes
first, and trims — grounded directly in the two conventions already used
in the hand-curated sample data (translation in parens, subtitle after a
colon). Normalization runs on **both sides** of the comparison. Verified:
without normalization, only 1 of 9 real movie titles would match; with it,
9 of 9. `"Baba"` vs `"Baba 2"` still stay distinct after normalizing —
proof the rule isn't too aggressive.

**Decision — Author/Director not found by name → auto-create a minimal
record**, not skip or log-for-manual-follow-up. Checked against real data:
4 of 10 JSON books reference an author not yet in the DB
(Vasconcelos, Sagan, Zweig, Atatürk) — all well-formed, valid entries.
Treating "not found" as an error would silently drop 4 good books every
run. Re-checked against the original case-study spec PDF directly (it
leaves this exact call to the student) before finalizing — auto-create
sets only the `name` field (the one value JSON actually provides);
Biography/Birth Year/Photo stay empty because the source data never
carries them.

**Decision — on an update (title match found), the title is never
overwritten.** Every other field syncs from the incoming JSON on both
create and update, but title is used only to *find* the record. This
protects the hand-curated `"Turkish (English)"` titles from being
clobbered by the ingestion source, which has no English titles at all.
Verified directly: `"Suç ve Ceza (Crime and Punishment)"` and
`"Baba (The Godfather)"` both survive repeated ingestion runs unchanged.

**Decision — Genre lookup has no create-on-miss.** Unlike Author/Director,
`findTermByName()` never creates a new taxonomy term on a miss. Genre is a
fixed, closed 8-term vocabulary (Fiction, Sci-Fi, Biography, History,
Poetry, Drama, Comedy, Crime) — auto-creating a genre term would be a
different, wrong kind of decision than auto-creating an author.

**Decision — image ingestion deferred.** `cover_url`/`poster_url` are not
downloaded or turned into managed File entities in this pass.
`field_cover_image`/`field_poster_image` are optional fields, and
fetching a remote URL + creating a File entity + alt text is a distinct
chunk of work from the Queue API lesson Week 5 is actually about. Can be
added later without touching anything already built.

## Fault tolerance: guards before matching

Each worker validates required fields **before** normalizing/matching, not
only relying on `$node->validate()` at the end. Reasoning: a non-numeric
or `null` value silently `(int)`-casts to `0`, which is not "empty" — so
Drupal's own required-field check would never catch it. This risk applies
to any required integer field fed by unvalidated external input, which is
why both `first_publish_year` (Book) and `release_year` **and**
`duration_minutes` (Movie — two required numeric fields, not one) each
get their own explicit `is_numeric()` guard.

Guard order, both workers: empty title → null/missing name → non-numeric
required integer field(s) → normalized title match/create → resolve
author/director (create-on-miss) → resolve genre (no create-on-miss) →
`$node->validate()` (catches anything the hand-guards can't see) → save.

## Verified end-to-end numbers (first real run, July 25)

| | In | Updates | Real creates | Rejected |
|---|---|---|---|---|
| Books | 10 | 5 | 4 | 1 (id 9) |
| Movies | 7 | 4 | 2 | 1 (id 6) |

Books 10 → 14, Authors 5 → 9 (4 new: Vasconcelos, Sagan, Zweig, Atatürk).
Movies 8 → 10, Directors 4 → 6 (2 new: Jean-Pierre Jeunet, Marc Forster).

## Fault-tolerance proof (all 4 deliberate edge cases)

- **Book id 9** — empty title. Rejected with a clear logged reason
  (`title is empty`), one bad item never blocked the rest of the queue.
- **Book id 8** — `"malformed": true`, but every real field in it is
  actually fine. A decoy. Passed through silently, exactly as intended —
  no special-casing needed, the flag itself is meaningless to the worker.
- **Movie id 6** — real broken data (empty `director_name` and a
  non-numeric `duration_minutes`/`release_year` — both broken at once).
  Rejected, logged. Note: the exact logged reason has varied run to run
  (`duration` vs `release year`) depending on which guard runs first —
  both are correct rejections of the same broken entry, not a
  contradiction.
- **Movie id 7** — unexpected extra JSON field `"extra_field_test"`.
  Ignored, not fatal — the worker only reads the keys it maps.

## Idempotency

Re-running `ddev drush library:ingest` against already-ingested data
produces **zero creates, all updates, the same two rejects**, every time.
Confirmed through the real Drush command (not just the underlying worker
logic) on July 26 and again July 27: node counts identical before and
after (14 books / 10 movies / 9 authors / 6 directors), same nids, same
watchdog warnings. This matters because the trigger is meant to be run
repeatedly while debugging — if this were broken, every test run would
leave duplicate junk behind.

## End-to-end GraphQL proof (July 27)

Queried an ingested book directly through the real GraphQL API to prove
the full chain: **external JSON → Queue → Drupal node → GraphQL.**

```graphql
{
  nodeBook(id: "2a141268-5115-44fe-81fe-17a981e3a6c8") {
    title
    publishYear
    summary
    author {
      ... on NodeAuthor {
        title
        books {
          ... on NodeBook { title }
        }
      }
    }
    genre {
      ... on TermGenre { name }
    }
  }
}
```

```json
{
  "title": "Kozmos",
  "publishYear": 1980,
  "summary": { "value": "Evreni, bilimi ve insanlığın evrendeki yerini konu alan popüler bilim kitabı." },
  "author": {
    "title": "Carl Sagan",
    "books": [{ "title": "Kozmos" }]
  },
  "genre": [{ "name": "Sci-Fi" }]
}
```

Confirms, on real ingested data: the create path wrote a real node
(`Kozmos`, 1980), the auto-created author (`Carl Sagan`) resolves
correctly and its Week 3 reverse-reference field (`books`) works even on
an ingestion-created node, and the Turkish genre slug (`bilim-kurgu`)
correctly mapped to the English taxonomy term (`Sci-Fi`).

## Known bugs caught in review (all fixed before running)

- **`import` instead of `use`** for class imports — PHP has no `import`
  keyword; this is a parse error, not an alternate syntax (reflex from
  another language).
- **`AutowireTrait` imported but never mixed in.** `use Drush\Commands\AutowireTrait;`
  at the top of the file only aliases the namespace; the class body also
  needed its own `use AutowireTrait;` to actually mix the trait's methods
  in. Without it, Drush's instantiator fell back to a zero-argument
  `new LibraryIngestCommand()` against a 3-argument constructor and
  crashed with "Too few arguments." Confirmed by reading Drush's actual
  `instantiateServices()` source in `vendor/`, not by guessing.
- **`claimItem()` called twice per loop turn** (once in the `while`
  condition, once again inside the body) — each call claims a *different*
  item, so roughly half the queue was silently abandoned every run, with
  no error output. Fixed by using a single `claimItem()` call as the
  `while` condition (the shape the movie loop already used correctly).

## Files added

- `src/Ingest/GenreSlugMapper.php`
- `src/Ingest/TitleNormalizer.php`
- `src/Ingest/NodeByNameResolver.php` + `library_graphql.services.yml`
- `src/Plugin/QueueWorker/BookIngestWorker.php`
- `src/Plugin/QueueWorker/MovieIngestWorker.php`
- `src/Drush/Commands/LibraryIngestCommand.php`
- `data/mocky-kitap-dummy-data.json`
