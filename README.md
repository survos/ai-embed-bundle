# Survos AI Embed Bundle

Model-aware embedding requests and an app-local DuckDB cache, independent of application entities and databases. The first working provider is local Ollama/Nomic. Remote OpenAI batching is the next slice; selecting an OpenAI model does not yet enable a remote provider.

```bash
composer require survos/ai-embed-bundle
php /path/to/mono/link /path/to/app
```

PHP 8.5+, native PDO DuckDB and the Composer dependencies are required. The bundle is disabled by default. Enabling services does not open a database or call a provider until used.

## Local configuration

```yaml
survos_ai_embed:
    enabled: true
    model: '%env(AI_EMBED_MODEL)%'
    revision: '%env(AI_EMBED_REVISION)%'
    url: '%env(AI_EMBED_URL)%'
    cache_path: '%env(resolve:AI_EMBED_CACHE)%'
```

```dotenv
AI_EMBED_MODEL=nomic-embed-text:v1.5
AI_EMBED_REVISION=0a109f422b47e3a30ba2b10eca18548e944e8a23073ee3f3e947efcf3c45e59f
AI_EMBED_URL=http://localhost:11434
AI_EMBED_CACHE=%kernel.project_dir%/var/embeddings/cache.duckdb
```

Pin the installed model digest, not merely a mutable tag. The consumer checks `/api/tags` before each batch and rejects a mismatched revision. Requests use `truncate: false` so oversized inputs cannot silently lose text.

## API and lifecycle

Inject `Survos\AiEmbedBundle\EmbeddingService`:

```php
$request = $embeddings->ensureEmbedding($text);
$query = $embeddings->ensureEmbedding($phrase, 'query');
$document = $embeddings->ensureDocumentEmbedding($stableReference, $title, $text, maxTokens: 1800);
```

Ensure methods never call the provider. They create idempotent requests or return cached results. Individual requests include their vector when ready. Document handles contain `documentId`, `status`, `chunks`, `cacheHits`, and `pending`. Identical documents skip tokenization too. `documentChunks()` exposes ordered offsets/request IDs; the cache's `vector()` returns completed vectors.

```bash
php bin/console ai:embed:consume --batch-size=8 --limit=1000
php bin/console ai:embed:export var/embeddings/preserved.jsonl.gz
```

The consumer is a bounded queue drain, suitable for a scheduled worker. This first slice uses the durable DuckDB requests table rather than Messenger. It resumes pending/failed work after restart, validates the entire batch before writing, and records attempted provider invocations before network access. Failed requests retry on the next invocation. A crash after generation but before commit may repeat generation; exactly-once external calls are not promised.

Export uses jsonl-bundle's gzip writer and includes profile, exact input, request ID and vector. It makes no provider calls and refuses to overwrite archives. Document/chunk mapping remains in DuckDB; this vector export alone is not a complete document archive. Copy the closed DuckDB file for full restoration. Archive import is not yet implemented.

## Cache identity and tables

A profile records provider, model, immutable revision, dimensions, task prefixes and normalization. Request IDs hash profile ID plus exact prefixed input. Application numeric IDs never affect request identity. Identical input is shared across documents; different profiles have separate vector spaces.

- `profiles`: identity and JSON provenance.
- `documents`: immutable text/title, stable reference and chunker configuration.
- `chunks`: document ID, ordinal, Unicode start/end offsets, token count, request ID.
- `requests`: exact provider input, pending/ready/failed status, attempts, error class.
- `vectors_<profileId>`: fixed-size vectors managed by `survos/ai-duckdb-store`.
- `provider_calls`: attempted batches and outcomes.

Provider inputs are currently retained alongside document text for self-contained queue replay. Deduplication beyond identical inputs is a later optimization.

One cooperating process owns the file via a sidecar lock. Other importers/consumers fail clearly and can retry. Run import, consume and populate sequentially; this is not a multi-host concurrent-write database. No vector index is created automatically.

Chunking reuses Bookworm's paragraph/token implementation: pinned Nomic WordPiece resources include checksums and their Apache license; OpenAI counting uses yethee/tiktoken. No overlap; exact Unicode offsets. The default Nomic budget of 1,800 tokens includes title and task prefix, within its effective 2,048-token context.

Applications own entity/index projections. Purging application records must not purge this cache. Bill's Baltimore 2024 fixture exercises import → ensure → consume → populate → purge → reimport. Scanstation, KPA and bench can use the same package without bill-specific dependencies.

## Verification

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```

Tests cover kernel boot, profile isolation, deduplication, restart persistence, invalid batch atomicity/retry, model digest checks, and paragraph/token boundaries. Bill provides the live Nomic/database reimport proof.
