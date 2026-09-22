<?php

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

require_once __DIR__ . "/contracts.php";

// Module: Cache store.
// Rationale: centralize transient caching for pending jobs and replies.


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
final class Cache_Factory implements Cache_FactoryInterface {
    public function makeStore(): Cache_StoreInterface {
        return new Cache_Store();
    }
}

final class Cache_Store implements Cache_StoreInterface {
    private const PREFIX_JOB_HASH = "sole_engine_job_hash_";
    private const PREFIX_JOB_ID = "sole_engine_job_id_";
    private const PREFIX_JOB_REPLY = "sole_engine_job_reply_";
    private const PREFIX_SUBMIT_ERROR = "sole_engine_submit_error_";

    public function getJobIdForHash(string $hash): ?string {
        $value = $this->getTransient(self::PREFIX_JOB_HASH . $hash);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function setJobIdForHash(string $hash, string $jobId, int $ttlSeconds): void {
        $this->setTransient(self::PREFIX_JOB_HASH . $hash, $jobId, $ttlSeconds);
    }

    public function getHashForJobId(string $jobId): ?string {
        $value = $this->getTransient(self::PREFIX_JOB_ID . $jobId);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function setHashForJobId(string $jobId, string $hash, int $ttlSeconds): void {
        $this->setTransient(self::PREFIX_JOB_ID . $jobId, $hash, $ttlSeconds);
    }

    public function getReplyForJob(string $jobId): ?array {
        $value = $this->getTransient(self::PREFIX_JOB_REPLY . $jobId);
        return \is_array($value) ? $value : null;
    }

    public function setReplyForJob(string $jobId, array $reply, int $ttlSeconds): void {
        $this->setTransient(self::PREFIX_JOB_REPLY . $jobId, $reply, $ttlSeconds);
    }

    public function getSubmitErrorForHash(string $hash): ?string {
        $value = $this->getTransient(self::PREFIX_SUBMIT_ERROR . $hash);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function setSubmitErrorForHash(string $hash, string $error, int $ttlSeconds): void {
        $this->setTransient(self::PREFIX_SUBMIT_ERROR . $hash, $error, $ttlSeconds);
    }

    private function getTransient(string $key) {
        if (!\function_exists("get_transient")) {
            return null;
        }
        return \get_transient($key);
    }

    private function setTransient(string $key, $value, int $ttlSeconds): void {
        if (!\function_exists("set_transient")) {
            return;
        }
        \set_transient($key, $value, $ttlSeconds);
    }
}
