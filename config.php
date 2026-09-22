<?php

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

require_once __DIR__ . "/contracts.php";

// Module: Configuration store.
// Rationale: isolate WordPress option access from engine clients.


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
final class Config_Factory implements Config_FactoryInterface {
    public function makeStore(): Config_StoreInterface {
        return new Config_Store();
    }
}

// Immutable, read-only credential snapshot for public consumers. The
// diagnostics store owns exact-key matching and never returns credential
// material; this object exposes only the resulting state and evidence time.
final class Engine_CredentialReadiness implements \SoleEngineCredentialReadinessInterface {
    private string $state;
    private ?string $checkedAt;

    public function __construct(Config_StoreInterface $config, Diagnostics_StoreInterface $diagnostics) {
        $userKey = $config->getUserKey();
        if ($userKey === null) {
            $this->state = self::STATE_MISSING;
            $this->checkedAt = null;
            return;
        }

        $evidence = $diagnostics->getCredentialEvidence($userKey);
        $evidenceState = $evidence["state"] ?? null;
        if ($evidenceState === self::STATE_INVALID || $evidenceState === self::STATE_VERIFIED) {
            $this->state = $evidenceState;
            $this->checkedAt = $evidence["checked_at"];
            return;
        }

        $this->state = self::STATE_UNVERIFIED;
        $this->checkedAt = null;
    }

    public function getState(): string {
        return $this->state;
    }

    public function isReady(): bool {
        return $this->state === self::STATE_VERIFIED;
    }

    public function getCheckedAt(): ?string {
        return $this->checkedAt;
    }
}

final class Config_Store implements Config_StoreInterface, Config_WpAiInterface {
    private const DEFAULT_ENDPOINT = "https://engine.sole.computer/";
    private const OPTION_ENDPOINT = "sole_engine_endpoint";
    private const OPTION_DEBUG_MODE = "sole_engine_debug_mode";
    private const OPTION_USER_KEY = "sole_engine_user_key";
    private const OPTION_RETRY_ATTEMPTS = "sole_engine_retry_attempts";
    private const OPTION_SEMANTIC_ENABLED = "sole_engine_semantic_enabled";
    private const OPTION_CALLER_BLOCKLIST = "sole_engine_caller_blocklist";
    private const OPTION_SYNC_TIMEOUT = "sole_engine_sync_timeout";
    private const OPTION_WP_PROVIDER_ENABLED = "sole_engine_wp_provider_enabled";
    private const OPTION_FRONT_END_ALLOWED = "sole_engine_wp_front_end_allowed";

    // Synchronous-bridge HTTP wait bounds. The ceiling mirrors sole_engine_api's
    // PROVIDER_TIMEOUT_LLM_MS (180s): the central API gives up on the LLM at
    // 180s, so waiting longer can never succeed. If that API value changes,
    // this constant must move in lockstep (cross-repo coupling). Public so the
    // settings-field sanitizer in routines.php clamps against the same single
    // source rather than re-littering the bounds.
    public const SYNC_TIMEOUT_DEFAULT = 60;
    public const SYNC_TIMEOUT_MIN = 5;
    public const SYNC_TIMEOUT_CEILING = 180;

    public function getEndpoint(): ?string {
        if (!\function_exists("get_option")) {
            return null;
        }
        $debugMode = \get_option(self::OPTION_DEBUG_MODE);
        if (\is_string($debugMode)) {
            $debugMode = \trim($debugMode);
        } else {
            $debugMode = "";
        }
        $value = \get_option(self::OPTION_ENDPOINT);
        if (!\is_string($value)) {
            $value = "";
        }
        $value = \trim($value);
        if ($debugMode === "1") {
            if ($value === "") {
                return \rtrim(self::DEFAULT_ENDPOINT, "/");
            }
            // Normalize: trailing slash is irrelevant, always strip it.
            return \rtrim($value, "/");
        }
        return \rtrim(self::DEFAULT_ENDPOINT, "/");
    }

    public function getUserKey(): ?string {
        if (!\function_exists("get_option")) {
            return null;
        }
        $value = \get_option(self::OPTION_USER_KEY);
        if (!\is_string($value)) {
            return null;
        }
        $value = \trim($value);
        if ($value === "") {
            return null;
        }
        return $value;
    }

    public function getTotalAttempts(): int {
        if (!\function_exists("get_option")) {
            return 3;
        }
        $value = \get_option(self::OPTION_RETRY_ATTEMPTS);
        if (\is_numeric($value)) {
            $intValue = (int) $value;
            if ($intValue < 1) {
                return 1;
            }
            if ($intValue > 5) {
                return 5;
            }
            return $intValue;
        }
        return 3;
    }

    public function isSemanticEnabled(): bool {
        // Default to enabled if not set.
        return $this->readBoolOption(self::OPTION_SEMANTIC_ENABLED, true);
    }

    public function isCallerBlocked(string $callerId): bool {
        if (!\function_exists("get_option")) {
            return false;
        }
        $blocklist = \get_option(self::OPTION_CALLER_BLOCKLIST);
        if (!\is_array($blocklist)) {
            return false;
        }
        return \in_array($callerId, $blocklist, true);
    }

    public function getSyncTimeout(): int {
        if (!\function_exists("get_option")) {
            return self::SYNC_TIMEOUT_DEFAULT;
        }
        $value = \get_option(self::OPTION_SYNC_TIMEOUT);
        if (!\is_numeric($value)) {
            return self::SYNC_TIMEOUT_DEFAULT;
        }
        $intValue = (int) $value;
        if ($intValue < self::SYNC_TIMEOUT_MIN) {
            return self::SYNC_TIMEOUT_MIN;
        }
        if ($intValue > self::SYNC_TIMEOUT_CEILING) {
            return self::SYNC_TIMEOUT_CEILING;
        }
        return $intValue;
    }

    public function isWpProviderEnabled(): bool {
        // Default to enabled when unset: registering as a provider is the whole
        // point of the feature. Only an explicit falsy value disables it.
        return $this->readBoolOption(self::OPTION_WP_PROVIDER_ENABLED, true);
    }

    public function isFrontEndRenderAllowed(): bool {
        // Default to allowed when unset: WordPress's provider model leaves
        // authorization to the caller, and no bundled provider gates by request
        // context. Turning this off is a performance guard (a synchronous call
        // can block a visitor's render for up to the configured timeout), so
        // only an explicit falsy value restricts front-end renders.
        return $this->readBoolOption(self::OPTION_FRONT_END_ALLOWED, true);
    }

    // Read a boolean option that follows the plugin's "1"/"0" string convention
    // (how register_setting stores every checkbox here). $default applies when
    // the option is effectively unset — get_option() unavailable, or a stored
    // false/null/empty string. A stored string is true only when it trims to
    // exactly "1"; any other stored type falls back to a boolean cast.
    private function readBoolOption(string $key, bool $default): bool {
        if (!\function_exists("get_option")) {
            return $default;
        }
        $value = \get_option($key);
        if ($value === false || $value === null || $value === "") {
            return $default;
        }
        if (\is_string($value)) {
            return \trim($value) === "1";
        }
        return (bool) $value;
    }
}
