<?php

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

/**
 * Indexing module.
 *
 * Internal infrastructure for content indexing:
 * - Embeddings HTTP client (index/delete/reindex-pending calls to central API).
 * - Content processing (hashing, chunking, post meta tracking).
 * - Post lifecycle hooks (auto-index on publish, delete on trash/unpublish).
 * - Bulk initial indexing (WP-Cron batches + manual trigger).
 * - Reindex polling (hourly WP-Cron to recover failed vector verifications).
 * - Routines_Indexing (WordPress hook registration).
 *
 * None of this is exposed through a public interface.
 * Consumer plugins use the semantic `search` task instead.
 */

require_once __DIR__ . "/contracts.php";


// ==========================================================================
// INTERFACES (module-private)
// ==========================================================================
//
// (none)


// ==========================================================================
// TRAITS
// ==========================================================================
//
// (none)


// ==========================================================================
// ENUMS
// ==========================================================================
//
// (none)


// ==========================================================================
// CLASSES
// ==========================================================================

/**
 * HTTP client for embeddings API calls (index/delete/reindex-pending).
 * Synchronous — no job polling needed for these endpoints.
 *
 * Delegates transport concerns (retries, HTTP 500 + was_accepted rule,
 * JSON decoding) to Engine_Transport so this client stays aligned with
 * the LLM/Semantic engines and the canonical error taxonomy.
 */
final class Indexing_EmbeddingsClient {
    private const ERROR_CONFIG_MISSING = "config_missing";
    // Partial-success marker: when the API accepts some chunks but reports
    // failed_chunks > 0 without a structured error.code, we still need to
    // signal failure so callers do not store the content hash (which would
    // mark the post as fully indexed on incomplete data). provider_error
    // is the canonical code for "AI provider failed to process the job".
    private const ERROR_PARTIAL_FAILURE = "provider_error";
    // Space-identity fetch errors. A non-2xx /space response is unexpected
    // (the endpoint is documented to always answer 200); a 2xx body missing
    // a usable identity is treated as invalid rather than accepted, so a
    // garbled response can never seed a bad baseline or hide a real change.
    private const ERROR_SPACE_HTTP = "space_http_error";
    private const ERROR_SPACE_INVALID = "space_response_invalid";

    private Config_StoreInterface $configStore;
    private Engine_SiteIdResolverInterface $siteIdResolver;
    private Engine_TransportInterface $transport;
    // The /v1/embeddings/space endpoint is unauthenticated and non-billable,
    // so fetchSpace() calls this raw client directly instead of going through
    // Engine_Transport (which wraps every other call with the user_key
    // envelope and retry/error machinery). The reference is retained solely
    // for that one bypass — see fetchSpace().
    private Http_ClientInterface $httpClient;

    public function __construct(
        Config_StoreInterface $configStore,
        Http_ClientInterface $httpClient,
        Diagnostics_StoreInterface $diagnostics
    ) {
        $this->configStore = $configStore;
        $this->siteIdResolver = new Engine_SiteIdResolver();
        $this->httpClient = $httpClient;
        $this->transport = new Engine_Transport($httpClient, $configStore, $diagnostics);
    }

    /**
     * Index content chunks via POST /v1/embeddings/index.
     *
     * @param array $chunks Array of chunk payloads (source_type, entity_id, field, chunk_ord, content_version, text).
     * @return array{accepted_chunks: int, failed_chunks: int, error: ?string}
     */
    public function indexChunks(array $chunks): array {
        $endpoint = $this->configStore->getEndpoint();
        $userKey = $this->configStore->getUserKey();
        if ($endpoint === null || $userKey === null) {
            return ["accepted_chunks" => 0, "failed_chunks" => \count($chunks), "error" => self::ERROR_CONFIG_MISSING];
        }
        $payload = [
            "user_key" => $userKey,
            "site_id" => $this->siteIdResolver->resolve($this->transport->readHomeUrl()),
            "chunks" => $chunks,
        ];
        Engine_HumanDemoBridge::mint("semantic_index", \rtrim($endpoint, "/") . "/v1/embeddings/index", $payload);
        $response = $this->transport->sendRequest(\rtrim($endpoint, "/") . "/v1/embeddings/index", $payload);
        if ($response["result"] === null) {
            return ["accepted_chunks" => 0, "failed_chunks" => \count($chunks), "error" => $response["error"]];
        }
        $decoded = $response["result"];
        $envelopeError = $this->transport->readErrorCode($decoded);
        if ($envelopeError !== null) {
            return ["accepted_chunks" => 0, "failed_chunks" => \count($chunks), "error" => $envelopeError];
        }
        $accepted = isset($decoded["accepted_chunks"]) && \is_int($decoded["accepted_chunks"]) ? $decoded["accepted_chunks"] : 0;
        $failed = isset($decoded["failed_chunks"]) && \is_int($decoded["failed_chunks"]) ? $decoded["failed_chunks"] : 0;
        // Treat any failed_chunks as a (partial) failure. Otherwise lifecycle
        // would store the content hash and mark the post as fully indexed
        // even though some chunks never landed — a silent search-data-loss.
        return [
            "accepted_chunks" => $accepted,
            "failed_chunks" => $failed,
            "error" => $failed > 0 ? self::ERROR_PARTIAL_FAILURE : null,
        ];
    }

    /**
     * Delete embeddings via POST /v1/embeddings/delete.
     *
     * @param array $selectors Array of selector objects (source_type, entity_id, optionally field).
     * @return array{deleted: int, error: ?string}
     */
    public function deleteBySelectors(array $selectors): array {
        $endpoint = $this->configStore->getEndpoint();
        $userKey = $this->configStore->getUserKey();
        if ($endpoint === null || $userKey === null) {
            return ["deleted" => 0, "error" => self::ERROR_CONFIG_MISSING];
        }
        $payload = [
            "user_key" => $userKey,
            "site_id" => $this->siteIdResolver->resolve($this->transport->readHomeUrl()),
            "selectors" => $selectors,
        ];
        Engine_HumanDemoBridge::mint("semantic_delete", \rtrim($endpoint, "/") . "/v1/embeddings/delete", $payload);
        $response = $this->transport->sendRequest(\rtrim($endpoint, "/") . "/v1/embeddings/delete", $payload);
        if ($response["result"] === null) {
            return ["deleted" => 0, "error" => $response["error"]];
        }
        $decoded = $response["result"];
        $envelopeError = $this->transport->readErrorCode($decoded);
        if ($envelopeError !== null) {
            return ["deleted" => 0, "error" => $envelopeError];
        }
        return [
            "deleted" => isset($decoded["deleted"]) && \is_int($decoded["deleted"]) ? $decoded["deleted"] : 0,
            "error" => null,
        ];
    }

    /**
     * Fetch entities pending reindex via POST /v1/embeddings/reindex/pending.
     *
     * @return array{pending_entities: array, next_cursor: ?string, poll_after_seconds: int, error: ?string}
     */
    public function fetchReindexPending(?string $cursor, int $limit): array {
        $endpoint = $this->configStore->getEndpoint();
        $userKey = $this->configStore->getUserKey();
        if ($endpoint === null || $userKey === null) {
            return ["pending_entities" => [], "next_cursor" => null, "poll_after_seconds" => 3600, "error" => self::ERROR_CONFIG_MISSING];
        }
        $payload = [
            "user_key" => $userKey,
            "site_id" => $this->siteIdResolver->resolve($this->transport->readHomeUrl()),
            "cursor" => $cursor,
            "limit" => $limit,
        ];
        Engine_HumanDemoBridge::mint("semantic_reindex", \rtrim($endpoint, "/") . "/v1/embeddings/reindex/pending", $payload);
        $response = $this->transport->sendRequest(\rtrim($endpoint, "/") . "/v1/embeddings/reindex/pending", $payload);
        if ($response["result"] === null) {
            return ["pending_entities" => [], "next_cursor" => null, "poll_after_seconds" => 3600, "error" => $response["error"]];
        }
        $decoded = $response["result"];
        $envelopeError = $this->transport->readErrorCode($decoded);
        if ($envelopeError !== null) {
            return ["pending_entities" => [], "next_cursor" => null, "poll_after_seconds" => 3600, "error" => $envelopeError];
        }
        return [
            "pending_entities" => isset($decoded["pending_entities"]) && \is_array($decoded["pending_entities"]) ? $decoded["pending_entities"] : [],
            "next_cursor" => isset($decoded["next_cursor"]) && \is_string($decoded["next_cursor"]) ? $decoded["next_cursor"] : null,
            "poll_after_seconds" => isset($decoded["poll_after_seconds"]) && \is_int($decoded["poll_after_seconds"]) ? $decoded["poll_after_seconds"] : 3600,
            "error" => null,
        ];
    }

    /**
     * Fetch the deployment's opaque embedding-space identity via the
     * unauthenticated, non-billable GET /v1/embeddings/space.
     *
     * This is the ONE method that bypasses Engine_Transport on purpose: the
     * endpoint takes no auth and no body, so it needs only getEndpoint()
     * (never getUserKey()) and goes straight through the raw Http client. Do
     * NOT reroute it through the transport — that path attaches the user_key
     * envelope and would make a public, keyless poll fail as config_missing
     * before a key is ever entered.
     *
     * Contract invariant: error === null IFF model_id and version are both
     * present, non-empty strings. A non-2xx response or a 2xx body lacking a
     * usable model_id/version yields an error with null identity fields, so
     * the caller can gate purely on error === null and never mistake a
     * garbled identity for a valid one.
     *
     * @return array{model_id: ?string, version: ?string, error: ?string}
     */
    public function fetchSpace(): array {
        $endpoint = $this->configStore->getEndpoint();
        if ($endpoint === null) {
            return ["model_id" => null, "version" => null, "error" => self::ERROR_CONFIG_MISSING];
        }
        $response = $this->httpClient->getJson(\rtrim($endpoint, "/") . "/v1/embeddings/space");
        if ($response->getError() !== null) {
            return ["model_id" => null, "version" => null, "error" => $response->getError()];
        }
        $status = $response->getStatus();
        if ($status < 200 || $status >= 300) {
            return ["model_id" => null, "version" => null, "error" => self::ERROR_SPACE_HTTP];
        }
        $decoded = \json_decode($response->getBody(), true);
        if (!\is_array($decoded)) {
            return ["model_id" => null, "version" => null, "error" => self::ERROR_SPACE_INVALID];
        }
        $modelId = isset($decoded["model_id"]) && \is_string($decoded["model_id"]) && $decoded["model_id"] !== "" ? $decoded["model_id"] : null;
        $version = isset($decoded["version"]) && \is_string($decoded["version"]) && $decoded["version"] !== "" ? $decoded["version"] : null;
        if ($modelId === null || $version === null) {
            return ["model_id" => null, "version" => null, "error" => self::ERROR_SPACE_INVALID];
        }
        return ["model_id" => $modelId, "version" => $version, "error" => null];
    }
}

/**
 * Content processing: hashing, chunking, post meta tracking.
 *
 * Not final: the search-hit resolver's per-post memoization invariant
 * ("re-chunk each post at most once per response") is observable only by
 * spying on chunker invocations. Tests instrument this via a subclass
 * that increments a counter inside chunkPost(). Production code never
 * extends this class.
 */
class Indexing_ContentProcessor {
    public const META_INDEXED_HASH = "sole_engine_indexed_hash";
    private const MAX_CHUNK_CHARS = 1500;

    // The hash is purely content-based for now. The API contract says
    // content_version should change whenever content OR chunking policy
    // changes; the policy-version dimension is deliberately deferred to a
    // future epoch because activating it without a corresponding bulk
    // rehash-all path would silently break every existing indexed site:
    // post meta would still hold legacy-format hashes that the bulk indexer
    // (which only queues posts with NO stored hash) would never re-queue,
    // and every search hit would mismatch the regenerated content_version
    // and be dropped as stale. See epochs.txt for the open question.
    public function computeContentHash(\WP_Post $post): string {
        $raw = $this->stripHtml($post->post_title)
            . "\n" . $this->stripHtml($post->post_content)
            . "\n" . $this->stripHtml($post->post_excerpt);
        return \hash("sha256", $raw);
    }

    public function getStoredHash(int $postId): ?string {
        $value = \get_post_meta($postId, self::META_INDEXED_HASH, true);
        if (\is_string($value) && $value !== "") {
            return $value;
        }
        return null;
    }

    public function storeHash(int $postId, string $hash): void {
        \update_post_meta($postId, self::META_INDEXED_HASH, $hash);
    }

    public function removeHash(int $postId): void {
        \delete_post_meta($postId, self::META_INDEXED_HASH);
    }

    /**
     * Clear the stored content hash for EVERY post in one query. This forces
     * the bulk indexer (which queues only posts with no stored hash) to
     * re-select and re-embed the entire corpus — the single mechanism behind
     * both the space-change auto-reindex and the manual "Reindex everything"
     * control. Mirrors the hash clear the site-purge handler performs.
     * Idempotent: a second call on already-cleared meta is a no-op.
     */
    public function removeAllHashes(): void {
        if (\function_exists("delete_post_meta_by_key")) {
            \delete_post_meta_by_key(self::META_INDEXED_HASH);
        }
    }

    public function needsIndexing(\WP_Post $post): bool {
        $current = $this->computeContentHash($post);
        $stored = $this->getStoredHash($post->ID);
        return $stored !== $current;
    }

    /**
     * Chunk a post into indexable pieces.
     *
     * @return array<array{field: string, chunk_ord: int, text: string}>
     */
    public function chunkPost(\WP_Post $post): array {
        $chunks = [];
        $ord = 0;

        $title = $this->stripHtml($post->post_title);
        if ($title !== "") {
            $chunks[] = ["field" => "post_title", "chunk_ord" => $ord, "text" => $title];
            $ord++;
        }

        $content = $this->stripHtml($post->post_content);
        if ($content !== "") {
            $contentChunks = $this->chunkText($content);
            foreach ($contentChunks as $chunk) {
                $chunks[] = ["field" => "post_content", "chunk_ord" => $ord, "text" => $chunk];
                $ord++;
            }
        }

        $excerpt = $this->stripHtml($post->post_excerpt);
        if ($excerpt !== "") {
            $chunks[] = ["field" => "post_excerpt", "chunk_ord" => $ord, "text" => $excerpt];
        }

        return $chunks;
    }

    /**
     * Build the full chunk payload for the embeddings API.
     *
     * @return array Array of chunk objects ready for the API.
     */
    public function buildChunkPayload(\WP_Post $post, string $contentVersion): array {
        $rawChunks = $this->chunkPost($post);
        $apiChunks = [];
        foreach ($rawChunks as $chunk) {
            $apiChunks[] = [
                "source_type" => "wp_post",
                "entity_id" => $post->ID,
                "field" => $chunk["field"],
                "chunk_ord" => $chunk["chunk_ord"],
                "content_version" => $contentVersion,
                "text" => $chunk["text"],
            ];
        }
        return $apiChunks;
    }

    private function stripHtml(string $text): string {
        return \trim(\wp_strip_all_tags($text));
    }

    /**
     * Tiered text chunking: paragraphs → sentences → hard split.
     *
     * @return string[]
     */
    private function chunkText(string $text): array {
        $paragraphs = \preg_split('/\n{2,}/', $text);
        if ($paragraphs === false) {
            $paragraphs = [$text];
        }
        $paragraphs = \array_values(\array_filter(\array_map("trim", $paragraphs), function (string $p): bool {
            return $p !== "";
        }));

        $result = [];
        foreach ($paragraphs as $paragraph) {
            if (\mb_strlen($paragraph) <= self::MAX_CHUNK_CHARS) {
                $result[] = $paragraph;
                continue;
            }
            $sentences = $this->splitSentences($paragraph);
            foreach ($sentences as $sentence) {
                if (\mb_strlen($sentence) <= self::MAX_CHUNK_CHARS) {
                    $result[] = $sentence;
                } else {
                    $hardParts = $this->hardSplit($sentence);
                    foreach ($hardParts as $part) {
                        $result[] = $part;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Split on sentence boundaries (latin and CJK punctuation).
     *
     * @return string[]
     */
    private function splitSentences(string $text): array {
        $parts = \preg_split('/(?<=[.!?\x{3002}\x{FF01}\x{FF1F}])\s+/u', $text);
        if ($parts === false) {
            return [$text];
        }
        return \array_values(\array_filter(\array_map("trim", $parts), function (string $s): bool {
            return $s !== "";
        }));
    }

    /**
     * Hard split at MAX_CHUNK_CHARS on nearest word boundary (50% minimum).
     *
     * @return string[]
     */
    private function hardSplit(string $text): array {
        $parts = [];
        $remaining = $text;
        while (\mb_strlen($remaining) > self::MAX_CHUNK_CHARS) {
            $chunk = \mb_substr($remaining, 0, self::MAX_CHUNK_CHARS);
            $lastSpace = \mb_strrpos($chunk, " ");
            $minPos = (int) (self::MAX_CHUNK_CHARS * 0.5);
            if ($lastSpace !== false && $lastSpace >= $minPos) {
                $parts[] = \mb_substr($remaining, 0, $lastSpace);
                $remaining = \ltrim(\mb_substr($remaining, $lastSpace));
            } else {
                $parts[] = $chunk;
                $remaining = \ltrim(\mb_substr($remaining, self::MAX_CHUNK_CHARS));
            }
        }
        if ($remaining !== "") {
            $parts[] = $remaining;
        }
        return $parts;
    }
}

/**
 * Post lifecycle hooks: auto-index on publish, delete on trash/unpublish.
 */
final class Indexing_PostLifecycle {
    private Indexing_ContentProcessor $processor;
    private Indexing_EmbeddingsClient $client;
    private Config_StoreInterface $configStore;
    private Diagnostics_StoreInterface $diagnostics;

    public function __construct(
        Indexing_ContentProcessor $processor,
        Indexing_EmbeddingsClient $client,
        Config_StoreInterface $configStore,
        Diagnostics_StoreInterface $diagnostics
    ) {
        $this->processor = $processor;
        $this->client = $client;
        $this->configStore = $configStore;
        $this->diagnostics = $diagnostics;
    }

    public function onPostSaved(int $postId, \WP_Post $post, bool $update): void {
        if (!$this->configStore->isSemanticEnabled()) {
            return;
        }
        if ($post->post_status !== "publish") {
            return;
        }
        if (!$this->isPublicPostType($post->post_type)) {
            return;
        }
        if (!$this->processor->needsIndexing($post)) {
            return;
        }
        $hash = $this->processor->computeContentHash($post);
        $chunks = $this->processor->buildChunkPayload($post, $hash);
        if (\count($chunks) === 0) {
            return;
        }
        $result = $this->client->indexChunks($chunks);
        if ($result["error"] === null) {
            $this->processor->storeHash($postId, $hash);
            $this->recordSuccess();
        } else {
            $this->recordError($result["error"], "indexing: post " . $postId . " (" . $post->post_type . ") failed: " . $result["error"]);
        }
    }

    public function onPostDeleted(int $postId): void {
        if (!$this->configStore->isSemanticEnabled()) {
            return;
        }
        $result = $this->client->deleteBySelectors([["source_type" => "wp_post", "entity_id" => $postId]]);
        if ($result["error"] !== null) {
            $this->recordError($result["error"], "indexing: delete post " . $postId . " failed: " . $result["error"]);
        }
        $this->processor->removeHash($postId);
    }

    public function onStatusTransition(string $newStatus, string $oldStatus, \WP_Post $post): void {
        if (!$this->configStore->isSemanticEnabled()) {
            return;
        }
        if ($oldStatus === "publish" && $newStatus !== "publish") {
            $result = $this->client->deleteBySelectors([["source_type" => "wp_post", "entity_id" => $post->ID]]);
            if ($result["error"] !== null) {
                $this->recordError($result["error"], "indexing: unpublish post " . $post->ID . " delete failed: " . $result["error"]);
            }
            $this->processor->removeHash($post->ID);
        }
    }

    private function recordSuccess(): void {
        $this->diagnostics->recordSuccess(\gmdate("c"));
    }

    private function recordError(string $code, string $context): void {
        $this->diagnostics->recordError($code, $context, \gmdate("c"));
    }

    private function isPublicPostType(string $postType): bool {
        if (!\function_exists("get_post_type_object")) {
            return false;
        }
        $typeObj = \get_post_type_object($postType);
        return $typeObj !== null && $typeObj->public === true;
    }
}

/**
 * Bulk initial indexing via WP-Cron batches.
 */
final class Indexing_BulkIndexer {
    public const CRON_HOOK = "sole_engine_bulk_index_batch";
    private const BATCH_SIZE = 20;
    private const STALE_THRESHOLD_SECONDS = 300;
    private const OPTION_LAST_BATCH_RUN = "sole_engine_index_last_batch_run";
    private const OPTION_BULK_INDEX_NEEDED = "sole_engine_bulk_index_needed";

    private Indexing_ContentProcessor $processor;
    private Indexing_EmbeddingsClient $client;
    private Config_StoreInterface $configStore;
    private Diagnostics_StoreInterface $diagnostics;

    public function __construct(
        Indexing_ContentProcessor $processor,
        Indexing_EmbeddingsClient $client,
        Config_StoreInterface $configStore,
        Diagnostics_StoreInterface $diagnostics
    ) {
        $this->processor = $processor;
        $this->client = $client;
        $this->configStore = $configStore;
        $this->diagnostics = $diagnostics;
    }

    public function maybeSchedule(): void {
        if (!$this->configStore->isSemanticEnabled()) {
            $this->unschedule();
            return;
        }
        $pending = $this->countPendingPosts();
        if ($pending === 0) {
            $this->unschedule();
            \delete_option(self::OPTION_BULK_INDEX_NEEDED);
            return;
        }
        \update_option(self::OPTION_BULK_INDEX_NEEDED, "1", false);
        if (!\wp_next_scheduled(self::CRON_HOOK)) {
            \wp_schedule_event(\time(), "sole_engine_every_minute", self::CRON_HOOK);
        }
    }

    /**
     * Force a full reindex of the entire corpus: clear every stored content
     * hash so the bulk path re-selects and re-embeds all published posts,
     * overwriting their vectors under the current embedding space. Used by
     * the space-change auto-reindex (Indexing_SpacePoller) and the manual
     * admin "Reindex everything" control.
     *
     * Guarded on semantic-enabled: clearing hashes while semantic is disabled
     * would strand the corpus, because maybeSchedule() unschedules the cron
     * when disabled and the cleared posts would never be re-indexed until
     * semantic is re-enabled. When disabled this is a no-op returning 0.
     *
     * Idempotent: removeAllHashes() is idempotent and maybeSchedule() only
     * schedules when no run is already queued, so repeated calls never
     * double-schedule or orphan state — the property processPoll() relies on
     * to retry safely after a partial failure.
     *
     * @return int Posts pending after the re-queue: the full corpus when
     *             semantic is enabled, 0 when disabled.
     */
    public function reindexAll(): int {
        if (!$this->configStore->isSemanticEnabled()) {
            return 0;
        }
        $this->processor->removeAllHashes();
        $this->maybeSchedule();
        return $this->countPendingPosts();
    }

    /**
     * Whether the bulk-index cron is currently scheduled. Lets a caller
     * confirm that a reindexAll() actually queued the work — wp_schedule_event()
     * can fail (a vetoing cron_schedules filter, a cron-option write failure)
     * and maybeSchedule() does not surface that.
     */
    public function isBulkScheduled(): bool {
        return \wp_next_scheduled(self::CRON_HOOK) !== false;
    }

    /**
     * Process one batch of unindexed posts.
     *
     * @return int Number of posts processed in this batch.
     */
    public function processBatch(): int {
        if (!$this->configStore->isSemanticEnabled()) {
            return 0;
        }
        $posts = $this->getUnindexedPosts(self::BATCH_SIZE);
        if (\count($posts) === 0) {
            $this->unschedule();
            \delete_option(self::OPTION_BULK_INDEX_NEEDED);
            return 0;
        }
        $processed = 0;
        foreach ($posts as $post) {
            $hash = $this->processor->computeContentHash($post);
            $chunks = $this->processor->buildChunkPayload($post, $hash);
            if (\count($chunks) === 0) {
                $this->processor->storeHash($post->ID, $hash);
                $processed++;
                continue;
            }
            $result = $this->client->indexChunks($chunks);
            if ($result["error"] !== null) {
                $this->recordError($result["error"], "indexing: bulk post " . $post->ID . " (" . $post->post_type . ") failed: " . $result["error"]);
                break;
            }
            $this->processor->storeHash($post->ID, $hash);
            $this->recordSuccess();
            $processed++;
        }
        \update_option(self::OPTION_LAST_BATCH_RUN, (string) \time(), false);
        if ($processed >= \count($posts)) {
            $remaining = $this->countPendingPosts();
            if ($remaining === 0) {
                $this->unschedule();
                \delete_option(self::OPTION_BULK_INDEX_NEEDED);
            }
        }
        return $processed;
    }

    public function countPendingPosts(): int {
        global $wpdb;
        $publicTypes = $this->getPublicPostTypes();
        if (\count($publicTypes) === 0) {
            return 0;
        }
        $placeholders = \implode(",", \array_fill(0, \count($publicTypes), "%s"));
        $metaKey = Indexing_ContentProcessor::META_INDEXED_HASH;
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_status = 'publish'
             AND p.post_type IN ($placeholders)
             AND pm.meta_value IS NULL",
            \array_merge([$metaKey], $publicTypes)
        );
        return (int) $wpdb->get_var($sql);
    }

    public function isCronStale(): bool {
        $needed = \get_option(self::OPTION_BULK_INDEX_NEEDED);
        if ($needed !== "1") {
            return false;
        }
        $pending = $this->countPendingPosts();
        if ($pending === 0) {
            return false;
        }
        $lastRun = \get_option(self::OPTION_LAST_BATCH_RUN);
        if (!\is_string($lastRun) || $lastRun === "") {
            return true;
        }
        return (\time() - (int) $lastRun) > self::STALE_THRESHOLD_SECONDS;
    }

    public function getLastBatchRun(): ?string {
        $value = \get_option(self::OPTION_LAST_BATCH_RUN);
        if (\is_string($value) && $value !== "") {
            return $value;
        }
        return null;
    }

    private function recordSuccess(): void {
        $this->diagnostics->recordSuccess(\gmdate("c"));
    }

    private function recordError(string $code, string $context): void {
        $this->diagnostics->recordError($code, $context, \gmdate("c"));
    }

    /**
     * @return \WP_Post[]
     */
    private function getUnindexedPosts(int $limit): array {
        $publicTypes = $this->getPublicPostTypes();
        if (\count($publicTypes) === 0) {
            return [];
        }
        $query = new \WP_Query([
            "post_type" => $publicTypes,
            "post_status" => "publish",
            "posts_per_page" => $limit,
            "meta_query" => [
                [
                    "key" => Indexing_ContentProcessor::META_INDEXED_HASH,
                    "compare" => "NOT EXISTS",
                ],
            ],
            "orderby" => "ID",
            "order" => "ASC",
            "no_found_rows" => true,
            "update_post_meta_cache" => false,
            "update_post_term_cache" => false,
        ]);
        return $query->posts;
    }

    /**
     * @return string[]
     */
    private function getPublicPostTypes(): array {
        if (!\function_exists("get_post_types")) {
            return [];
        }
        $types = \get_post_types(["public" => true], "names");
        return \is_array($types) ? \array_values($types) : [];
    }

    private function unschedule(): void {
        $next = \wp_next_scheduled(self::CRON_HOOK);
        if ($next !== false) {
            \wp_unschedule_event($next, self::CRON_HOOK);
        }
    }
}

/**
 * Polls the engine for entities that need reindexing due to failed vector verification.
 */
final class Indexing_ReindexPoller {
    public const CRON_HOOK = "sole_engine_reindex_poll";
    private const PAGE_LIMIT = 50;
    private const MAX_ENTITIES = 200;
    private const OPTION_LAST_POLL = "sole_engine_reindex_last_poll";

    private Indexing_ContentProcessor $processor;
    private Indexing_EmbeddingsClient $client;
    private Config_StoreInterface $configStore;
    private Diagnostics_StoreInterface $diagnostics;

    public function __construct(
        Indexing_ContentProcessor $processor,
        Indexing_EmbeddingsClient $client,
        Config_StoreInterface $configStore,
        Diagnostics_StoreInterface $diagnostics
    ) {
        $this->processor = $processor;
        $this->client = $client;
        $this->configStore = $configStore;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Fetch pending reindex entities from the engine and re-submit or clean up each one.
     *
     * @return int Number of entities processed.
     */
    public function processPending(): int {
        if (!$this->configStore->isSemanticEnabled()) {
            return 0;
        }
        $allEntities = [];
        $cursor = null;
        $unsupportedCount = 0;
        $invalidIdCount = 0;
        do {
            $page = $this->client->fetchReindexPending($cursor, self::PAGE_LIMIT);
            if ($page["error"] !== null) {
                $this->recordError($page["error"], "reindex_poll: fetch failed: " . $page["error"]);
                break;
            }
            foreach ($page["pending_entities"] as $entity) {
                $sourceType = isset($entity["source_type"]) && \is_string($entity["source_type"]) ? $entity["source_type"] : "";
                if ($sourceType !== "wp_post") {
                    $unsupportedCount++;
                    continue;
                }
                $entityId = isset($entity["entity_id"]) && \is_numeric($entity["entity_id"]) ? (int) $entity["entity_id"] : 0;
                if ($entityId <= 0) {
                    $invalidIdCount++;
                    continue;
                }
                $allEntities[] = [
                    "source_type" => $sourceType,
                    "entity_id" => $entityId,
                ];
                if (\count($allEntities) >= self::MAX_ENTITIES) {
                    break 2;
                }
            }
            $cursor = $page["next_cursor"];
        } while ($cursor !== null);

        if ($unsupportedCount > 0) {
            $this->recordError(
                "reindex_poll_unsupported_source_type",
                "reindex_poll: skipped " . $unsupportedCount . " non-wp_post entities"
            );
        }
        if ($invalidIdCount > 0) {
            $this->recordError(
                "reindex_poll_invalid_entity_id",
                "reindex_poll: skipped " . $invalidIdCount . " entities with invalid entity_id"
            );
        }

        $processed = 0;
        foreach ($allEntities as $entity) {
            $entityId = (int) $entity["entity_id"];
            $processed++;
            $post = \function_exists("get_post") ? \get_post($entityId) : null;
            if (!($post instanceof \WP_Post) || $post->post_status !== "publish" || !$this->isPublicPostType($post->post_type)) {
                $deleteResult = $this->client->deleteBySelectors([["source_type" => "wp_post", "entity_id" => $entityId]]);
                if ($deleteResult["error"] !== null) {
                    $this->recordError(
                        $deleteResult["error"],
                        "reindex_poll: post " . $entityId . " cleanup delete failed: " . $deleteResult["error"]
                    );
                }
                continue;
            }
            $hash = $this->processor->computeContentHash($post);
            $chunks = $this->processor->buildChunkPayload($post, $hash);
            if (\count($chunks) === 0) {
                $this->processor->storeHash($post->ID, $hash);
                continue;
            }
            $result = $this->client->indexChunks($chunks);
            if ($result["error"] !== null) {
                $this->recordError($result["error"], "reindex_poll: post " . $entityId . " reindex failed: " . $result["error"]);
                continue;
            }
            $this->processor->storeHash($post->ID, $hash);
            $this->recordSuccess();
        }

        // Always record the last-run timestamp, even if fetch failed partway.
        // Partial failures are surfaced via the error log; the admin "last run"
        // timestamp answers "did the cron fire?" which is useful independent
        // of whether every page succeeded.
        \update_option(self::OPTION_LAST_POLL, (string) \time(), false);
        return $processed;
    }

    public function getLastPoll(): ?string {
        $value = \get_option(self::OPTION_LAST_POLL);
        if (\is_string($value) && $value !== "") {
            return $value;
        }
        return null;
    }

    public function maybeSchedule(): void {
        if (!$this->configStore->isSemanticEnabled()) {
            $this->unschedule();
            return;
        }
        if (!\wp_next_scheduled(self::CRON_HOOK)) {
            \wp_schedule_event(\time(), "hourly", self::CRON_HOOK);
        }
    }

    private function unschedule(): void {
        $next = \wp_next_scheduled(self::CRON_HOOK);
        if ($next !== false) {
            \wp_unschedule_event($next, self::CRON_HOOK);
        }
    }

    private function isPublicPostType(string $postType): bool {
        if (!\function_exists("get_post_type_object")) {
            return false;
        }
        $typeObj = \get_post_type_object($postType);
        return $typeObj !== null && $typeObj->public === true;
    }

    private function recordSuccess(): void {
        $this->diagnostics->recordSuccess(\gmdate("c"));
    }

    private function recordError(string $code, string $context): void {
        $this->diagnostics->recordError($code, $context, \gmdate("c"));
    }
}

/**
 * Polls the deployment's embedding-space identity and triggers a full
 * reindex of the corpus when it changes.
 *
 * The central API exposes GET /v1/embeddings/space returning an opaque
 * { model_id, version }: model_id changes iff the embedding model or dims
 * change; version is a manual operator lever for non-model invalidations
 * (e.g. chunking policy). A semantic search is only comparable to corpus
 * vectors embedded by the SAME model, so when the space changes the whole
 * corpus must be re-embedded — the API keeps no raw text, only the plugin
 * can re-submit content. The API filters searches to the canonical model,
 * so a change degrades gracefully (fewer/no semantic hits, native-search
 * fallback) rather than corrupting; this poller heals it automatically.
 *
 * Cadence: hourly, mirroring Indexing_ReindexPoller. Model/chunking changes
 * are rare, operator-driven, non-emergency events, so sub-hour latency buys
 * nothing; /space is non-billable so polling is free.
 *
 * The persisted baseline (OPTION_SPACE_IDENTITY) is an OPAQUE token: only
 * ever compared for equality, never split or parsed back into its parts. A
 * future change to how the token is composed therefore triggers at most one
 * graceful reindex on upgrade, with no parsing ambiguity.
 */
final class Indexing_SpacePoller {
    public const CRON_HOOK = "sole_engine_space_poll";
    private const OPTION_SPACE_IDENTITY = "sole_engine_space_identity";
    private const OPTION_LAST_POLL = "sole_engine_space_last_poll";

    private Indexing_EmbeddingsClient $client;
    private Indexing_BulkIndexer $bulkIndexer;
    private Config_StoreInterface $configStore;
    private Diagnostics_StoreInterface $diagnostics;

    public function __construct(
        Indexing_EmbeddingsClient $client,
        Indexing_BulkIndexer $bulkIndexer,
        Config_StoreInterface $configStore,
        Diagnostics_StoreInterface $diagnostics
    ) {
        $this->client = $client;
        $this->bulkIndexer = $bulkIndexer;
        $this->configStore = $configStore;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Poll /space, compare against the stored baseline, and on change trigger
     * a full reindex.
     *
     * Decision table:
     *  - semantic disabled    -> 0 (nothing touched)
     *  - fetch error          -> 0, error recorded, baseline UNTOUCHED
     *                            (a transient outage must never read as a
     *                            space change)
     *  - baseline unset       -> seed it, record an info diagnostic, 0
     *                            (no reindex — the corpus is assumed current;
     *                            the manual control is the escape hatch if a
     *                            model change preceded this plugin version)
     *  - baseline === current -> 0 (no-op)
     *  - baseline !== current -> reindexAll() first, THEN advance the
     *                            baseline, record the change, return 1
     *
     * Ordering guarantee: the baseline is advanced only AFTER reindexAll()
     * returns AND the bulk reindex is confirmed queued. If reindexAll() fatals
     * mid-way, or the bulk cron failed to schedule, the baseline stays old and
     * the next hourly poll re-detects the change and re-runs the idempotent
     * reindex — the system self-heals with no silent stale state.
     *
     * @return int 1 if a reindex was triggered, otherwise 0.
     */
    public function processPoll(): int {
        if (!$this->configStore->isSemanticEnabled()) {
            return 0;
        }
        $space = $this->client->fetchSpace();
        if ($space["error"] !== null) {
            $this->recordError($space["error"], "space_poll: fetch failed: " . $space["error"]);
            return 0;
        }
        // error === null guarantees model_id and version are non-empty strings.
        // Encode the pair as JSON rather than concatenating with a separator:
        // the values are opaque, so a length-injective encoding is required to
        // guarantee distinct identities never collapse to the same token.
        $current = \wp_json_encode(["model_id" => $space["model_id"], "version" => $space["version"]]);
        $stored = \get_option(self::OPTION_SPACE_IDENTITY);

        if (!\is_string($stored) || $stored === "") {
            \update_option(self::OPTION_SPACE_IDENTITY, $current, false);
            // Seeding is a notable, auditable event: it makes the
            // model-changed-before-upgrade window visible (the admin sees a
            // fresh seed and can use Reindex everything) and audits a manual
            // option deletion.
            $this->recordError(
                "space_identity_seeded",
                "space_poll: baseline embedding-space identity seeded (no reindex)"
            );
            \update_option(self::OPTION_LAST_POLL, (string) \time(), false);
            return 0;
        }

        if ($stored === $current) {
            \update_option(self::OPTION_LAST_POLL, (string) \time(), false);
            return 0;
        }

        // Space changed: re-embed the whole corpus, THEN advance the baseline.
        $pending = $this->bulkIndexer->reindexAll();
        // Defensive guard: reindexAll() no-ops and returns 0 without clearing
        // hashes when semantic is disabled. If semantic were toggled off between
        // the top-of-method guard and here, advancing the baseline would record
        // "no change" on the next poll and silently strand the corpus on the old
        // space — the self-heal is foreclosed. Re-read through a method boundary
        // so this remains a fresh temporal observation rather than a restatement
        // of the entry guard. The mid-poll test pins the distinct second read.
        if (!$this->isSemanticEnabledAfterReindex()) {
            return 0;
        }
        // Confirm the reindex was actually queued before advancing the baseline.
        // reindexAll() reports the corpus as pending, but wp_schedule_event()
        // can fail silently (a vetoing filter, a cron-option write failure),
        // leaving hashes cleared with no cron. Advancing the baseline then would
        // record "no change" next poll and strand the corpus. Leaving it old
        // makes the next hourly poll retry (and isCronStale() warns the admin).
        if ($pending > 0 && !$this->bulkIndexer->isBulkScheduled()) {
            $this->recordError(
                "space_reindex_unscheduled",
                "space_poll: space changed but the bulk reindex cron is not scheduled; baseline not advanced, will retry next poll"
            );
            return 0;
        }
        \update_option(self::OPTION_SPACE_IDENTITY, $current, false);
        $this->recordError(
            "space_changed_reindex",
            "space_poll: embedding space changed (" . $stored . " -> " . $current . "); full reindex queued (" . $pending . " posts)"
        );
        \update_option(self::OPTION_LAST_POLL, (string) \time(), false);
        return 1;
    }

    private function isSemanticEnabledAfterReindex(): bool {
        return $this->configStore->isSemanticEnabled();
    }

    public function maybeSchedule(): void {
        if (!$this->configStore->isSemanticEnabled()) {
            $this->unschedule();
            return;
        }
        if (!\wp_next_scheduled(self::CRON_HOOK)) {
            \wp_schedule_event(\time(), "hourly", self::CRON_HOOK);
        }
    }

    public function getLastPoll(): ?string {
        $value = \get_option(self::OPTION_LAST_POLL);
        // The setter only ever writes (string) time(); a non-numeric value is
        // corruption — surface it as "never polled" rather than letting the
        // admin display cast it to a misleading 1970-01-01.
        if (\is_string($value) && $value !== "" && \is_numeric($value)) {
            return $value;
        }
        return null;
    }

    public function getSpaceIdentity(): ?string {
        $value = \get_option(self::OPTION_SPACE_IDENTITY);
        if (\is_string($value) && $value !== "") {
            return $value;
        }
        return null;
    }

    private function unschedule(): void {
        $next = \wp_next_scheduled(self::CRON_HOOK);
        if ($next !== false) {
            \wp_unschedule_event($next, self::CRON_HOOK);
        }
    }

    private function recordError(string $code, string $context): void {
        $this->diagnostics->recordError($code, $context, \gmdate("c"));
    }
}

/**
 * Resolves the chunk text for a semantic-search hit by re-running the
 * canonical chunker against the post that owned the hit. The API never
 * carries chunk_text on the wire (it stores vectors only); the plugin
 * reconstructs the text locally so consumers receive meaningful results.
 *
 * Per-request memoization: re-chunks each post at most once across a
 * single response, regardless of how many hits target it. A 100-hit
 * search returning chunks from the same post collapses to a single
 * chunkPost() call.
 *
 * Determinism contract: as long as the chunker algorithm and the post
 * content are unchanged, resolveChunkText reproduces the exact text that
 * was originally embedded at index time. content_version is currently
 * content-only — the API contract says it should also change when the
 * chunking policy changes, but activating that without a bulk
 * rehash-all migration would break existing indexed sites (see
 * Indexing_ContentProcessor::computeContentHash). Deferred to a future
 * epoch.
 */
final class Indexing_SearchHitResolver implements Indexing_SearchHitResolverInterface {
    private Indexing_ContentProcessor $processor;

    /** @var array<int, array<string, array<int, string>>> post_id => field => chunk_ord => text */
    private array $chunkCache = [];

    public function __construct(Indexing_ContentProcessor $processor) {
        $this->processor = $processor;
    }

    public function computeContentVersion(\WP_Post $post): string {
        return $this->processor->computeContentHash($post);
    }

    public function resolveChunkText(\WP_Post $post, string $field, int $chunkOrd): ?string {
        $map = $this->getChunkMap($post);
        if (!isset($map[$field][$chunkOrd])) {
            return null;
        }
        return $map[$field][$chunkOrd];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function getChunkMap(\WP_Post $post): array {
        if (isset($this->chunkCache[$post->ID])) {
            return $this->chunkCache[$post->ID];
        }
        $map = [];
        foreach ($this->processor->chunkPost($post) as $chunk) {
            $map[$chunk["field"]][$chunk["chunk_ord"]] = $chunk["text"];
        }
        $this->chunkCache[$post->ID] = $map;
        return $map;
    }
}

/**
 * Routine: registers indexing WordPress hooks, cron, and AJAX handlers.
 */
final class Routines_Indexing implements Routines_RoutineInterface {
    public function execute(): void {
        $configFactory = new Config_Factory();
        $configStore = $configFactory->makeStore();

        if (!$configStore->isSemanticEnabled()) {
            // Unschedule all three indexing crons when semantic is disabled (map.md Flow E).
            // Leaving a cron scheduled would fire it hitting a hook with no handler
            // registered after disable.
            $nextReindex = \wp_next_scheduled(Indexing_ReindexPoller::CRON_HOOK);
            if ($nextReindex !== false) {
                \wp_unschedule_event($nextReindex, Indexing_ReindexPoller::CRON_HOOK);
            }
            $nextBulk = \wp_next_scheduled(Indexing_BulkIndexer::CRON_HOOK);
            if ($nextBulk !== false) {
                \wp_unschedule_event($nextBulk, Indexing_BulkIndexer::CRON_HOOK);
            }
            $nextSpace = \wp_next_scheduled(Indexing_SpacePoller::CRON_HOOK);
            if ($nextSpace !== false) {
                \wp_unschedule_event($nextSpace, Indexing_SpacePoller::CRON_HOOK);
            }
            return;
        }

        $processor = new Indexing_ContentProcessor();
        $httpFactory = new Http_Factory();
        $diagnosticsFactory = new Diagnostics_Factory();
        $diagnostics = $diagnosticsFactory->makeStore();

        // Lifecycle hooks use a shorter timeout (inline during save).
        $lifecycleClient = new Indexing_EmbeddingsClient($configStore, $httpFactory->makeClient(15), $diagnostics);
        $lifecycle = new Indexing_PostLifecycle($processor, $lifecycleClient, $configStore, $diagnostics);

        \add_action("wp_after_insert_post", function (int $postId, \WP_Post $post, bool $update) use ($lifecycle): void {
            $lifecycle->onPostSaved($postId, $post, $update);
        }, 10, 3);

        \add_action("before_delete_post", function (int $postId) use ($lifecycle): void {
            $lifecycle->onPostDeleted($postId);
        }, 10, 1);

        \add_action("transition_post_status", function (string $newStatus, string $oldStatus, \WP_Post $post) use ($lifecycle): void {
            $lifecycle->onStatusTransition($newStatus, $oldStatus, $post);
        }, 10, 3);

        // Bulk indexer uses a longer timeout.
        $bulkClient = new Indexing_EmbeddingsClient($configStore, $httpFactory->makeClient(30), $diagnostics);
        $bulkIndexer = new Indexing_BulkIndexer($processor, $bulkClient, $configStore, $diagnostics);

        // Custom cron interval.
        \add_filter("cron_schedules", function (array $schedules): array {
            if (!isset($schedules["sole_engine_every_minute"])) {
                $schedules["sole_engine_every_minute"] = [
                    "interval" => 60,
                    "display" => "Every Minute (Sole Engine)",
                ];
            }
            return $schedules;
        });

        // Cron batch handler.
        \add_action(Indexing_BulkIndexer::CRON_HOOK, function () use ($bulkIndexer): void {
            $bulkIndexer->processBatch();
        });

        // AJAX: trigger one batch manually.
        \add_action("wp_ajax_sole_engine_bulk_index_trigger", function () use ($bulkIndexer): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
            $processed = $bulkIndexer->processBatch();
            $pending = $bulkIndexer->countPendingPosts();
            \wp_send_json_success([
                "processed" => $processed,
                "pending" => $pending,
            ]);
        });

        // AJAX: get index status.
        \add_action("wp_ajax_sole_engine_index_status", function () use ($bulkIndexer): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
            \wp_send_json_success([
                "pending" => $bulkIndexer->countPendingPosts(),
                "last_batch_run" => $bulkIndexer->getLastBatchRun(),
                "cron_stale" => $bulkIndexer->isCronStale(),
            ]);
        });

        // Reindex poller: hourly cron to recover entities with failed vector verification.
        $reindexPoller = new Indexing_ReindexPoller($processor, $bulkClient, $configStore, $diagnostics);

        \add_action(Indexing_ReindexPoller::CRON_HOOK, function () use ($reindexPoller): void {
            $reindexPoller->processPending();
        });

        // Space poller: hourly cron that detects an embedding-space change
        // (model/version) and auto-reindexes the whole corpus.
        $spacePoller = new Indexing_SpacePoller($bulkClient, $bulkIndexer, $configStore, $diagnostics);

        // WordPress owns request-to-screen routing. The settings routine emits
        // the exact suffix returned by add_options_page(); this routine owns the
        // three scheduler instances and binds one capability-gated screen load.
        \add_action(
            "sole_engine_settings_page_registered",
            function (string $hookSuffix) use ($bulkIndexer, $reindexPoller, $spacePoller): void {
                \add_action("load-" . $hookSuffix, function () use ($bulkIndexer, $reindexPoller, $spacePoller): void {
                    if (!\current_user_can("manage_options")) {
                        return;
                    }
                    $bulkIndexer->maybeSchedule();
                    $reindexPoller->maybeSchedule();
                    $spacePoller->maybeSchedule();
                });
            },
            10,
            1
        );

        \add_action(Indexing_SpacePoller::CRON_HOOK, function () use ($spacePoller): void {
            $spacePoller->processPoll();
        });

        // AJAX: manually force a full reindex of the entire corpus. Shares the
        // clear-hashes-then-bulk-re-embed primitive with the space-change
        // auto-reindex; useful for recovery and for any future plugin-side
        // chunking-policy change.
        \add_action("wp_ajax_sole_engine_reindex_all_trigger", function () use ($bulkIndexer): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
            $pending = $bulkIndexer->reindexAll();
            \wp_send_json_success([
                "pending" => $pending,
            ]);
        });
    }
}
