<?php

declare(strict_types=1);

// This file declares BOTH namespaces the plugin uses, and the braces are
// deliberate: they put the boundary between what is published and what is ours
// where a reader cannot miss it, and let PHP enforce it.
//
// The global block below is the plugin's entire published type surface. Consumer
// plugins type-hint these exact names, so they are declared globally for real —
// not aliased. Everything else lives in SoleEngineWP and cannot collide with
// anything on the site. The allow-list is proposed as a Landmarks section in
// epoch4_plan.md (delta 2), awaiting the Ministry of Engine.

namespace {
    if (!defined("ABSPATH")) {
        exit;
    }

    // Public engine access points for consumer plugins.
    interface SoleEngineLLMInterface {
        public function submit(string $callerId, string $taskName, string $mainPayload, ?array $options = null): SoleEngineJobTicketInterface;
        public function getResult(string $jobId): SoleEngineReplyStringInterface;
    }

    // Public engine access points for semantic tasks.
    interface SoleEngineSemanticInterface {
        public function submit(string $callerId, string $taskName, string $mainPayload, ?array $options = null): SoleEngineJobTicketInterface;
        public function getResult(string $jobId): SoleEngineReplyStringInterface;
        public function getSiteId(): string;
    }

    // Read-only snapshot of whether the exact saved Engine credential is ready.
    interface SoleEngineCredentialReadinessInterface {
        public const STATE_MISSING = "missing";
        public const STATE_UNVERIFIED = "unverified";
        public const STATE_INVALID = "invalid";
        public const STATE_VERIFIED = "verified";

        public function getState(): string;
        public function isReady(): bool;
        public function getCheckedAt(): ?string;
    }

    // Job ticket returned when submitting a task.
    interface SoleEngineJobTicketInterface {
        public function wasAccepted(): bool;
        public function getJobId(): ?string;
        public function getError(): ?string;
    }

    // Base reply for job polling.
    interface SoleEngineReplyInterface {
        public function isPending(): bool;
        public function isSuccessful(): bool;
        public function getError(): ?string;
    }

    // Reply wrapper for text results.
    interface SoleEngineReplyStringInterface extends SoleEngineReplyInterface {
        public function getReply(): ?string;
    }

}

namespace SoleEngineWP {
    // Admin settings page slug. Shared between routines (registers the page) and
    // indexing (gates cron scheduling on this page). A literal mismatch here would
    // silently prevent the bulk-index and reindex-poll crons from ever scheduling.
    const SOLE_ENGINE_ADMIN_MENU_SLUG = "sole-engine-wp-settings";

    // Concrete engine interface for the LLM client implementation.
    interface Engine_LlmInterface extends \SoleEngineLLMInterface {}

    // Concrete engine interface for the semantic client implementation.
    interface Engine_SemanticInterface extends \SoleEngineSemanticInterface {}

    // Terminal reply for the synchronous bridge. Unlike SoleEngineReplyInterface
    // there is no isPending(): a synchronous call is structurally terminal
    // (success or failed), so advertising a pending state would be a dishonest
    // contract. See ADR-0002.
    //
    // Namespaced, not global: this pair and the accessor that returns them are
    // @internal, and Landmarks delta 3 (ministry ruling 2026-08-10 18:01) moved
    // them out of global scope so that the published claim matches the code.
    interface SoleEngineSyncReplyInterface {
        public function isSuccessful(): bool;
        public function getReply(): ?string;
        public function getError(): ?string;
    }

    // @internal — Synchronous generation bridge. Exists ONLY to satisfy the
    // WordPress 7 AI Client provider hook (generateTextResult() is synchronous).
    // NOT a consumer access point: every plugin that calls the SOLE engine
    // directly uses the asynchronous submit/poll path (SoleEngineLLMInterface).
    // See compass.md "Sole exception" and ADR-0002.
    interface SoleEngineLLMSyncInterface {
        public function generate(string $callerId, string $taskName, string $mainPayload, ?array $options = null): SoleEngineSyncReplyInterface;
    }

    // Concrete engine interface for the synchronous bridge implementation.
    interface Engine_LlmSyncInterface extends SoleEngineLLMSyncInterface {}

    // Core plugin bootstrap interface.
    interface Core_PluginInterface {
        public function boot(): void;
    }

    // Engine configuration store.
    interface Config_StoreInterface {
        public function getEndpoint(): ?string;
        public function getUserKey(): ?string;
        public function getTotalAttempts(): int;
        public function isSemanticEnabled(): bool;
        public function isCallerBlocked(string $callerId): bool;
    }

    // Factory for configuration stores.
    interface Config_FactoryInterface {
        public function makeStore(): Config_StoreInterface;
    }

    // Configuration for the WordPress 7 AI Client provider feature. Kept separate
    // from Config_StoreInterface so the shared config contract (and the consumer
    // plugins' test stubs of it) stay frozen — only the WP-provider code depends
    // on these. getSyncTimeout() bounds the synchronous bridge's HTTP wait;
    // isWpProviderEnabled() is the admin kill-switch for the whole provider surface;
    // isFrontEndRenderAllowed() lets the site owner decide whether a synchronous AI
    // call may run during a front-end page render (WordPress's provider model leaves
    // authorization to the caller — see adr.txt ADR-0002 addendum 2026-06-11,
    // resolving epochs.txt Epoch 2 open question 2).
    interface Config_WpAiInterface {
        public function getSyncTimeout(): int;
        public function isWpProviderEnabled(): bool;
        public function isFrontEndRenderAllowed(): bool;
    }

    // HTTP response wrapper for engine calls.
    interface Http_ResponseInterface {
        public function getStatus(): int;
        public function getBody(): string;
        public function getError(): ?string;
    }

    // HTTP client for engine calls.
    interface Http_ClientInterface {
        public function getJson(string $url): Http_ResponseInterface;
        public function postJson(string $url, array $payload): Http_ResponseInterface;
    }

    // HTTP client factory.
    interface Http_FactoryInterface {
        public function makeClient(int $timeoutSeconds): Http_ClientInterface;
    }

    // Routine interface for WordPress hook orchestration.
    interface Routines_RoutineInterface {
        public function execute(): void;
    }

    // Diagnostics store for recent engine status.
    interface Diagnostics_StoreInterface {
        public function recordSuccess(string $timestamp): void;
        // Records the last error and appends to the error log.
        public function recordError(string $errorCode, string $context, string $timestamp): void;
        public function getLastSuccess(): ?string;
        public function getLastError(): ?string;
        public function getLastErrorAt(): ?string;
        // Returns the error log as an array of entries (newest last).
        public function getErrorLog(): array;
        public function recordHealthStatus(string $status, string $timestamp): void;
        public function recordAccountStatus(string $status, array $quota, string $timestamp): void;
        public function getLastHealthStatus(): ?string;
        public function getLastHealthAt(): ?string;
        public function getLastAccountStatus(): ?string;
        public function getLastAccountAt(): ?string;
        public function getLastAccountQuota(): ?array;
        public function recordCallerCredits(string $callerId, float $credits): void;
        /** @return array<string, float> caller → lifetime credits */
        public function getCallerCredits(): array;
        public function resetCallerCredits(): void;
        // Credential evidence is bound to the exact key without storing it.
        public function recordCredentialEvidence(string $userKey, string $state, string $timestamp): void;
        /** @return array{state:string,checked_at:string}|null */
        public function getCredentialEvidence(string $userKey): ?array;
    }

    // Factory for diagnostics store.
    interface Diagnostics_FactoryInterface {
        public function makeStore(): Diagnostics_StoreInterface;
    }

    // Cache store for pending jobs and completed replies.
    interface Cache_StoreInterface {
        public function getJobIdForHash(string $hash): ?string;
        public function setJobIdForHash(string $hash, string $jobId, int $ttlSeconds): void;
        public function getHashForJobId(string $jobId): ?string;
        public function setHashForJobId(string $jobId, string $hash, int $ttlSeconds): void;
        public function getReplyForJob(string $jobId): ?array;
        public function setReplyForJob(string $jobId, array $reply, int $ttlSeconds): void;
        public function getSubmitErrorForHash(string $hash): ?string;
        public function setSubmitErrorForHash(string $hash, string $error, int $ttlSeconds): void;
    }

    // Factory for cache store.
    interface Cache_FactoryInterface {
        public function makeStore(): Cache_StoreInterface;
    }

    // Site id normalizer: canonical form for site URLs used as API namespace keys.
    interface Engine_SiteIdNormalizerInterface {
        public function normalize(string $rawUrl): string;
    }

    interface Engine_SiteIdentityReaderInterface {
        public function readPersistedHome(): ?string;
    }

    // Canonical Engine namespace resolver. The request-derived URL is used
    // only when the persisted WordPress home cannot be read.
    interface Engine_SiteIdResolverInterface {
        public function resolve(string $requestDerivedFallback): string;
    }

    // Shared transport helper for anything that talks to the central API. LLM,
    // Semantic and Indexing all delegate here so the hard-learnt HTTP 500 +
    // was_accepted rule, retry policy, and diagnostics formatting only have to
    // be maintained once. Module-public so the Indexing module can reuse it.
    interface Engine_TransportInterface {
        public const ERROR_NETWORK = "network_error";
        public const ERROR_API_INVALID = "api_response_invalid";

        // Send a JSON POST with retry; returns a decoded envelope or an error
        // descriptor. Shape: array{result: ?array, error: ?string, detail: string}.
        // A non-null `result` is the decoded API response (even for 500 replies
        // that carry the `was_accepted` envelope). A null `result` means the
        // request exhausted retries or produced an unparseable body — `error`
        // then holds a canonical code and `detail` a short diagnostic string.
        public function sendRequest(string $url, array $payload): array;

        // Compute the deterministic cache-dedupe hash for a submit. Endpoint and
        // user_key are included so changing either invalidates cached entries.
        public function buildHash(string $task, string $payload, ?array $options, string $siteId, string $userKey, string $endpoint): string;

        // Extract a structured error code from an API envelope, or null if absent.
        public function readErrorCode(array $decoded): ?string;

        // Build a diagnostics log context string for a failed API interaction.
        public function buildErrorContext(string $phase, array $decoded): string;

        // Canonical home_url() reader that survives non-WP contexts (empty string).
        public function readHomeUrl(): string;

        // Diagnostics passthroughs so engines only hold one collaborator reference.
        public function recordSuccess(): void;
        public function recordError(string $code, string $context): void;
    }

    // Resolver that lets the semantic engine reconstruct the matched chunk text
    // for a search hit without crossing the module boundary with raw chunk arrays.
    // The API never returns chunk_text on the wire — it only stores vectors and
    // returns coordinates (entity_id, field, chunk_ord, content_version). The
    // plugin owns the canonical chunking, so it can rebuild the exact text that
    // was embedded, as long as the post's current content_version still matches
    // the version stored at index time. The resolver answers two questions:
    //   1) Is this post's current content_version still X?
    //   2) What text sits at (field, chunk_ord) for this post under the current
    //      content?
    // Returning ?string lets the resolver report "no chunk at that ord" (a drift
    // signal) without inventing an error envelope.
    interface Indexing_SearchHitResolverInterface {
        public function computeContentVersion(\WP_Post $post): string;
        public function resolveChunkText(\WP_Post $post, string $field, int $chunkOrd): ?string;
    }
}
