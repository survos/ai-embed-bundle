# Survos AI Embed Bundle

Shared home for model-aware embedding requests and reusable vector caches.

**Initial scaffold:** this release registers a Symfony bundle and boots without a database, model server, or API credentials. Embedding generation, cache backends, document chunking, and queue handling are not implemented yet. Installing it does not create files, alter the application schema, or send model requests.

```bash
composer require survos/ai-embed-bundle
```

Symfony Flex registers `Survos\AiEmbedBundle\SurvosAiEmbedBundle`. The bundle requires kit-bundle and uses its registration conventions. PHP 8.4+ and Symfony 8.1+ are required (transitive dependencies may impose a higher PHP minimum).

## Planned API and boundaries

- `ensureEmbedding(text, profile)`: an idempotent request keyed by the embedding profile and exact input hash; return a durable reference/status, with a vector when complete.
- A batch equivalent reuses cached results and groups only missing work by compatible profile.
- `ensureDocumentEmbedding(document, profile, chunkingOptions)`: a separate document layer that records immutable text, paragraph-aware chunks, offsets, and embedding links. No overlap initially.
- Profiles retain model/revision, dimensions, document/query prefixes, and normalization. Changing model space creates new results, never overwrites incompatible vectors.
- Completion validates and persists vectors through one idempotent result-writing path, whether execution was synchronous, queued locally, or a remote provider batch.
- No dependency on bill, newspaper, museum, or application Doctrine entity classes.

## Storage and integrations to build

Use an app-local store, independent of the application's database. Reuse `survos/jsonl-bundle` for `.jsonl.gz` archives and lookup sidecars, `survos/ai-duckdb-store` for DuckDB vector storage/search, and existing workflow/Messenger contracts for optional background execution. A PostgreSQL adapter may come later; it is not required.

Keep preserved embeddings distinct from disposable search indexes. Import/export must never call an embedding provider. Document text should be stored once, with explicit Unicode offsets and recorded input transformations. Preserve existing archives while testing new representations.

Bookworm is the initial fixture application (Virginia bills and existing OpenAI/Nomic vectors). KPA, bench, Bill/rsun, and folio consumers can install the same package; app-specific importers remain outside it. Folio integration should bind a vector companion to an immutable source build. Bench can test archive round-trips, cache identity, profile isolation, retrieval, and storage sizes.

## Development

Persistent changes belong in `survos/mono`, under `bu/ai-embed-bundle`. After installing a published package in an app, use the existing mono linker:

```bash
php /path/to/mono/link /path/to/app
```

Do not add Composer path/VCS overrides or edit a copied vendor package. This initial bundle does not yet replace Bookworm's experimental embedding implementation.

## Tests

With this package's development dependencies installed:

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```

The kernel test verifies bundle registration and compilation without Doctrine or provider credentials.
