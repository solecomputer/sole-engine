<?php

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

require_once __DIR__ . "/contracts.php";

// Module: Engine client stubs.
// Rationale: provide a minimal, fail-safe implementation for the public interfaces.


// ==========================================================================
// INTERFACES (module-private)
// ==========================================================================
// Error TTL resolver: returns the cache TTL in seconds for a given error code.
interface Engine_ErrorTtlResolverInterface {
    public function getTtlForError(string $errorCode): int;
}

// Engine job ticket implementation interface.
interface Engine_JobTicketInterface extends \SoleEngineJobTicketInterface {}

// Engine reply implementation for string replies.
interface Engine_ReplyStringInterface extends \SoleEngineReplyStringInterface {}
// Engine_TransportInterface is module-public (shared with Indexing) and lives in contracts.php.



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
// Error TTL resolver: tiered cache durations based on error nature.
// Rationale: transient errors (network, rate limit) clear quickly; permanent
// errors (invalid key, unsupported task) cache longer to prevent retry spam.
final class Engine_ErrorTtlResolver implements Engine_ErrorTtlResolverInterface {
    private const TTL_NONE = 0;
    private const TTL_INSTANT = 1;
    private const TTL_VERY_SHORT = 10;
    private const TTL_MEDIUM = 60;
    private const TTL_STANDARD = 180;
    private const TTL_LONG = 900;

    public function getTtlForError(string $errorCode): int {
        // Every code the Landmark declares carries an EXPLICIT arm here, even
        // where its value equals the default. A term satisfied only because the
        // default happens to match it is not satisfied — it is unfalsified,
        // which is weaker, and changing the default would break a published
        // promise with nothing noticing. Ratified 2026-08-19 (delta D4).
        return match ($errorCode) {
            // Never cached: a fault whose cure is an administrator action must
            // clear the instant the administrator acts. Caching one tells a
            // person who has already done the right thing that he did not.
            // Ratified posture, 2026-08-19 (delta D3).
            "user_key_invalid", "account_disabled" => self::TTL_NONE,
            // Never cached: refused locally, or transient by nature.
            "site_purging", "caller_blocked", "semantic_disabled" => self::TTL_NONE,
            "network_error" => self::TTL_INSTANT,
            // Transient upstream conditions: retry soon, do not hammer.
            "rate_limited", "provider_unavailable" => self::TTL_VERY_SHORT,
            "provider_error", "engine_internal" => self::TTL_MEDIUM,
            // Cached at the standard interval. quota_exceeded belongs here and
            // NOT with the administrator-cure codes above: its cure is TIME
            // rather than an action, so caching delays nothing a person could
            // have shortened.
            "quota_exceeded", "config_missing", "invalid_payload",
            "index_invalid", "job_not_found", "api_response_invalid" => self::TTL_STANDARD,
            "task_unsupported" => self::TTL_LONG,
            // `timeout` and `rates_missing` are RETIRED — declared in the
            // Landmark and never emitted by the engine. They have no arm on
            // purpose; see landmarks.md. Do not add one back without a
            // producer to justify it.
            default => self::TTL_STANDARD,
        };
    }
}

// Job ticket concrete: only two legal shapes, each produced by a named
// constructor. The private constructor makes illegal combinations like
// "accepted with no jobId" or "rejected with no error" unrepresentable.
final class Engine_JobTicket implements Engine_JobTicketInterface {
    private bool $wasAccepted;
    private ?string $jobId;
    private ?string $error;

    private function __construct(bool $wasAccepted, ?string $jobId, ?string $error) {
        $this->wasAccepted = $wasAccepted;
        $this->jobId = $jobId;
        $this->error = $error;
    }

    public static function accepted(string $jobId): self {
        return new self(true, $jobId, null);
    }

    public static function rejected(string $error): self {
        return new self(false, null, $error);
    }

    public function wasAccepted(): bool {
        return $this->wasAccepted;
    }

    public function getJobId(): ?string {
        return $this->jobId;
    }

    public function getError(): ?string {
        return $this->error;
    }
}

final class Engine_Llm implements Engine_LlmInterface {
    private const ERROR_CONFIG_MISSING = "config_missing";
    private const ERROR_PROVIDER = "provider_error";
    private const TTL_PENDING_SECONDS = 120;
    private const TTL_SUCCESS_SECONDS = 86400;
    // The DEDUP MAPPING is a different decision from the reply cache above and
    // carries its own name so the two can move independently. THE VALUE IS THE
    // SAME BY AGREEMENT, NOT BY INHERITANCE, and this is the whole point of the
    // split: it now takes a decision to change either one.
    // It matches the service's 24h job retention exactly, and equality is SAFE
    // because BOTH CLOCKS START AT THE SAME EVENT. I briefly held the opposite
    // and was refuted on evidence I checked myself in sole_engine_api:
    //   tasks.module.ts:1273 - the terminal write stamps
    //     `updated_at = charge.recorded_at`, i.e. the completion time;
    //   account_state.do.ts:785, :810, :1578, :1765 - all four expiry paths
    //     read `updated_at ?? created_at` and all four skip pending records.
    // So retention is counted from terminalization, not from creation, and a
    // mapping of exactly 86400 cannot outlive its job. Checked 2026-08-22,
    // including the question their report did not cover: whether EVERY
    // terminalization stamps the field, since the fallback is `created_at`.
    // It does - jobs.module.ts:186 stamps it on the failure path, and the one
    // unstamped write is a self-test mock on an in-memory store.
    // IF THAT COUPLING EVER MOVES, this must move with it; the failure mode is
    // `job_not_found`, which is a bound defect and never a wrong answer.
    private const TTL_DEDUP_SECONDS = 86400;

    private Cache_StoreInterface $cacheStore;
    private Config_StoreInterface $configStore;
    private Engine_SiteIdResolverInterface $siteIdResolver;
    private Engine_ErrorTtlResolverInterface $ttlResolver;
    private Engine_TransportInterface $transport;
    // Credit recording is engine-level business state, not transport concern;
    // keep a direct reference for recordCallerCredits calls.
    private Diagnostics_StoreInterface $diagnostics;

    public function __construct(
        Cache_StoreInterface $cacheStore,
        Config_StoreInterface $configStore,
        Http_ClientInterface $httpClient,
        Diagnostics_StoreInterface $diagnostics
    ) {
        $this->cacheStore = $cacheStore;
        $this->configStore = $configStore;
        $this->siteIdResolver = new Engine_SiteIdResolver();
        $this->ttlResolver = new Engine_ErrorTtlResolver();
        $this->transport = new Engine_Transport($httpClient, $configStore, $diagnostics);
        $this->diagnostics = $diagnostics;
    }

    public function submit(string $callerId, string $taskName, string $mainPayload, ?array $options = null): \SoleEngineJobTicketInterface {
        if ($this->configStore->isCallerBlocked($callerId)) {
            $this->transport->recordError("caller_blocked", "submit: blocked; caller: " . $callerId);
            return Engine_JobTicket::rejected("caller_blocked");
        }
        $endpoint = $this->configStore->getEndpoint();
        $userKey = $this->configStore->getUserKey();
        if ($endpoint === null || $userKey === null) {
            $this->transport->recordError(self::ERROR_CONFIG_MISSING, "submit: endpoint or user key not configured; caller: " . $callerId);
            return Engine_JobTicket::rejected(self::ERROR_CONFIG_MISSING);
        }
        $siteId = $this->siteIdResolver->resolve($this->transport->readHomeUrl());
        $hash = $this->transport->buildHash($taskName, $mainPayload, $options, $siteId, $userKey, $endpoint);
        $submitError = $this->cacheStore->getSubmitErrorForHash($hash);
        if ($submitError !== null) {
            return Engine_JobTicket::rejected($submitError);
        }
        $cachedJobId = $this->cacheStore->getJobIdForHash($hash);
        if ($cachedJobId !== null) {
            return Engine_JobTicket::accepted($cachedJobId);
        }
        $payload = [
            "user_key" => $userKey,
            "site_id" => $siteId,
            "task" => $taskName,
            "payload" => $mainPayload,
            "options" => $options,
            "requester" => $callerId
        ];
        $humanOperation = $callerId === "dolet-wp" && $taskName === "translate_batch"
            ? "dolet_submit"
            : "unallowlisted_submit";
        Engine_HumanDemoBridge::mint($humanOperation, $endpoint . "/v1/tasks/submit", $payload);
        $response = $this->transport->sendRequest($endpoint . "/v1/tasks/submit", $payload);
        $decoded = $response["result"];
        if ($decoded === null) {
            $error = $response["error"] ?? Engine_TransportInterface::ERROR_API_INVALID;
            $detail = $response["detail"] ?? "";
            $this->transport->recordError($error, "submit: failed for task " . $taskName . "; caller: " . $callerId . ($detail !== "" ? "; detail: " . $detail : ""));
            $ttl = $this->ttlResolver->getTtlForError($error);
            if ($ttl > 0) {
                $this->cacheStore->setSubmitErrorForHash($hash, $error, $ttl);
            }
            return Engine_JobTicket::rejected($error);
        }
        if (empty($decoded["was_accepted"])) {
            $errorCode = $this->transport->readErrorCode($decoded) ?? Engine_TransportInterface::ERROR_API_INVALID;
            $this->transport->recordError($errorCode, $this->transport->buildErrorContext("submit: rejected for task " . $taskName, $decoded) . "; caller: " . $callerId);
            $ttl = $this->ttlResolver->getTtlForError($errorCode);
            if ($ttl > 0) {
                $this->cacheStore->setSubmitErrorForHash($hash, $errorCode, $ttl);
            }
            return Engine_JobTicket::rejected($errorCode);
        }
        $jobId = isset($decoded["job_id"]) && \is_string($decoded["job_id"]) ? $decoded["job_id"] : null;
        if ($jobId === null || $jobId === "") {
            $this->transport->recordError(Engine_TransportInterface::ERROR_API_INVALID, "submit: response accepted but job_id missing for task " . $taskName . "; caller: " . $callerId);
            $ttl = $this->ttlResolver->getTtlForError(Engine_TransportInterface::ERROR_API_INVALID);
            if ($ttl > 0) {
                $this->cacheStore->setSubmitErrorForHash($hash, Engine_TransportInterface::ERROR_API_INVALID, $ttl);
            }
            return Engine_JobTicket::rejected(Engine_TransportInterface::ERROR_API_INVALID);
        }
        if (!Engine_HumanDemoBridge::bindJob($humanOperation, $jobId)) {
            return Engine_JobTicket::rejected("human_demo_job_binding_failed");
        }
        $this->cacheStore->setJobIdForHash($hash, $jobId, self::TTL_PENDING_SECONDS);
        $this->cacheStore->setHashForJobId($jobId, $hash, self::TTL_PENDING_SECONDS);
        $rawCredits = $decoded["usage"]["credits_total"] ?? null;
        $creditsTotal = \is_numeric($rawCredits) ? (float) $rawCredits : 0.0;
        if ($creditsTotal > 0) {
            $this->diagnostics->recordCallerCredits($callerId, $creditsTotal);
        }
        $this->transport->recordSuccess();
        return Engine_JobTicket::accepted($jobId);
    }

    public function getResult(string $jobId): \SoleEngineReplyStringInterface {
        $endpoint = $this->configStore->getEndpoint();
        $userKey = $this->configStore->getUserKey();
        if ($endpoint === null || $userKey === null) {
            $this->transport->recordError(self::ERROR_CONFIG_MISSING, "poll: endpoint or user key not configured");
            return Engine_ReplyString::failed(self::ERROR_CONFIG_MISSING);
        }
        $cachedReply = $this->cacheStore->getReplyForJob($jobId);
        if (\is_array($cachedReply)) {
            // Defensive: an upgrade from a pre-audit-fix version may leave
            // stale semantic_search success replies in WP transients. Those
            // entries embed chunk_text/post_title captured before the
            // read-side eligibility gate existed. Treat such entries as a
            // cache miss so the engine re-fetches and re-rehydrates
            // against current WP state. New writes for this reply type
            // are already suppressed; this read-side guard handles the
            // upgrade window. The same guard runs in the LLM engine too,
            // which is harmless (no LLM reply has type semantic_search)
            // and keeps both engines structurally identical.
            $cachedSuccess = isset($cachedReply["success"]) && $cachedReply["success"] === true;
            $cachedType = isset($cachedReply["reply_type"]) && \is_string($cachedReply["reply_type"]) ? $cachedReply["reply_type"] : "";
            if (!($cachedSuccess && $cachedType === "semantic_search")) {
                return $this->replyFromCache($cachedReply);
            }
        }
        $hash = $this->cacheStore->getHashForJobId($jobId);
        $siteId = $this->siteIdResolver->resolve($this->transport->readHomeUrl());
        $payload = [
            "user_key" => $userKey,
            "site_id" => $siteId,
            "job_id" => $jobId
        ];
        Engine_HumanDemoBridge::mint("result_poll", $endpoint . "/v1/tasks/result", $payload);
        $response = $this->transport->sendRequest($endpoint . "/v1/tasks/result", $payload);
        $decoded = $response["result"];
        if ($decoded === null) {
            $error = $response["error"] ?? Engine_TransportInterface::ERROR_API_INVALID;
            $detail = $response["detail"] ?? "";
            // When the result slot is null the decoded body is null too, so we have
            // nothing to summarize — just say so. Avoids "raw: null" noise.
            $contextDetail = $detail !== "" ? "; detail: " . $detail : "; no response detail captured";
            $this->transport->recordError($error, "poll: failed" . $contextDetail);
            $ttl = $this->ttlResolver->getTtlForError($error);
            if ($ttl > 0) {
                $this->cacheStore->setReplyForJob(
                    $jobId,
                    $this->buildCacheReply(false, null, null, $error),
                    $ttl
                );
            }
            return Engine_ReplyString::failed($error);
        }
        if (empty($decoded["was_accepted"])) {
            $errorCode = $this->transport->readErrorCode($decoded) ?? Engine_TransportInterface::ERROR_API_INVALID;
            $this->transport->recordError($errorCode, $this->transport->buildErrorContext("poll: was_accepted false or missing", $decoded));
            $ttl = $this->ttlResolver->getTtlForError($errorCode);
            if ($ttl > 0) {
                $this->cacheStore->setReplyForJob(
                    $jobId,
                    $this->buildCacheReply(false, null, null, $errorCode),
                    $ttl
                );
            }
            return Engine_ReplyString::failed($errorCode);
        }
        $state = isset($decoded["state"]) && \is_string($decoded["state"]) ? $decoded["state"] : "";
        if ($state === "pending") {
            if ($hash !== null) {
                $this->cacheStore->setJobIdForHash($hash, $jobId, self::TTL_PENDING_SECONDS);
                $this->cacheStore->setHashForJobId($jobId, $hash, self::TTL_PENDING_SECONDS);
            }
            return Engine_ReplyString::pending();
        }
        if ($state === "completed") {
            $reply = isset($decoded["reply"]) && \is_array($decoded["reply"]) ? $decoded["reply"] : null;
            $text = $reply !== null && isset($reply["text"]) && \is_string($reply["text"]) ? $reply["text"] : null;
            if ($text === null) {
                $this->transport->recordError(
                    self::ERROR_PROVIDER,
                    $this->transport->buildErrorContext("poll: completed but reply.text missing", $decoded)
                );
                $ttl = $this->ttlResolver->getTtlForError(self::ERROR_PROVIDER);
                if ($ttl > 0) {
                    $this->cacheStore->setReplyForJob(
                        $jobId,
                        $this->buildCacheReply(false, null, null, self::ERROR_PROVIDER),
                        $ttl
                    );
                }
                // Do NOT refresh hash→jobId on failure. The job is terminal —
                // refreshing would create a self-renewing cache loop that
                // permanently binds this content hash to a dead job.
                return Engine_ReplyString::failed(self::ERROR_PROVIDER);
            }
            $this->transport->recordSuccess();
            $this->cacheStore->setReplyForJob(
                $jobId,
                $this->buildCacheReply(true, "llm_text", $text, null),
                self::TTL_SUCCESS_SECONDS
            );
            if ($hash !== null) {
                $this->cacheStore->setJobIdForHash($hash, $jobId, self::TTL_DEDUP_SECONDS);
                $this->cacheStore->setHashForJobId($jobId, $hash, self::TTL_DEDUP_SECONDS);
            }
            return Engine_ReplyString::successful($text);
        }
        if ($state === "failed") {
            $errorCode = $this->transport->readErrorCode($decoded) ?? self::ERROR_PROVIDER;
            $this->transport->recordError($errorCode, $this->transport->buildErrorContext("poll: job failed", $decoded));
            $ttl = $this->ttlResolver->getTtlForError($errorCode);
            if ($ttl > 0) {
                $this->cacheStore->setReplyForJob(
                    $jobId,
                    $this->buildCacheReply(false, null, null, $errorCode),
                    $ttl
                );
            }
            // No hash→jobId refresh — terminal failure. Letting the hash
            // expire forces the next submit to create a fresh job instead
            // of re-polling a dead one in a self-renewing loop.
            return Engine_ReplyString::failed($errorCode);
        }
        $this->transport->recordError(
            self::ERROR_PROVIDER,
            $this->transport->buildErrorContext("poll: unexpected state", $decoded)
        );
        $ttl = $this->ttlResolver->getTtlForError(self::ERROR_PROVIDER);
        if ($ttl > 0) {
            $this->cacheStore->setReplyForJob(
                $jobId,
                $this->buildCacheReply(false, null, null, self::ERROR_PROVIDER),
                $ttl
            );
        }
        // No hash→jobId refresh — unrecognized state is treated as terminal.
        return Engine_ReplyString::failed(self::ERROR_PROVIDER);
    }

    private function buildCacheReply(
        bool $success,
        ?string $replyType,
        $replyValue,
        ?string $error
    ): array {
        return [
            "success" => $success,
            "reply_type" => $replyType,
            "reply_value" => $replyValue,
            "error" => $error
        ];
    }

    private function replyFromCache(array $cached): \SoleEngineReplyStringInterface {
        $success = isset($cached["success"]) ? (bool) $cached["success"] : false;
        $replyType = isset($cached["reply_type"]) && \is_string($cached["reply_type"]) ? $cached["reply_type"] : null;
        $replyValue = $cached["reply_value"] ?? null;
        $error = isset($cached["error"]) && \is_string($cached["error"]) ? $cached["error"] : null;
        if ($success) {
            if ($replyType === "llm_text" && \is_string($replyValue)) {
                return Engine_ReplyString::successful($replyValue);
            }
            // Cache record marked successful but shape is wrong. Log the
            // corruption so the underlying cache-write bug surfaces instead
            // of silently masquerading as a generic provider_error.
            $this->transport->recordError(
                self::ERROR_PROVIDER,
                "cache: llm success entry with unexpected shape (type=" . ($replyType ?? "null") . ", value=" . \gettype($replyValue) . ")"
            );
            return Engine_ReplyString::failed(self::ERROR_PROVIDER);
        }
        return Engine_ReplyString::failed($error ?? self::ERROR_PROVIDER);
    }

}

// @internal — Synchronous bridge for the WordPress 7 AI Client provider only
// (see compass.md "Sole exception" and ADR-0002). Unlike Engine_Llm it does NOT
// submit a job and poll: POST /v1/tasks/execute runs the LLM inline and returns
// the reply in the same response. It reuses Engine_Transport (forced to a single
// attempt) so the HTTP-500/was_accepted rule and diagnostics stay in one place,
// and it keeps NO cache — /v1/tasks/execute bills per call and does no
// server-side dedup, so a local cache would be a false economy.
final class Engine_LlmSync implements Engine_LlmSyncInterface {
    private const ERROR_CONFIG_MISSING = "config_missing";
    private const ERROR_PROVIDER = "provider_error";

    private Config_StoreInterface $configStore;
    private Engine_SiteIdResolverInterface $siteIdResolver;
    private Engine_TransportInterface $transport;
    // Credit recording is engine-level business state, not a transport concern.
    private Diagnostics_StoreInterface $diagnostics;

    public function __construct(
        Config_StoreInterface $configStore,
        Http_ClientInterface $httpClient,
        Diagnostics_StoreInterface $diagnostics
    ) {
        $this->configStore = $configStore;
        $this->siteIdResolver = new Engine_SiteIdResolver();
        // Single attempt (4th arg = 1): a blocking, human-facing call must not
        // multiply its wall time by retrying. The httpClient already carries the
        // configured sync timeout, which bounds the wait.
        $this->transport = new Engine_Transport($httpClient, $configStore, $diagnostics, 1);
        $this->diagnostics = $diagnostics;
    }

    public function generate(string $callerId, string $taskName, string $mainPayload, ?array $options = null): SoleEngineSyncReplyInterface {
        if ($this->configStore->isCallerBlocked($callerId)) {
            $this->transport->recordError("caller_blocked", "execute: blocked; caller: " . $callerId);
            return Engine_SyncReply::failed("caller_blocked");
        }
        $endpoint = $this->configStore->getEndpoint();
        $userKey = $this->configStore->getUserKey();
        if ($endpoint === null || $userKey === null) {
            $this->transport->recordError(self::ERROR_CONFIG_MISSING, "execute: endpoint or user key not configured; caller: " . $callerId);
            return Engine_SyncReply::failed(self::ERROR_CONFIG_MISSING);
        }
        $siteId = $this->siteIdResolver->resolve($this->transport->readHomeUrl());
        $payload = [
            "user_key" => $userKey,
            "site_id" => $siteId,
            "task" => $taskName,
            "payload" => $mainPayload,
            "options" => $options,
            "requester" => $callerId
        ];
        $response = $this->transport->sendRequest($endpoint . "/v1/tasks/execute", $payload);
        $decoded = $response["result"];
        if ($decoded === null) {
            $error = $response["error"] ?? Engine_TransportInterface::ERROR_API_INVALID;
            $detail = $response["detail"] ?? "";
            $contextDetail = $detail !== "" ? "; detail: " . $detail : "; no response detail captured";
            $this->transport->recordError($error, "execute: failed for task " . $taskName . "; caller: " . $callerId . $contextDetail);
            return Engine_SyncReply::failed($error);
        }
        if (empty($decoded["was_accepted"])) {
            $errorCode = $this->transport->readErrorCode($decoded) ?? Engine_TransportInterface::ERROR_API_INVALID;
            $this->transport->recordError($errorCode, $this->transport->buildErrorContext("execute: rejected for task " . $taskName, $decoded) . "; caller: " . $callerId);
            return Engine_SyncReply::failed($errorCode);
        }
        $state = isset($decoded["state"]) && \is_string($decoded["state"]) ? $decoded["state"] : "";
        if ($state === "completed") {
            $reply = isset($decoded["reply"]) && \is_array($decoded["reply"]) ? $decoded["reply"] : null;
            $text = $reply !== null && isset($reply["text"]) && \is_string($reply["text"]) ? $reply["text"] : null;
            if ($text === null) {
                $this->transport->recordError(self::ERROR_PROVIDER, $this->transport->buildErrorContext("execute: completed but reply.text missing", $decoded));
                return Engine_SyncReply::failed(self::ERROR_PROVIDER);
            }
            $rawCredits = $decoded["usage"]["credits_total"] ?? null;
            $creditsTotal = \is_numeric($rawCredits) ? (float) $rawCredits : 0.0;
            if ($creditsTotal > 0) {
                $this->diagnostics->recordCallerCredits($callerId, $creditsTotal);
            }
            $this->transport->recordSuccess();
            return Engine_SyncReply::successful($text);
        }
        if ($state === "failed") {
            $errorCode = $this->transport->readErrorCode($decoded) ?? self::ERROR_PROVIDER;
            $this->transport->recordError($errorCode, $this->transport->buildErrorContext("execute: job failed", $decoded));
            return Engine_SyncReply::failed($errorCode);
        }
        // /v1/tasks/execute is synchronous and terminal. A "pending" (or any
        // unrecognized) state means the API violated its own sync contract.
        // That is a malformed-response signal (api_response_invalid), NOT a
        // provider outage (provider_error) — different cause, different TTL.
        $this->transport->recordError(
            Engine_TransportInterface::ERROR_API_INVALID,
            $this->transport->buildErrorContext("execute: unexpected non-terminal state on sync endpoint", $decoded)
        );
        return Engine_SyncReply::failed(Engine_TransportInterface::ERROR_API_INVALID);
    }
}

// Reply concrete: exactly three legal shapes (pending / successful / failed),
// each produced by a named constructor. The private constructor prevents
// illegal combinations like "pending AND successful" or "successful with
// null reply" from being constructed — these used to be representable.
final class Engine_ReplyString implements Engine_ReplyStringInterface {
    private bool $isPending;
    private bool $isSuccessful;
    private ?string $reply;
    private ?string $error;

    private function __construct(bool $isPending, bool $isSuccessful, ?string $reply, ?string $error) {
        $this->isPending = $isPending;
        $this->isSuccessful = $isSuccessful;
        $this->reply = $reply;
        $this->error = $error;
    }

    public static function pending(): self {
        return new self(true, false, null, null);
    }

    public static function successful(string $reply): self {
        return new self(false, true, $reply, null);
    }

    public static function failed(string $error): self {
        return new self(false, false, null, $error);
    }

    public function isPending(): bool {
        return $this->isPending;
    }

    public function isSuccessful(): bool {
        return $this->isSuccessful;
    }

    public function getReply(): ?string {
        return $this->reply;
    }

    public function getError(): ?string {
        return $this->error;
    }
}

// Disabled stub: rejects all semantic operations with semantic_disabled error.
final class Engine_SemanticDisabled implements Engine_SemanticInterface {
    private ?Engine_SiteIdResolverInterface $siteIdResolver = null;

    public function submit(string $callerId, string $taskName, string $mainPayload, ?array $options = null): \SoleEngineJobTicketInterface {
        return Engine_JobTicket::rejected("semantic_disabled");
    }

    public function getResult(string $jobId): \SoleEngineReplyStringInterface {
        return Engine_ReplyString::failed("semantic_disabled");
    }

    public function getSiteId(): string {
        $rawUrl = "";
        if (\function_exists("home_url")) {
            $home = \home_url();
            $rawUrl = \is_string($home) ? $home : "";
        }
        $this->siteIdResolver ??= new Engine_SiteIdResolver();
        return $this->siteIdResolver->resolve($rawUrl);
    }
}

final class Engine_Semantic implements Engine_SemanticInterface {
    // How many dropped hits the aggregate names explicitly. Bounded so a
    // normal edit-then-search interleaving cannot flood the diagnostics ring.
    private const REHYDRATE_DROP_SAMPLES = 3;
    private const ERROR_CONFIG_MISSING = "config_missing";
    private const ERROR_PROVIDER = "provider_error";
    private const TTL_PENDING_SECONDS = 120;
    private const TTL_SUCCESS_SECONDS = 86400;
    // Dedup mapping for payload-complete semantic tasks (relevance). Equal to
    // the service's 24h retention, safely, for the reason given in Engine_Llm.
    private const TTL_DEDUP_SECONDS = 86400;
    // Dedup mapping for `search` ONLY, and it is NOT a cache knob. While this
    // mapping holds, submit() returns the cached job id and NEVER CALLS THE
    // SERVICE, so the service-side requeue never runs. THIS VALUE IS THEREFORE
    // EXACTLY HOW STALE A REPEATED SEARCH MAY BE.
    // FLOOR: the observed 45-130s indexing lag. A window shorter than the lag
    // pays for a re-execution against a corpus that cannot yet have caught up.
    // 300s is ~2.3x that ceiling and states a bound a person can hold: a search
    // may be up to five minutes behind an edit.
    // PROVISIONAL. A higher measured lag raises it; a long repeat-tail raises
    // it; complaints of slow-appearing edits lower it toward the lag floor and
    // NO FURTHER. Ratified 2026-08-22.
    private const TTL_DEDUP_SEARCH_SECONDS = 300;

    private Cache_StoreInterface $cacheStore;
    private Config_StoreInterface $configStore;
    private Engine_SiteIdResolverInterface $siteIdResolver;
    private Engine_ErrorTtlResolverInterface $ttlResolver;
    private Engine_TransportInterface $transport;
    // Credit recording is engine-level business state, not transport concern;
    // keep a direct reference for recordCallerCredits calls.
    private Diagnostics_StoreInterface $diagnostics;
    // Search-hit chunk text reconstruction. The API returns hit coordinates
    // only; the resolver re-derives chunk_text from the canonical chunker.
    private Indexing_SearchHitResolverInterface $hitResolver;

    public function __construct(
        Cache_StoreInterface $cacheStore,
        Config_StoreInterface $configStore,
        Http_ClientInterface $httpClient,
        Diagnostics_StoreInterface $diagnostics,
        Indexing_SearchHitResolverInterface $hitResolver
    ) {
        $this->cacheStore = $cacheStore;
        $this->configStore = $configStore;
        $this->siteIdResolver = new Engine_SiteIdResolver();
        $this->ttlResolver = new Engine_ErrorTtlResolver();
        $this->transport = new Engine_Transport($httpClient, $configStore, $diagnostics);
        $this->diagnostics = $diagnostics;
        $this->hitResolver = $hitResolver;
    }

    public function submit(string $callerId, string $taskName, string $mainPayload, ?array $options = null): \SoleEngineJobTicketInterface {
        if ($this->configStore->isCallerBlocked($callerId)) {
            $this->transport->recordError("caller_blocked", "submit: blocked; caller: " . $callerId);
            return Engine_JobTicket::rejected("caller_blocked");
        }
        $endpoint = $this->configStore->getEndpoint();
        $userKey = $this->configStore->getUserKey();
        if ($endpoint === null || $userKey === null) {
            $this->transport->recordError(self::ERROR_CONFIG_MISSING, "submit: endpoint or user key not configured; caller: " . $callerId);
            return Engine_JobTicket::rejected(self::ERROR_CONFIG_MISSING);
        }
        $siteId = $this->getSiteId();
        $hash = $this->transport->buildHash($taskName, $mainPayload, $options, $siteId, $userKey, $endpoint);
        $submitError = $this->cacheStore->getSubmitErrorForHash($hash);
        if ($submitError !== null) {
            return Engine_JobTicket::rejected($submitError);
        }
        $cachedJobId = $this->cacheStore->getJobIdForHash($hash);
        if ($cachedJobId !== null) {
            return Engine_JobTicket::accepted($cachedJobId);
        }
        $payload = [
            "user_key" => $userKey,
            "site_id" => $siteId,
            "task" => $taskName,
            "payload" => $mainPayload,
            "options" => $options,
            "requester" => $callerId
        ];
        Engine_HumanDemoBridge::mint("semantic_submit", $endpoint . "/v1/tasks/submit", $payload);
        $response = $this->transport->sendRequest($endpoint . "/v1/tasks/submit", $payload);
        $decoded = $response["result"];
        if ($decoded === null) {
            $error = $response["error"] ?? Engine_TransportInterface::ERROR_API_INVALID;
            $detail = $response["detail"] ?? "";
            $this->transport->recordError($error, "submit: failed for task " . $taskName . "; caller: " . $callerId . ($detail !== "" ? "; detail: " . $detail : ""));
            $ttl = $this->ttlResolver->getTtlForError($error);
            if ($ttl > 0) {
                $this->cacheStore->setSubmitErrorForHash($hash, $error, $ttl);
            }
            return Engine_JobTicket::rejected($error);
        }
        if (empty($decoded["was_accepted"])) {
            $errorCode = $this->transport->readErrorCode($decoded) ?? Engine_TransportInterface::ERROR_API_INVALID;
            $this->transport->recordError($errorCode, $this->transport->buildErrorContext("submit: rejected for task " . $taskName, $decoded) . "; caller: " . $callerId);
            $ttl = $this->ttlResolver->getTtlForError($errorCode);
            if ($ttl > 0) {
                $this->cacheStore->setSubmitErrorForHash($hash, $errorCode, $ttl);
            }
            return Engine_JobTicket::rejected($errorCode);
        }
        $jobId = isset($decoded["job_id"]) && \is_string($decoded["job_id"]) ? $decoded["job_id"] : null;
        if ($jobId === null || $jobId === "") {
            $this->transport->recordError(Engine_TransportInterface::ERROR_API_INVALID, "submit: response accepted but job_id missing for task " . $taskName . "; caller: " . $callerId);
            $ttl = $this->ttlResolver->getTtlForError(Engine_TransportInterface::ERROR_API_INVALID);
            if ($ttl > 0) {
                $this->cacheStore->setSubmitErrorForHash($hash, Engine_TransportInterface::ERROR_API_INVALID, $ttl);
            }
            return Engine_JobTicket::rejected(Engine_TransportInterface::ERROR_API_INVALID);
        }
        if (!Engine_HumanDemoBridge::bindJob("semantic_submit", $jobId)) {
            return Engine_JobTicket::rejected("human_demo_job_binding_failed");
        }
        $this->cacheStore->setJobIdForHash($hash, $jobId, self::TTL_PENDING_SECONDS);
        $this->cacheStore->setHashForJobId($jobId, $hash, self::TTL_PENDING_SECONDS);
        $rawCredits = $decoded["usage"]["credits_total"] ?? null;
        $creditsTotal = \is_numeric($rawCredits) ? (float) $rawCredits : 0.0;
        if ($creditsTotal > 0) {
            $this->diagnostics->recordCallerCredits($callerId, $creditsTotal);
        }
        $this->transport->recordSuccess();
        return Engine_JobTicket::accepted($jobId);
    }

    public function getResult(string $jobId): \SoleEngineReplyStringInterface {
        $endpoint = $this->configStore->getEndpoint();
        $userKey = $this->configStore->getUserKey();
        if ($endpoint === null || $userKey === null) {
            $this->transport->recordError(self::ERROR_CONFIG_MISSING, "poll: endpoint or user key not configured");
            return Engine_ReplyString::failed(self::ERROR_CONFIG_MISSING);
        }
        $cachedReply = $this->cacheStore->getReplyForJob($jobId);
        if (\is_array($cachedReply)) {
            // Defensive: an upgrade from a pre-audit-fix version may leave
            // stale semantic_search success replies in WP transients. Those
            // entries embed chunk_text/post_title captured before the
            // read-side eligibility gate existed. Treat such entries as a
            // cache miss so the engine re-fetches and re-rehydrates
            // against current WP state. New writes for this reply type
            // are already suppressed; this read-side guard handles the
            // upgrade window. The same guard runs in the LLM engine too,
            // which is harmless (no LLM reply has type semantic_search)
            // and keeps both engines structurally identical.
            $cachedSuccess = isset($cachedReply["success"]) && $cachedReply["success"] === true;
            $cachedType = isset($cachedReply["reply_type"]) && \is_string($cachedReply["reply_type"]) ? $cachedReply["reply_type"] : "";
            if (!($cachedSuccess && $cachedType === "semantic_search")) {
                return $this->replyFromCache($cachedReply);
            }
        }
        $hash = $this->cacheStore->getHashForJobId($jobId);
        $siteId = $this->getSiteId();
        $payload = [
            "user_key" => $userKey,
            "site_id" => $siteId,
            "job_id" => $jobId
        ];
        Engine_HumanDemoBridge::mint("result_poll", $endpoint . "/v1/tasks/result", $payload);
        $response = $this->transport->sendRequest($endpoint . "/v1/tasks/result", $payload);
        $decoded = $response["result"];
        if ($decoded === null) {
            $error = $response["error"] ?? Engine_TransportInterface::ERROR_API_INVALID;
            $detail = $response["detail"] ?? "";
            // When the result slot is null the decoded body is null too, so we have
            // nothing to summarize — just say so. Avoids "raw: null" noise.
            $contextDetail = $detail !== "" ? "; detail: " . $detail : "; no response detail captured";
            $this->transport->recordError($error, "poll: failed" . $contextDetail);
            $ttl = $this->ttlResolver->getTtlForError($error);
            if ($ttl > 0) {
                $this->cacheStore->setReplyForJob(
                    $jobId,
                    $this->buildCacheReply(false, null, null, $error),
                    $ttl
                );
            }
            return Engine_ReplyString::failed($error);
        }
        if (empty($decoded["was_accepted"])) {
            $errorCode = $this->transport->readErrorCode($decoded) ?? Engine_TransportInterface::ERROR_API_INVALID;
            $this->transport->recordError($errorCode, $this->transport->buildErrorContext("poll: was_accepted false or missing", $decoded));
            $ttl = $this->ttlResolver->getTtlForError($errorCode);
            if ($ttl > 0) {
                $this->cacheStore->setReplyForJob(
                    $jobId,
                    $this->buildCacheReply(false, null, null, $errorCode),
                    $ttl
                );
            }
            return Engine_ReplyString::failed($errorCode);
        }
        $state = isset($decoded["state"]) && \is_string($decoded["state"]) ? $decoded["state"] : "";
        if ($state === "pending") {
            if ($hash !== null) {
                $this->cacheStore->setJobIdForHash($hash, $jobId, self::TTL_PENDING_SECONDS);
                $this->cacheStore->setHashForJobId($jobId, $hash, self::TTL_PENDING_SECONDS);
            }
            return Engine_ReplyString::pending();
        }
        if ($state === "completed") {
            $reply = isset($decoded["reply"]) && \is_array($decoded["reply"]) ? $decoded["reply"] : null;
            $replyType = $reply !== null && isset($reply["type"]) && \is_string($reply["type"]) ? $reply["type"] : null;
            $text = $this->extractSemanticReply($reply, $replyType, $decoded);
            if ($text === null) {
                $this->transport->recordError(
                    self::ERROR_PROVIDER,
                    $this->transport->buildErrorContext("poll: completed but no extractable reply", $decoded)
                );
                $ttl = $this->ttlResolver->getTtlForError(self::ERROR_PROVIDER);
                if ($ttl > 0) {
                    $this->cacheStore->setReplyForJob(
                        $jobId,
                        $this->buildCacheReply(false, null, null, self::ERROR_PROVIDER),
                        $ttl
                    );
                }
                // Do NOT refresh hash→jobId on failure. The job is terminal —
                // refreshing would create a self-renewing cache loop that
                // permanently binds this content hash to a dead job.
                return Engine_ReplyString::failed(self::ERROR_PROVIDER);
            }
            $cacheType = \is_string($replyType) ? $replyType : "semantic_text";
            $this->transport->recordSuccess();
            // Skip the 24h success cache for semantic_search. The rehydrated
            // reply embeds chunk_text and post_title captured from WordPress
            // at the moment of caching. Posts can be unpublished, edited,
            // deleted, or made non-public during the 24h window — returning
            // the cached reply would surface content that is no longer
            // eligible, because the eligibility gate runs only inside
            // rehydrateSearchHits(). The hash→jobId mapping below is still
            // refreshed so submit-time dedup keeps working; on the next
            // poll the engine re-fetches from /v1/tasks/result (non-billable
            // per the API contract) and re-rehydrates against current state.
            if ($cacheType !== "semantic_search") {
                $this->cacheStore->setReplyForJob(
                    $jobId,
                    $this->buildCacheReply(true, $cacheType, $text, null),
                    self::TTL_SUCCESS_SECONDS
                );
            }
            if ($hash !== null) {
                $mappingTtl = $cacheType === "semantic_search"
                    ? self::TTL_DEDUP_SEARCH_SECONDS
                    : self::TTL_DEDUP_SECONDS;
                $this->cacheStore->setJobIdForHash($hash, $jobId, $mappingTtl);
                $this->cacheStore->setHashForJobId($jobId, $hash, $mappingTtl);
            }
            return Engine_ReplyString::successful($text);
        }
        if ($state === "failed") {
            $errorCode = $this->transport->readErrorCode($decoded) ?? self::ERROR_PROVIDER;
            $this->transport->recordError($errorCode, $this->transport->buildErrorContext("poll: job failed", $decoded));
            $ttl = $this->ttlResolver->getTtlForError($errorCode);
            if ($ttl > 0) {
                $this->cacheStore->setReplyForJob(
                    $jobId,
                    $this->buildCacheReply(false, null, null, $errorCode),
                    $ttl
                );
            }
            // No hash→jobId refresh — terminal failure. Letting the hash
            // expire forces the next submit to create a fresh job instead
            // of re-polling a dead one in a self-renewing loop.
            return Engine_ReplyString::failed($errorCode);
        }
        $this->transport->recordError(
            self::ERROR_PROVIDER,
            $this->transport->buildErrorContext("poll: unexpected state", $decoded)
        );
        $ttl = $this->ttlResolver->getTtlForError(self::ERROR_PROVIDER);
        if ($ttl > 0) {
            $this->cacheStore->setReplyForJob(
                $jobId,
                $this->buildCacheReply(false, null, null, self::ERROR_PROVIDER),
                $ttl
            );
        }
        // No hash→jobId refresh — unrecognized state is treated as terminal.
        return Engine_ReplyString::failed(self::ERROR_PROVIDER);
    }

    public function getSiteId(): string {
        return $this->siteIdResolver->resolve($this->transport->readHomeUrl());
    }

    /**
     * Extract the reply string from a completed semantic response.
     * Returns JSON-encoded string for structured types, or null if extraction fails.
     */
    private function extractSemanticReply(?array $reply, ?string $replyType, array $decoded): ?string {
        if ($reply === null) {
            return null;
        }
        if ($replyType === "semantic_relevance") {
            $value = isset($reply["value"]) && \is_numeric($reply["value"]) ? (float) $reply["value"] : null;
            if ($value === null) {
                return null;
            }
            $json = \json_encode(["value" => $value]);
            return \is_string($json) ? $json : null;
        }
        if ($replyType === "semantic_search") {
            $rawHits = isset($reply["hits"]) && \is_array($reply["hits"]) ? $reply["hits"] : null;
            if ($rawHits === null) {
                return null;
            }
            return $this->rehydrateSearchHits($rawHits);
        }
        // Forward compatibility: try reply.text for unknown types.
        if (isset($reply["text"]) && \is_string($reply["text"])) {
            return $reply["text"];
        }
        return null;
    }

    /**
     * Rehydrate search hits with local WordPress post data and reconstruct
     * chunk_text from the canonical chunker.
     *
     * The API returns hit coordinates only (source_type, entity_id, field,
     * chunk_ord, content_version) — never chunk_text on the wire. The plugin
     * owns the canonical chunking, so it can rebuild the exact text that was
     * embedded, provided the hit's content_version still matches the post's
     * current version. If anything drifts (post deleted, unpublished, made
     * non-public, content edited since indexing, or the (field, chunk_ord)
     * no longer maps), the hit is dropped from the result and counted; a
     * single aggregate diagnostic warning is emitted at the end of the loop
     * (never one-per-hit — search can return up to 100 hits, which would
     * otherwise flush the entire diagnostics log on a single drift event).
     *
     * Eligibility: only source_type === "wp_post" hits are reconstructed.
     * The post must exist, be in "publish" status, and belong to a public
     * post type — matching what Indexing_PostLifecycle agrees to index in
     * the first place. A drifted vector store (e.g. a failed unpublish
     * cleanup) must not surface non-public content via reconstructed text.
     */
    private function rehydrateSearchHits(array $rawHits): string {
        $postIds = [];
        foreach ($rawHits as $hit) {
            if (
                isset($hit["source_type"], $hit["entity_id"])
                && $hit["source_type"] === "wp_post"
                && \is_numeric($hit["entity_id"])
            ) {
                $postIds[] = (int) $hit["entity_id"];
            }
        }
        $postIds = \array_values(\array_unique($postIds));
        $postsMap = [];
        if (!empty($postIds) && \function_exists("get_posts")) {
            $posts = \get_posts([
                "post__in" => $postIds,
                "post_type" => "any",
                "post_status" => "any",
                "posts_per_page" => \count($postIds),
                "no_found_rows" => true,
                "update_post_term_cache" => false,
            ]);
            foreach ($posts as $post) {
                $postsMap[$post->ID] = $post;
            }
        }

        $rehydrated = [];
        $unsupportedCount = 0;
        $orphanCount = 0;
        $ineligibleCount = 0;
        $staleVersionCount = 0;
        // Bounded exemplars for the aggregate below. The aggregate alone
        // reports HOW MANY hits were dropped and never WHICH, which makes it
        // uninformative even when read: a defect cannot be localised from it.
        // Per-hit logging would flood the 100-entry ring on a normal
        // edit-then-search interleaving, so a capped sample is carried instead.
        $dropSamples = [];
        $missingChunkCount = 0;

        foreach ($rawHits as $hit) {
            $sourceType = isset($hit["source_type"]) && \is_string($hit["source_type"]) ? $hit["source_type"] : "";
            if ($sourceType !== "wp_post") {
                $unsupportedCount++;
                continue;
            }
            $postId = isset($hit["entity_id"]) && \is_numeric($hit["entity_id"]) ? (int) $hit["entity_id"] : 0;
            if ($postId <= 0 || !isset($postsMap[$postId])) {
                $orphanCount++;
                continue;
            }
            $post = $postsMap[$postId];
            if ($post->post_status !== "publish" || !$this->isPublicPostType($post->post_type)) {
                $ineligibleCount++;
                continue;
            }
            $hitVersion = isset($hit["content_version"]) && \is_string($hit["content_version"]) ? $hit["content_version"] : "";
            $currentVersion = $this->hitResolver->computeContentVersion($post);
            if ($hitVersion === "" || $hitVersion !== $currentVersion) {
                $staleVersionCount++;
                if (\count($dropSamples) < self::REHYDRATE_DROP_SAMPLES) {
                    $dropSamples[] = "stale " . $postId . "/"
                        . (isset($hit["field"]) && \is_string($hit["field"]) ? $hit["field"] : "?") . "/"
                        . (isset($hit["chunk_ord"]) && \is_numeric($hit["chunk_ord"]) ? (string) (int) $hit["chunk_ord"] : "?")
                        . " hit=" . ($hitVersion === "" ? "(empty)" : \substr($hitVersion, 0, 8))
                        . " cur=" . \substr($currentVersion, 0, 8);
                }
                continue;
            }
            $field = isset($hit["field"]) && \is_string($hit["field"]) ? $hit["field"] : "";
            $chunkOrd = isset($hit["chunk_ord"]) && \is_numeric($hit["chunk_ord"]) ? (int) $hit["chunk_ord"] : -1;
            if ($field === "" || $chunkOrd < 0) {
                $missingChunkCount++;
                continue;
            }
            $chunkText = $this->hitResolver->resolveChunkText($post, $field, $chunkOrd);
            if ($chunkText === null) {
                $missingChunkCount++;
                continue;
            }
            $rehydrated[] = [
                "score" => isset($hit["score"]) && \is_numeric($hit["score"]) ? (float) $hit["score"] : 0.0,
                "post_id" => $postId,
                "post_type" => $post->post_type,
                "field" => $field,
                "chunk_text" => $chunkText,
                "post_title" => $post->post_title ?? "",
            ];
        }

        $totalDropped = $unsupportedCount + $orphanCount + $ineligibleCount + $staleVersionCount + $missingChunkCount;
        if ($totalDropped > 0) {
            $total = \count($rawHits);
            // Single aggregate entry per response. The reindex poller cleans
            // up stale vectors on its next hourly run; per-hit logging here
            // would flood the 100-entry diagnostics ring on a normal
            // post-edit-then-search interleaving.
            $parts = [];
            if ($unsupportedCount > 0) { $parts[] = "unsupported_source=" . $unsupportedCount; }
            if ($orphanCount > 0)      { $parts[] = "missing_post=" . $orphanCount; }
            if ($ineligibleCount > 0)  { $parts[] = "unpublished_or_non_public=" . $ineligibleCount; }
            if ($staleVersionCount > 0){ $parts[] = "stale_content_version=" . $staleVersionCount; }
            if ($missingChunkCount > 0){ $parts[] = "chunk_not_found=" . $missingChunkCount; }
            $detail = "rehydrate: " . $totalDropped . " of " . $total . " hits dropped (" . \implode(", ", $parts) . ")";
            if (\count($dropSamples) > 0) {
                $detail .= " | e.g. " . \implode("; ", $dropSamples);
            }
            $this->transport->recordError(self::ERROR_PROVIDER, $detail);
        }

        $json = \json_encode([
            "hits" => $rehydrated,
            "coordinate_count" => \count($rawHits),
        ]);
        if (\is_string($json)) {
            return $json;
        }
        $this->transport->recordError(
            self::ERROR_PROVIDER,
            "rehydrate: JSON encoding failed; raw_coordinate_count=" . \count($rawHits)
        );
        return '{"hits":[]}';
    }

    /**
     * Mirrors Indexing_PostLifecycle::isPublicPostType. Duplicated here rather
     * than crossing the module boundary for a one-line predicate — the
     * lifecycle class is module-private and exposing it would be heavier than
     * the duplication.
     */
    private function isPublicPostType(string $postType): bool {
        if (!\function_exists("get_post_type_object")) {
            return false;
        }
        $typeObj = \get_post_type_object($postType);
        return $typeObj !== null && $typeObj->public === true;
    }

    private function buildCacheReply(
        bool $success,
        ?string $replyType,
        $replyValue,
        ?string $error
    ): array {
        return [
            "success" => $success,
            "reply_type" => $replyType,
            "reply_value" => $replyValue,
            "error" => $error
        ];
    }

    private function replyFromCache(array $cached): \SoleEngineReplyStringInterface {
        $success = isset($cached["success"]) ? (bool) $cached["success"] : false;
        $replyValue = $cached["reply_value"] ?? null;
        $error = isset($cached["error"]) && \is_string($cached["error"]) ? $cached["error"] : null;
        if ($success) {
            if (\is_string($replyValue)) {
                return Engine_ReplyString::successful($replyValue);
            }
            // Cache record marked successful but reply_value is not a string.
            // Log the corruption so the cache-write bug surfaces.
            $this->transport->recordError(
                self::ERROR_PROVIDER,
                "cache: semantic success entry with non-string reply_value (" . \gettype($replyValue) . ")"
            );
            return Engine_ReplyString::failed(self::ERROR_PROVIDER);
        }
        return Engine_ReplyString::failed($error ?? self::ERROR_PROVIDER);
    }

}

final class Engine_SiteIdNormalizer implements Engine_SiteIdNormalizerInterface {
    public function normalize(string $rawUrl): string {
        $rawUrl = \trim($rawUrl);
        if ($rawUrl === "") {
            return "";
        }
        $parts = \wp_parse_url($rawUrl);
        if (!\is_array($parts)) {
            return $rawUrl;
        }
        $scheme = isset($parts["scheme"]) && \is_string($parts["scheme"]) ? \strtolower($parts["scheme"]) : null;
        $host = isset($parts["host"]) && \is_string($parts["host"]) ? \strtolower($parts["host"]) : null;
        if ($scheme === null || $host === null) {
            return $rawUrl;
        }
        $port = isset($parts["port"]) ? (int) $parts["port"] : null;
        if ($port === 80 && $scheme === "http") {
            $port = null;
        }
        if ($port === 443 && $scheme === "https") {
            $port = null;
        }
        $path = isset($parts["path"]) && \is_string($parts["path"]) ? $parts["path"] : "";
        if ($path !== "/") {
            $path = \rtrim($path, "/");
        }
        $normalized = $scheme . "://" . $host;
        if ($port !== null && $port > 0) {
            $normalized .= ":" . $port;
        }
        if ($path !== "" && $path !== "/") {
            $normalized .= $path;
        }
        return $normalized;
    }
}

final class Engine_PersistedHomeReader implements Engine_SiteIdentityReaderInterface {
    private bool $hasRead = false;
    private ?string $persistedHome = null;

    public function readPersistedHome(): ?string {
        if ($this->hasRead) {
            return $this->persistedHome;
        }
        $this->hasRead = true;

        global $wpdb;
        if (!\is_object($wpdb)
            || !isset($wpdb->options)
            || !\is_string($wpdb->options)
            || !\method_exists($wpdb, "prepare")
            || !\method_exists($wpdb, "get_var")) {
            return $this->persistedHome;
        }
        $query = $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            "home"
        );
        if (!\is_string($query)) {
            return $this->persistedHome;
        }
        $home = $wpdb->get_var($query);
        $this->persistedHome = \is_string($home) && \trim($home) !== "" ? $home : null;
        return $this->persistedHome;
    }
}

/**
 * Resolves the outbound Engine namespace without coupling it to the browser
 * Host header. A sealed human-demo profile remains authoritative when loaded;
 * ordinary product mode uses the persisted, unfiltered WordPress home.
 */
final class Engine_SiteIdResolver implements Engine_SiteIdResolverInterface {
    private Engine_SiteIdNormalizer $normalizer;
    private Engine_SiteIdentityReaderInterface $identityReader;
    private ?Diagnostics_StoreInterface $diagnostics;
    private static bool $fallbackRecordedThisRequest = false;

    public function __construct(
        ?Engine_SiteIdentityReaderInterface $identityReader = null,
        ?Diagnostics_StoreInterface $diagnostics = null
    ) {
        $this->normalizer = new Engine_SiteIdNormalizer();
        $this->identityReader = $identityReader ?? new Engine_PersistedHomeReader();
        $this->diagnostics = $diagnostics;
    }

    public function resolve(string $requestDerivedFallback): string {
        $guardClass = $this->loadedGuardClass();
        if ($guardClass !== null && \method_exists($guardClass, "profileSiteId")) {
            $profileSiteId = $guardClass::profileSiteId();
            if (\is_string($profileSiteId) && $profileSiteId !== "") {
                return $this->normalizer->normalize($profileSiteId);
            }
        }
        $persistedHome = $this->identityReader->readPersistedHome();
        if ($persistedHome !== null) {
            return $this->normalizer->normalize($persistedHome);
        }
        if (\defined("WPINC") && !self::$fallbackRecordedThisRequest) {
            self::$fallbackRecordedThisRequest = true;
            $diagnostics = $this->diagnostics ?? (new Diagnostics_Factory())->makeStore();
            $diagnostics->recordError(
                "site_identity_fallback",
                "site_identity: persisted home unavailable; request-derived fallback used",
                \gmdate("c")
            );
        }
        return $this->normalizer->normalize($requestDerivedFallback);
    }

    /** @return string|null */
    private function loadedGuardClass(): ?string {
        $guardClassName = (string) "Sole_Dev7_Human_Demo_Guard";
        if (!\class_exists($guardClassName, false)
            || !\defined($guardClassName . "::GENERATION")
            || $guardClassName::GENERATION !== "human_demo_v1") {
            return null;
        }
        return $guardClassName;
    }
}

/**
 * Narrow, inert bridge to the exact DEV human-demo guard. It is not a public
 * accessor and carries no credential. Normal installations never load the
 * guard class, making both methods strict no-ops.
 */
final class Engine_HumanDemoBridge {
    public static function mint(string $operation, string $url, array $payload): void {
        $guardClass = self::loadedGuardClass();
        if ($guardClass === null || !\method_exists($guardClass, "mintCapability")) {
            return;
        }
        $guardClass::mintCapability($operation, $url, $payload);
    }

    public static function bindJob(string $operation, string $jobId): bool {
        $guardClass = self::loadedGuardClass();
        if ($guardClass === null || !\method_exists($guardClass, "bindJob")) {
            return true;
        }
        if (\method_exists($guardClass, "profileSiteId") && $guardClass::profileSiteId() === null) {
            return true;
        }
        return $guardClass::bindJob($operation, $jobId) === true;
    }

    /** @return string|null */
    private static function loadedGuardClass(): ?string {
        $guardClassName = (string) "Sole_Dev7_Human_Demo_Guard";
        if (!\class_exists($guardClassName, false)
            || !\defined($guardClassName . "::GENERATION")
            || $guardClassName::GENERATION !== "human_demo_v1") {
            return null;
        }
        return $guardClassName;
    }

    private function __construct() {}
}

// Terminal sync reply: exactly two legal shapes (successful / failed), each from
// a named constructor. The private constructor makes "successful with no reply"
// or "failed with no error" unrepresentable. There is no pending state — the
// synchronous bridge is structurally terminal.
final class Engine_SyncReply implements SoleEngineSyncReplyInterface {
    private bool $isSuccessful;
    private ?string $reply;
    private ?string $error;

    private function __construct(bool $isSuccessful, ?string $reply, ?string $error) {
        $this->isSuccessful = $isSuccessful;
        $this->reply = $reply;
        $this->error = $error;
    }

    public static function successful(string $reply): self {
        return new self(true, $reply, null);
    }

    public static function failed(string $error): self {
        return new self(false, null, $error);
    }

    public function isSuccessful(): bool {
        return $this->isSuccessful;
    }

    public function getReply(): ?string {
        return $this->reply;
    }

    public function getError(): ?string {
        return $this->error;
    }
}

// Transport concrete: the single place where HTTP/hash/diagnostics
// mechanics live. Both Engine_Llm and Engine_Semantic delegate here so
// architectural rules (notably the HTTP 500 + was_accepted rule and the
// "never refresh hash→jobId on terminal failure") only need to be
// maintained once.
final class Engine_Transport implements Engine_TransportInterface {
    private const HASH_PREFIX = "sole_engine";

    private Http_ClientInterface $httpClient;
    private Config_StoreInterface $configStore;
    private Diagnostics_StoreInterface $diagnostics;
    // When non-null, overrides the config-driven retry count. The synchronous
    // bridge passes 1: a single blocking, human-facing call must not multiply
    // its wall time by retrying. Left null on the async paths, which keep the
    // configured getTotalAttempts() retries. This is a concrete-only constructor
    // option; Engine_TransportInterface::sendRequest is deliberately unchanged.
    private ?int $maxAttemptsOverride;

    public function __construct(
        Http_ClientInterface $httpClient,
        Config_StoreInterface $configStore,
        Diagnostics_StoreInterface $diagnostics,
        ?int $maxAttemptsOverride = null
    ) {
        $this->httpClient = $httpClient;
        $this->configStore = $configStore;
        $this->diagnostics = $diagnostics;
        $this->maxAttemptsOverride = $maxAttemptsOverride;
    }

    public function sendRequest(string $url, array $payload): array {
        $attempts = $this->maxAttemptsOverride ?? $this->configStore->getTotalAttempts();
        if ($attempts < 1) {
            $attempts = 1;
        }
        $lastError = null;
        $lastDetail = "";
        for ($index = 0; $index < $attempts; $index += 1) {
            $response = $this->httpClient->postJson($url, $payload);
            if ($response->getError() !== null) {
                $lastError = Engine_TransportInterface::ERROR_NETWORK;
                $lastDetail = $response->getError();
                continue;
            }
            if ($response->getStatus() >= 500) {
                // IMPORTANT: The API returns structured JSON errors with HTTP 500
                // (e.g. engine_internal: "LLM models not configured"). These carry
                // actionable error codes and must be returned to the caller — NOT
                // masked as network_error and retried. Only treat 500 as a retryable
                // network failure when the body is empty, unparseable, or lacks the
                // API envelope (was_accepted field). A JSON 500 without was_accepted
                // may come from a proxy or CDN, not the API, and should be retried.
                $decoded500 = $this->decodeResponse($response->getBody());
                if ($decoded500 !== null && \array_key_exists("was_accepted", $decoded500)) {
                    $this->recordCredentialEvidenceFromResponse($decoded500, $payload);
                    return [
                        "result" => $decoded500,
                        "error" => null,
                        "detail" => ""
                    ];
                }
                $lastError = Engine_TransportInterface::ERROR_NETWORK;
                $lastDetail = "HTTP " . $response->getStatus() . ": " . \substr($response->getBody(), 0, 200);
                continue;
            }
            $decoded = $this->decodeResponse($response->getBody());
            if ($decoded === null) {
                // Non-retryable: 4xx or 2xx with invalid body.
                return [
                    "result" => null,
                    "error" => Engine_TransportInterface::ERROR_API_INVALID,
                    "detail" => "HTTP " . $response->getStatus() . ": " . \substr($response->getBody(), 0, 200)
                ];
            }
            $this->recordCredentialEvidenceFromResponse($decoded, $payload);
            return [
                "result" => $decoded,
                "error" => null,
                "detail" => ""
            ];
        }
        return [
            "result" => null,
            "error" => $lastError ?? Engine_TransportInterface::ERROR_NETWORK,
            "detail" => $lastDetail
        ];
    }

    public function buildHash(string $task, string $payload, ?array $options, string $siteId, string $userKey, string $endpoint): string {
        $normalizedOptions = $this->normalizeOptions($options);
        // Sort recursively so semantically identical options with different
        // key orders produce the same hash. See map.md "Hash dedupe key".
        $encoded = \json_encode(
            [
                "prefix" => self::HASH_PREFIX,
                "task" => $task,
                "payload" => $payload,
                "options" => $normalizedOptions,
                "site_id" => $siteId,
                "user_key" => $userKey,
                "endpoint" => $endpoint
            ]
        );
        if (!\is_string($encoded)) {
            $encoded = $task . "|" . $payload . "|" . $siteId;
        }
        return \hash("sha256", $encoded);
    }

    public function readErrorCode(array $decoded): ?string {
        if (!isset($decoded["error"]) || !\is_array($decoded["error"])) {
            return null;
        }
        $code = $decoded["error"]["code"] ?? null;
        if (!\is_string($code)) {
            return null;
        }
        return $code;
    }

    public function buildErrorContext(string $phase, array $decoded): string {
        $message = $this->readErrorMessage($decoded);
        if ($message !== null) {
            return $phase . "; message: " . $message;
        }
        return $phase . "; response: " . $this->summarizeResponse($decoded);
    }

    public function readHomeUrl(): string {
        if (\function_exists("home_url")) {
            $value = \home_url();
            if (\is_string($value)) {
                return $value;
            }
        }
        return "";
    }

    public function recordSuccess(): void {
        $this->diagnostics->recordSuccess(\gmdate("c"));
    }

    public function recordError(string $code, string $context): void {
        $this->diagnostics->recordError($code, $context, \gmdate("c"));
    }

    private function recordCredentialEvidenceFromResponse(array $decoded, array $payload): void {
        $userKey = $payload["user_key"] ?? null;
        if (!\is_string($userKey) || $userKey === "") {
            return;
        }
        $timestamp = \gmdate("c");
        if (($decoded["was_accepted"] ?? null) === true) {
            $this->diagnostics->recordCredentialEvidence($userKey, "verified", $timestamp);
            return;
        }
        if ($this->readErrorCode($decoded) === "user_key_invalid") {
            $this->diagnostics->recordCredentialEvidence($userKey, "invalid", $timestamp);
        }
    }

    private function readErrorMessage(array $decoded): ?string {
        if (!isset($decoded["error"]) || !\is_array($decoded["error"])) {
            return null;
        }
        $message = $decoded["error"]["message"] ?? null;
        return \is_string($message) ? $message : null;
    }

    private function normalizeOptions(?array $options): ?array {
        if ($options === null) {
            return null;
        }
        $normalized = $options;
        $this->sortRecursive($normalized);
        return $normalized;
    }

    private function sortRecursive(array &$value): void {
        foreach ($value as &$entry) {
            if (\is_array($entry)) {
                $this->sortRecursive($entry);
            }
        }
        \ksort($value, SORT_STRING);
    }

    private function decodeResponse(string $body): ?array {
        $decoded = \json_decode($body, true);
        if (!\is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    private function summarizeResponse(?array $decoded): string {
        if ($decoded === null) {
            return "null";
        }
        $safe = $decoded;
        unset($safe["user_key"]);
        $json = \json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!\is_string($json)) {
            return "(unserializable)";
        }
        return \strlen($json) > 500 ? \substr($json, 0, 500) . "..." : $json;
    }
}
