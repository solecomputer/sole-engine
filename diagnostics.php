<?php

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

require_once __DIR__ . "/contracts.php";

// Module: Diagnostics store.
// Rationale: persist minimal local diagnostics without heavy state.


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
final class Diagnostics_Factory implements Diagnostics_FactoryInterface {
    public function makeStore(): Diagnostics_StoreInterface {
        return new Diagnostics_Store();
    }
}

final class Diagnostics_Store implements Diagnostics_StoreInterface {
    private const OPTION_LAST_SUCCESS = "sole_engine_last_success";
    private const OPTION_LAST_ERROR = "sole_engine_last_error";
    private const OPTION_LAST_ERROR_AT = "sole_engine_last_error_at";
    private const OPTION_ERROR_LOG = "sole_engine_error_log";
    private const ERROR_LOG_MAX_ENTRIES = 100;
    private const OPTION_LAST_HEALTH_STATUS = "sole_engine_last_health_status";
    private const OPTION_LAST_HEALTH_AT = "sole_engine_last_health_at";
    private const OPTION_LAST_ACCOUNT_STATUS = "sole_engine_last_account_status";
    private const OPTION_LAST_ACCOUNT_AT = "sole_engine_last_account_at";
    private const OPTION_LAST_ACCOUNT_QUOTA = "sole_engine_last_account_quota";
    private const OPTION_CALLER_CREDITS = "sole_engine_caller_credits";
    private const OPTION_CREDENTIAL_EVIDENCE = "sole_engine_credential_evidence";

    public function recordSuccess(string $timestamp): void {
        if (!\function_exists("update_option")) {
            return;
        }
        \update_option(self::OPTION_LAST_SUCCESS, $timestamp, false);
    }

    public function recordError(string $errorCode, string $context, string $timestamp): void {
        if (!\function_exists("update_option")) {
            return;
        }
        \update_option(self::OPTION_LAST_ERROR, $errorCode, false);
        \update_option(self::OPTION_LAST_ERROR_AT, $timestamp, false);
        $this->appendToErrorLog($errorCode, $context, $timestamp);
    }

    public function getErrorLog(): array {
        if (!\function_exists("get_option")) {
            return [];
        }
        $log = \get_option(self::OPTION_ERROR_LOG);
        return \is_array($log) ? $log : [];
    }

    private function appendToErrorLog(string $errorCode, string $context, string $timestamp): void {
        $log = $this->getErrorLog();
        $log[] = [
            "code" => $errorCode,
            "context" => $context,
            "timestamp" => $timestamp,
        ];
        // Ring buffer: drop oldest entries when exceeding max.
        if (\count($log) > self::ERROR_LOG_MAX_ENTRIES) {
            $log = \array_slice($log, -self::ERROR_LOG_MAX_ENTRIES);
        }
        \update_option(self::OPTION_ERROR_LOG, $log, false);
    }

    public function getLastSuccess(): ?string {
        if (!\function_exists("get_option")) {
            return null;
        }
        $value = \get_option(self::OPTION_LAST_SUCCESS);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function getLastError(): ?string {
        if (!\function_exists("get_option")) {
            return null;
        }
        $value = \get_option(self::OPTION_LAST_ERROR);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function getLastErrorAt(): ?string {
        if (!\function_exists("get_option")) {
            return null;
        }
        $value = \get_option(self::OPTION_LAST_ERROR_AT);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function recordHealthStatus(string $status, string $timestamp): void {
        if (!\function_exists("update_option")) {
            return;
        }
        \update_option(self::OPTION_LAST_HEALTH_STATUS, $status, false);
        \update_option(self::OPTION_LAST_HEALTH_AT, $timestamp, false);
    }

    public function recordAccountStatus(string $status, array $quota, string $timestamp): void {
        if (!\function_exists("update_option")) {
            return;
        }
        \update_option(self::OPTION_LAST_ACCOUNT_STATUS, $status, false);
        \update_option(self::OPTION_LAST_ACCOUNT_QUOTA, $quota, false);
        \update_option(self::OPTION_LAST_ACCOUNT_AT, $timestamp, false);
    }

    public function getLastHealthStatus(): ?string {
        if (!\function_exists("get_option")) {
            return null;
        }
        $value = \get_option(self::OPTION_LAST_HEALTH_STATUS);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function getLastHealthAt(): ?string {
        if (!\function_exists("get_option")) {
            return null;
        }
        $value = \get_option(self::OPTION_LAST_HEALTH_AT);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function getLastAccountStatus(): ?string {
        if (!\function_exists("get_option")) {
            return null;
        }
        $value = \get_option(self::OPTION_LAST_ACCOUNT_STATUS);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function getLastAccountAt(): ?string {
        if (!\function_exists("get_option")) {
            return null;
        }
        $value = \get_option(self::OPTION_LAST_ACCOUNT_AT);
        return \is_string($value) && $value !== "" ? $value : null;
    }

    public function getLastAccountQuota(): ?array {
        if (!\function_exists("get_option")) {
            return null;
        }
        $value = \get_option(self::OPTION_LAST_ACCOUNT_QUOTA);
        return \is_array($value) ? $value : null;
    }

    public function recordCallerCredits(string $callerId, float $credits): void {
        if (!\function_exists("update_option")) {
            return;
        }
        $data = $this->getCallerCredits();
        $data[$callerId] = ($data[$callerId] ?? 0) + $credits;
        \update_option(self::OPTION_CALLER_CREDITS, $data, false);
    }

    public function getCallerCredits(): array {
        if (!\function_exists("get_option")) {
            return [];
        }
        $data = \get_option(self::OPTION_CALLER_CREDITS);
        return \is_array($data) ? $data : [];
    }

    public function resetCallerCredits(): void {
        if (!\function_exists("delete_option")) {
            return;
        }
        \delete_option(self::OPTION_CALLER_CREDITS);
    }

    public function recordCredentialEvidence(string $userKey, string $state, string $timestamp): void {
        if (!\function_exists("update_option") || !\in_array($state, ["invalid", "verified"], true)) {
            return;
        }
        \update_option(self::OPTION_CREDENTIAL_EVIDENCE, [
            "fingerprint" => $this->credentialFingerprint($userKey),
            "state" => $state,
            "checked_at" => $timestamp,
        ], false);
    }

    public function getCredentialEvidence(string $userKey): ?array {
        if (!\function_exists("get_option")) {
            return null;
        }
        $evidence = \get_option(self::OPTION_CREDENTIAL_EVIDENCE);
        if (!\is_array($evidence)) {
            return null;
        }
        $fingerprint = $evidence["fingerprint"] ?? null;
        $state = $evidence["state"] ?? null;
        $checkedAt = $evidence["checked_at"] ?? null;
        if (!\is_string($fingerprint) || !\is_string($state) || !\is_string($checkedAt)) {
            return null;
        }
        if (!\in_array($state, ["invalid", "verified"], true)) {
            return null;
        }
        if (!\hash_equals($fingerprint, $this->credentialFingerprint($userKey))) {
            return null;
        }
        return ["state" => $state, "checked_at" => $checkedAt];
    }

    private function credentialFingerprint(string $userKey): string {
        // wp_hash is a site-secret-keyed, one-way digest. The deterministic
        // fallback exists only for the no-WordPress unit harness.
        if (\function_exists("wp_hash")) {
            return \wp_hash($userKey, "auth");
        }
        return \hash("sha256", "sole-engine-credential-evidence\0" . $userKey);
    }
}
