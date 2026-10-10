<?php

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

   
                   
  
                                                
                                                                                
                                                                
                                                                             
                                                              
                                                                             
                                                     
  
                                                      
                                                           
   

require_once __DIR__ . "/contracts.php";


                                                                             
                              
                                                                             
  
         


                                                                             
         
                                                                             
  
         


                                                                             
        
                                                                             
  
         


                                                                             
          
                                                                             

   
                                                                       
                                                             
  
                                                                       
                                                                       
                                                             
   
final class Indexing_EmbeddingsClient {
    private const ERROR_CONFIG_MISSING = "config_missing";
                                                                           
                                                                          
                                                                           
                                                                         
                                                                         
    private const ERROR_PARTIAL_FAILURE = "provider_error";
                                                                           
                                                                            
                                                                         
                                                                            
    private const ERROR_SPACE_HTTP = "space_http_error";
    private const ERROR_SPACE_INVALID = "space_response_invalid";

    private Config_StoreInterface $configStore;
    private Engine_SiteIdResolverInterface $siteIdResolver;
    private Engine_TransportInterface $transport;
                                                                             
                                                                              
                                                                       
                                                                            
                                                
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
                                                                              
                                                                          
                                                                              
        return [
            "accepted_chunks" => $accepted,
            "failed_chunks" => $failed,
            "error" => $failed > 0 ? self::ERROR_PARTIAL_FAILURE : null,
        ];
    }

       
                                                        
      
                                                                                                    
                                                  
       
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

   
                                                             
  
                                                                      
                                                                         
                                                                      
                                                                      
                      
   
class Indexing_ContentProcessor {
    public const META_INDEXED_HASH = "sole_engine_indexed_hash";
    private const MAX_CHUNK_CHARS = 1500;

                                                                      
                                                                        
                                                                          
                                                                      
                                                                        
                                                                            
                                                                          
                                                                          
                                                                     
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

       
                                                                
      
                       
       
    private function splitSentences(string $text): array {
        $parts = \preg_split('/(?<=[.!?\x{3002}\x{FF01}\x{FF1F}])\s+/u', $text);
        if ($parts === false) {
            return [$text];
        }
        return \array_values(\array_filter(\array_map("trim", $parts), function (string $s): bool {
            return $s !== "";
        }));
    }

       
                                                                            
      
                       
       
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

       
                                                                            
                                                                          
                                                                           
                                                                          
                                          
      
                                                                              
                                                                            
                                                                          
                                                                         
      
                                                                           
                                                                       
                                                                               
                                               
      
                                                                         
                                                        
       
    public function reindexAll(): int {
        if (!$this->configStore->isSemanticEnabled()) {
            return 0;
        }
        $this->processor->removeAllHashes();
        $this->maybeSchedule();
        return $this->countPendingPosts();
    }

       
                                                                        
                                                                                   
                                                                              
                                                 
       
    public function isBulkScheduled(): bool {
        return \wp_next_scheduled(self::CRON_HOOK) !== false;
    }

       
                                            
      
                                                           
       
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

       
                                                                              
                      
      
                      
                                                     
                                                                       
                                                                          
                                               
                                                                       
                                                                                
                                                                             
                                                                            
                                           
                                                                      
                                                                       
      
                                                                           
                                                                               
                                                                               
                                                                            
                                                                    
      
                                                             
       
    public function processPoll(): int {
        if (!$this->configStore->isSemanticEnabled()) {
            return 0;
        }
        $space = $this->client->fetchSpace();
        if ($space["error"] !== null) {
            $this->recordError($space["error"], "space_poll: fetch failed: " . $space["error"]);
            return 0;
        }
                                                                                
                                                                              
                                                                               
                                                                          
        $current = \wp_json_encode(["model_id" => $space["model_id"], "version" => $space["version"]]);
        $stored = \get_option(self::OPTION_SPACE_IDENTITY);

        if (!\is_string($stored) || $stored === "") {
            \update_option(self::OPTION_SPACE_IDENTITY, $current, false);
                                                                  
                                                                            
                                                                             
                               
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

                                                                               
        $pending = $this->bulkIndexer->reindexAll();
                                                                              
                                                                                 
                                                                                
                                                                                 
                                                                                   
                                                                                 
                                                                               
        if (!$this->isSemanticEnabledAfterReindex()) {
            return 0;
        }
                                                                                 
                                                                              
                                                                             
                                                                                 
                                                                             
                                                                                
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

   
                                                                      
                                                                       
                                                                      
                                                                         
  
                                                                     
                                                                    
                                                                   
                    
  
                                                                      
                                                                         
                                                                      
                                                                        
                                                              
                                                               
                                                                       
         
   
final class Indexing_SearchHitResolver implements Indexing_SearchHitResolverInterface {
    private Indexing_ContentProcessor $processor;

                                                                                                   
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

   
                                                                        
   
final class Routines_Indexing implements Routines_RoutineInterface {
    public function execute(): void {
        $configFactory = new Config_Factory();
        $configStore = $configFactory->makeStore();

        if (!$configStore->isSemanticEnabled()) {
                                                                                             
                                                                                    
                                        
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

                                              
        $bulkClient = new Indexing_EmbeddingsClient($configStore, $httpFactory->makeClient(30), $diagnostics);
        $bulkIndexer = new Indexing_BulkIndexer($processor, $bulkClient, $configStore, $diagnostics);

                                
        \add_filter("cron_schedules", function (array $schedules): array {
            if (!isset($schedules["sole_engine_every_minute"])) {
                $schedules["sole_engine_every_minute"] = [
                    "interval" => 60,
                    "display" => "Every Minute (Sole Engine)",
                ];
            }
            return $schedules;
        });

                              
        \add_action(Indexing_BulkIndexer::CRON_HOOK, function () use ($bulkIndexer): void {
            $bulkIndexer->processBatch();
        });

                                            
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

                                                                                           
        $reindexPoller = new Indexing_ReindexPoller($processor, $bulkClient, $configStore, $diagnostics);

        \add_action(Indexing_ReindexPoller::CRON_HOOK, function () use ($reindexPoller): void {
            $reindexPoller->processPending();
        });

                                                                           
                                                               
        $spacePoller = new Indexing_SpacePoller($bulkClient, $bulkIndexer, $configStore, $diagnostics);

                                                                               
                                                                                 
                                                                                
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
