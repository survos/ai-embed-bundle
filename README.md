# Survos AI Embed Bundle

Model-aware embedding requests and an app-local DuckDB cache, independent of application entities and databases. Local Ollama/Nomic and asynchronous OpenAI embedding batches are supported. OpenAI uses the existing ai-batch-bundle client; embedding job persistence stays in the app-local DuckDB file. Interactive synchronous query embedding is not implemented yet.

```bash
composer require survos/ai-embed-bundle
php /path/to/mono/link /path/to/app
```

PHP 8.5+ and the Composer dependencies are required. The DuckDB backend additionally needs the optional `survos/ai-duckdb-store` package and native PDO DuckDB extension. Generation is disabled by default. Snapshot commands remain available, but do not open a database or contact storage until used.

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

Export uses jsonl-bundle's gzip writer and includes profile, exact input, request ID and vector. It makes no provider calls and refuses to overwrite archives. Document/chunk mapping remains in DuckDB; this vector export alone is not a complete document archive. Copy the closed DuckDB file for full restoration. JSONL archive import is not yet implemented; the snapshot commands below support DuckDB exchange.

## Cache identity and tables

A profile records provider, model, revision/cache generation, dimensions, task prefixes and normalization. For local models, pin the immutable digest. OpenAI currently exposes a model alias here, not an immutable revision; use an explicit cache-generation label and retain the returned model and raw batch metadata. Do not claim that label pins provider weights. Request IDs hash profile ID plus exact prefixed input. Application numeric IDs never affect request identity. Identical input is shared across documents; different profiles have separate vector spaces.

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

Tests cover kernel boot, profile isolation, deduplication, restart persistence, invalid batch atomicity/retry, model digest checks, and paragraph/token boundaries. Bill provides live Nomic and OpenAI database purge/reimport proofs.

## OpenAI embedding batches

Configure a separate profile in the **same cache file** to preserve existing Nomic vectors:

```yaml
survos_ai_embed:
    enabled: true
    cache_path: '%env(resolve:AI_EMBED_CACHE)%'
    provider: openai
    model: text-embedding-3-small
    revision: alias-generation-2026-11-22
    dimensions: 1536
    document_prefix: ''
    query_prefix: ''
    api_key: '%env(OPENAI_API_KEY)%'
```

Call `ensureDocumentEmbedding()` to queue cache misses, then:

```bash
php bin/console ai:embed:batch:submit
php bin/console ai:embed:batch:collect
```

Submission writes JSONL with stable request hashes as `custom_id`, uploads it, and creates one `/v1/embeddings` Batch API job. Collection polls known jobs, archives terminal output/error lines in `.jsonl.gz`, validates IDs/model/dimensions, and reconciles results independent of order. Both commands return without HTTP calls when there is no work. `ai:embed:consume` remains the local Ollama path and refuses pending OpenAI requests.

`embedding_batches` stores local IDs, file/remote IDs, lifecycle state and archive paths. `embedding_batch_requests` records immutable membership. BatchHttpClient records each outgoing API attempt in `provider_calls` without credentials or request bodies. Input and output archives live beside the cache under `<cache>.batches/<localId>/`. Raw outputs preserve usage, returned model and provider errors.

Successful results, failed/missing-request markings and reconciliation state commit together. Replaying an applied job is a no-op. Failed, expired or cancelled jobs retain valid partial results; failed/missing requests require explicit `ai:embed:batch:submit --retry-failed`. No automatic paid retry loop is installed.

Use `ai:embed:batch:resume LOCAL_ID` for prepared/uploaded work. If creation times out after dispatch, state remains `submitting` and automatic resubmission is forbidden. Find the remote job by its `embeddingBatchId` metadata and recover it with `ai:embed:batch:attach LOCAL_ID REMOTE_ID`; the client verifies file, endpoint and profile metadata first. A lost upload response can leave an unused remote input file; retries may upload another file, but do not duplicate a known/uncertain generation job.

The adapter reuses ai-batch-bundle's `OpenAiBatchClient`, `BatchRequest`, `BatchJob` and `BatchResult`. It registers that client directly: consumers using this adapter alone need not enable `TacmanAiBatchBundle`, its Doctrine entity or scheduler. Remove that bundle's automatic Flex registration if only the embedding adapter is wanted. No shared ai-batch-bundle behavior was changed.

DuckDB is optional at installation: `survos/ai-duckdb-store` is a Composer suggestion and a development dependency for bundle tests. Apps that use this backend must explicitly `composer require survos/ai-duckdb-store` and enable `ext-pdo_duckdb`. The bundle remains disabled by default and opens its file lazily. Attempting to open a cache without the adapter or driver throws an actionable exception before creating directories, locks, or database files; it never silently drops work. DuckDB is currently the only implemented cache backend.

OpenAI documents a 24-hour batch completion window. Bill's verified 25-request fixture completed in 133 seconds, but that is an observation, not a latency guarantee. Query vectors should eventually use a foreground cache-first path with the same model/dimensions rather than waiting on a provider batch.

References: [OpenAI Batch API](https://developers.openai.com/api/docs/guides/batch), [embedding model](https://developers.openai.com/api/docs/models/text-embedding-3-small).

## Durable S3 snapshots: push, pull, merge

`EmbeddingStorageService` exposes three console commands through method-level `#[AsCommand]` attributes:

```sh
php bin/console embed:push survos-sites/packages
php bin/console embed:pull survos-sites/packages
php bin/console embed:merge survos-sites/packages
# Pin a historical publication rather than the latest pointer:
php bin/console embed:pull survos-sites/packages --snapshot=<sha256>
```

These commands are available even when `enabled: false` disables embedding generation. They never call a model. The local working file remains `cache_path`; it must be on persistent storage in production. S3 stores closed, checksummed snapshots for backup and exchange, not the live writable database.

**Shared Survos location (created 2026-11-22):**

- Bucket: **`survos-embeddings`**
- Region: **`fsn1`** (Hetzner Falkenstein)
- S3 endpoint: **`https://fsn1.your-objectstorage.com`**
- Packages namespace: **`s3://survos-embeddings/survos-sites/packages/`**

These names are public configuration; bucket objects are private. Credentials are secrets and belong in `.env.local`, environment variables or the deployment secret store. The bundle has no hard-coded bucket or credentials. The commands do not create buckets.

Install the optional adapter in apps that use S3:

```sh
composer require league/flysystem-aws-s3-v3
```

```yaml
survos_ai_embed:
    cache_path: '%kernel.project_dir%/var/embeddings/packages.duckdb'
    s3:
        bucket: survos-embeddings
        region: fsn1
        endpoint: https://fsn1.your-objectstorage.com
        access_key: '%env(EMBEDDINGS_S3_ACCESS_KEY)%'
        secret_key: '%env(EMBEDDINGS_S3_SECRET_KEY)%'
        path_style: true
        # prefix: embeddings  # optional, relative to bucket root
```

The adapter uses the same Flysystem AWS S3 implementation as storage-bundle and folio storage. You can instead reuse an existing storage-bundle/Flysystem service, including a local filesystem for offline exchange/testing:

```yaml
survos_ai_embed:
    remote_storage: embeddings.storage
```

`remote_storage` takes precedence over `s3`. Without either configuration the commands fail with an actionable error; container boot and unrelated embedding operations do not contact S3. On AWS, omitting both explicit credential fields uses the SDK credential chain; optional `session_token` is supported.

### Publication layout and behavior

```text
survos-sites/packages/
  snapshots/<sha256>.duckdb
  snapshots/<sha256>.json
  latest.json
```

A namespace identifies the producing application/repository, not a model. Each snapshot may hold multiple isolated model profiles. Manifests contain schema version, namespace, byte size, SHA-256, profile IDs, completed-vector/document counts, and publication time. Document references and exact source/input text remain inside the database. The latest pointer is written only after the data and historical manifest succeed. Concurrent publishers are last-writer-wins for that pointer; serialize publishers or merge the latest publication first. Prior content-addressed snapshots remain available. There is no automatic retention/deletion policy.

- **Push** holds the cooperating local cache lock and builds a separate database containing ready vectors and only complete document/chunk mappings. It excludes pending/failed requests, provider-call history, batch ownership and raw provider response archives. Profiles, exact inputs, source text, tokenizer/chunker metadata and vectors are preserved. The original working database is not replaced. Empty publications are refused.
- **Pull** streams to a temporary file, verifies checksum/size and validates model/input/document identities and vector dimensions before atomic installation. It only installs into an absent local database or returns a no-op for an identical file. Divergent files, an open connection, a WAL, or an active file lock are rejected. There is no force-overwrite flag.
- **Merge** downloads/verifies the same immutable snapshot, then transactionally imports completed vectors and mappings through the existing cache. Matching request IDs/vectors are reused; a conflicting vector for the same exact input/profile aborts and rolls back. Pending local requests may become ready from imported results, but requests in locally owned unreconciled batches are refused. Local-only vectors, pending work and provider audit history remain intact.

Merge currently stages the snapshot locally; it does **not** issue remote DuckDB queries against S3. This avoids transferring credentials into SQL and lets the full file checksum be verified before import. Working databases and pending-job backups should be retained separately: published snapshots intentionally cannot resume another machine's queue.

Packages verified a real upload → fresh pull → zero-call fixture reload on 2026-11-22: 156 vectors from seven files, with identical projections and no model calls. An idempotent merge reused all 156. Bundle tests additionally cover multiple profiles, incomplete documents, corrupt downloads, namespace validation, merge rollback, active batch ownership, and file locking.
