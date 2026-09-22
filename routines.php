<?php

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

/**
 * Routines module.
 *
 * High-level WordPress hook orchestration:
 * - Plugin bootstrap entrypoint.
 *
 * This module sits closest to WordPress and wires together other modules.
 */

require_once __DIR__ . "/contracts.php";


// ==========================================================================
// INTERFACES (module-private)
// ==========================================================================
// Judgement for the `ttl_resolver` self-test row, extracted so it can be
// exercised by the unit suite. While the judgement lived inline in the AJAX
// handler, nothing could reach it: the row could be rewritten to assert
// anything at all and no test would notice whether its branches fired.
interface Routines_TtlPostureCheckInterface {
    /**
     * @return list<string> One line per violated posture; empty when the
     *                      caching posture holds.
     */
    public function findViolations(Engine_ErrorTtlResolverInterface $resolver): array;
}


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
// A refusal and a reading are two different answers, and this engine returns
// them in ONE body. The degraded /v1/account/status replies HTTP 503 with
// `was_accepted:false` and an error — AND ALSO `"account_status":"disabled"`
// with `credits_used:0, credits_limit:0`. Those status fields are not a
// report about the account; they are the shape of a response that could not
// consult the registry at all.
//
// Read naively, a TRANSIENT REFUSAL TELLS AN ADMINISTRATOR THEIR ACCOUNT IS
// DISABLED AND THEIR QUOTA IS ZERO. "Disabled" is terminal and its cure is an
// administrator action; "the registry is briefly unreachable" cures itself.
// The expensive part is that the false reading PROVOKES A HUMAN INTO ACTING.
//
// Observed live on 2026-08-23 07:41Z when the engine gained its sole_account
// binding and began refusing correctly. A correct refusal and a real outage
// are INDISTINGUISHABLE AT THIS END, so this does not try to tell them apart:
// it declines to read status fields from any body that declares itself failed.
final class Routines_AccountStatusReading {

    // Nothing is recorded and nothing is displayed on a refusal. The previously
    // recorded status is left INTACT rather than overwritten or cleared: it is
    // the last thing the engine actually told us about this account, and a
    // stale true reading is more honest than a fresh false one. Clearing it
    // would substitute a second wrong answer for the first.
    public static function read(?array $decoded): array {
        if ($decoded === null) {
            return ["ok" => false, "error" => "api_response_invalid"];
        }
        if (($decoded["was_accepted"] ?? null) !== true) {
            $code = $decoded["error"]["code"] ?? null;
            return [
                "ok" => false,
                // The engine's own code, never a message invented here. The
                // words an administrator reads are not this coder's to write.
                "error" => \is_string($code) && $code !== "" ? $code : "api_response_invalid"
            ];
        }
        return [
            "ok" => true,
            "status" => isset($decoded["account_status"]) && \is_string($decoded["account_status"])
                ? $decoded["account_status"]
                : "unknown",
            "quota" => isset($decoded["quota"]) && \is_array($decoded["quota"]) ? $decoded["quota"] : []
        ];
    }
}

// One formatter owns every administrator-facing quota string. JavaScript only
// places these server-produced strings in the document; it never rounds or
// groups credit values independently.
final class Routines_QuotaPresentation {
    /** @param array<string, mixed> $quota @return array{quota_display:string,quota_percent_display:string} */
    public static function fromQuota(array $quota, bool $eligible): array {
        $unknown = ["quota_display" => "unknown", "quota_percent_display" => "unknown"];
        if (!$eligible || !isset($quota["credits_used"], $quota["credits_limit"])) {
            return $unknown;
        }
        $used = $quota["credits_used"];
        $limit = $quota["credits_limit"];
        if (
            (!\is_int($used) && !\is_float($used))
            || (!\is_int($limit) && !\is_float($limit))
            || !\is_finite((float) $used)
            || !\is_finite((float) $limit)
            || $used < 0
            || $limit <= 0
        ) {
            return $unknown;
        }
        return [
            "quota_display" => self::formatNumber((float) $used) . " of " . self::formatNumber((float) $limit),
            "quota_percent_display" => \number_format(((float) $used / (float) $limit) * 100, 2, ".", "") . "%",
        ];
    }

    private static function formatNumber(float $value): string {
        return \rtrim(\rtrim(\number_format($value, 2, ".", ","), "0"), ".");
    }
}

final class Routines_DiagnosticsSummary {
    public const FALLBACK = "Engine status is not fully verified; review the details below.";

    public static function fromReadings(?string $health, string $credential, ?string $account): string {
        if ($health !== "ok") {
            return self::FALLBACK;
        }
        if ($credential === "verified" && $account === "active") {
            return "Last recorded checks show Engine healthy and the saved key accepted.";
        }
        if ($credential === "missing") {
            return "Engine health can be checked, but no user key is saved.";
        }
        if ($credential === "invalid") {
            return "Engine is reachable, but the saved key was rejected.";
        }
        return self::FALLBACK;
    }

    public static function forAccountOutcome(
        bool $succeeded,
        ?string $error,
        ?string $health,
        string $credential,
        ?string $account
    ): string {
        if (!$succeeded && $error !== "user_key_invalid") {
            return self::FALLBACK;
        }
        return self::fromReadings($health, $credential, $account);
    }
}

// A non-empty option is only presence, never validity. API evidence is stored
// against a one-way fingerprint of the exact key, so a process-local test key
// cannot make a different saved key look healthy.
final class Routines_CredentialStatusReading {
    /** @return array{state:string,label:string,remediation:string,checked_at:?string} */
    public static function read(Config_StoreInterface $config, Diagnostics_StoreInterface $diagnostics): array {
        $readiness = new Engine_CredentialReadiness($config, $diagnostics);
        if ($readiness->getState() === \SoleEngineCredentialReadinessInterface::STATE_MISSING) {
            return [
                "state" => $readiness->getState(),
                "label" => "missing",
                "remediation" => "Enter an Engine user key and save the settings, then verify it with an account check.",
                "checked_at" => null,
            ];
        }
        if ($readiness->getState() === \SoleEngineCredentialReadinessInterface::STATE_INVALID) {
            return [
                "state" => $readiness->getState(),
                "label" => "invalid — rejected by API",
                "remediation" => "Replace the saved user key with a valid key from your SOLE account, then verify it again.",
                "checked_at" => $readiness->getCheckedAt(),
            ];
        }
        if ($readiness->getState() === \SoleEngineCredentialReadinessInterface::STATE_VERIFIED) {
            return [
                "state" => $readiness->getState(),
                "label" => "verified — accepted by API",
                "remediation" => "No action needed.",
                "checked_at" => $readiness->getCheckedAt(),
            ];
        }
        return [
            "state" => $readiness->getState(),
            "label" => "present — not verified",
            "remediation" => "Run Refresh Account Status to verify this saved key.",
            "checked_at" => null,
        ];
    }
}

// The ratified caching posture, expressed as checks rather than as a copy of
// the table. Each method of failure here corresponds to a decision that was
// argued and ratified on 2026-08-19, so a violation means a policy was
// reversed rather than that a number drifted.
final class Routines_TtlPostureCheck implements Routines_TtlPostureCheckInterface {
    // A code no arm can match, used to observe the resolver's default without
    // hardcoding it. If the default moves, this moves with it.
    private const UNMATCHABLE_CODE = "a_code_no_arm_can_match_xyzzy";

    // Faults whose cure is an administrator action. Caching one tells a person
    // who has already done the right thing that he did not.
    private const ADMIN_CURE_CODES = ["user_key_invalid", "account_disabled"];

    // Declared in the Landmark and never emitted. They must not acquire live
    // behaviour; a producer means un-retiring the term first.
    private const RETIRED_CODES = ["timeout", "rates_missing"];

    private const EXPECTED_DEFAULT = 180;

    public function findViolations(Engine_ErrorTtlResolverInterface $resolver): array {
        $violations = [];

        foreach (self::ADMIN_CURE_CODES as $code) {
            $ttl = $resolver->getTtlForError($code);
            if ($ttl !== 0) {
                $violations[] = $code . " is an administrator-cure fault and must never be cached, got " . $ttl . "s";
            }
        }

        // Deliberately NOT an administrator-cure fault: the cure for a quota is
        // time, so caching delays nothing a person could have shortened.
        if ($resolver->getTtlForError("quota_exceeded") === 0) {
            $violations[] = "quota_exceeded must stay cached: its cure is time, not an administrator action";
        }

        $default = $resolver->getTtlForError(self::UNMATCHABLE_CODE);
        foreach (self::RETIRED_CODES as $code) {
            if ($resolver->getTtlForError($code) !== $default) {
                $violations[] = $code . " is retired but carries a live rule of its own";
            }
        }

        if ($default !== self::EXPECTED_DEFAULT) {
            $violations[] = "unknown code: expected the " . self::EXPECTED_DEFAULT . "s default, got " . $default . "s";
        }

        return $violations;
    }

    public function describeDefault(Engine_ErrorTtlResolverInterface $resolver): int {
        return $resolver->getTtlForError(self::UNMATCHABLE_CODE);
    }
}

/**
 * Admin console routine: interactive testing of the public LLM and semantic contracts.
 * Uses sole_engine_llm() and sole_engine_semantic() exclusively.
 */
final class Routines_AdminConsole implements Routines_RoutineInterface {
    public function execute(): void {
        \add_action("wp_ajax_sole_engine_console_llm_submit", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_console", "_wpnonce");
            $task = isset($_POST["task"]) && \is_string($_POST["task"]) ? \trim(\wp_unslash($_POST["task"])) : "";
            $payload = isset($_POST["payload"]) && \is_string($_POST["payload"]) ? \wp_unslash($_POST["payload"]) : "";
            $optionsRaw = isset($_POST["options"]) && \is_string($_POST["options"]) ? \wp_unslash($_POST["options"]) : "";
            if ($task === "" || $payload === "") {
                \wp_send_json_error(["error" => "invalid_payload"]);
                return;
            }
            $options = null;
            if ($optionsRaw !== "") {
                $decoded = \json_decode($optionsRaw, true);
                if (\is_array($decoded)) {
                    $options = $decoded;
                }
            }
            $llm = sole_engine_llm();
            $ticket = $llm->submit("sole-engine-console", $task, $payload, $options);
            \wp_send_json_success([
                "was_accepted" => $ticket->wasAccepted(),
                "job_id" => $ticket->getJobId(),
                "error" => $ticket->getError(),
            ]);
        });

        \add_action("wp_ajax_sole_engine_console_llm_poll", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_console", "_wpnonce");
            $jobId = isset($_POST["job_id"]) && \is_string($_POST["job_id"]) ? \trim(\wp_unslash($_POST["job_id"])) : "";
            if ($jobId === "") {
                \wp_send_json_error(["error" => "invalid_payload"]);
                return;
            }
            $llm = sole_engine_llm();
            $result = $llm->getResult($jobId);
            \wp_send_json_success([
                "is_pending" => $result->isPending(),
                "is_successful" => $result->isSuccessful(),
                "reply" => $result->getReply(),
                "error" => $result->getError(),
            ]);
        });

        \add_action("wp_ajax_sole_engine_console_semantic_submit", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_console", "_wpnonce");
            $task = isset($_POST["task"]) && \is_string($_POST["task"]) ? \trim(\wp_unslash($_POST["task"])) : "";
            $payload = isset($_POST["payload"]) && \is_string($_POST["payload"]) ? \wp_unslash($_POST["payload"]) : "";
            $optionsRaw = isset($_POST["options"]) && \is_string($_POST["options"]) ? \wp_unslash($_POST["options"]) : "";
            if ($task === "" || $payload === "") {
                \wp_send_json_error(["error" => "invalid_payload"]);
                return;
            }
            $options = null;
            if ($optionsRaw !== "") {
                $decoded = \json_decode($optionsRaw, true);
                if (\is_array($decoded)) {
                    $options = $decoded;
                }
            }
            $ticket = sole_engine_semantic()->submit("sole-engine-console", $task, $payload, $options);
            \wp_send_json_success([
                "was_accepted" => $ticket->wasAccepted(),
                "job_id" => $ticket->getJobId(),
                "error" => $ticket->getError(),
            ]);
        });

        \add_action("wp_ajax_sole_engine_console_semantic_poll", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_console", "_wpnonce");
            $jobId = isset($_POST["job_id"]) && \is_string($_POST["job_id"]) ? \trim(\wp_unslash($_POST["job_id"])) : "";
            if ($jobId === "") {
                \wp_send_json_error(["error" => "invalid_payload"]);
                return;
            }
            $result = sole_engine_semantic()->getResult($jobId);
            \wp_send_json_success([
                "is_pending" => $result->isPending(),
                "is_successful" => $result->isSuccessful(),
                "reply" => $result->getReply(),
                "error" => $result->getError(),
            ]);
        });
    }

    public function renderConsole(): void {
        $showSemantic = sole_engine_semantic_enabled();
        $consoleNonce = \wp_create_nonce("sole_engine_console");

        echo "<hr style=\"margin:24px 0;\" />";
        echo "<h2>Console</h2>";
        echo "<p class=\"description\">Test the engine directly using the public contract. Each request uses your quota.</p>";

        echo "<h3>LLM</h3>";
        echo '<table class="form-table" role="presentation">';
        echo "<tr>";
        echo "<th scope=\"row\"><label for=\"sole_engine_console_llm_task\">Task</label></th>";
        echo "<td>";
        echo "<select id=\"sole_engine_console_llm_task\">";
        echo "<option value=\"chat\">chat</option>";
        echo "<option value=\"translate\">translate</option>";
        echo "<option value=\"summary\">summary</option>";
        echo "</select>";
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<th scope=\"row\"><label for=\"sole_engine_console_llm_preset\">Preset</label></th>";
        echo "<td><select id=\"sole_engine_console_llm_preset\"><option value=\"\">-- select preset --</option></select></td>";
        echo "</tr>";
        echo "<tr>";
        echo "<th scope=\"row\"><label for=\"sole_engine_console_llm_payload\">Payload</label></th>";
        echo "<td><textarea id=\"sole_engine_console_llm_payload\" rows=\"4\" class=\"large-text\"></textarea></td>";
        echo "</tr>";
        echo "<tr id=\"sole_engine_console_llm_row_targetLanguage\" style=\"display:none;\">";
        echo "<th scope=\"row\"><label for=\"sole_engine_console_llm_targetLanguage\">targetLanguage</label></th>";
        echo "<td><input type=\"text\" id=\"sole_engine_console_llm_targetLanguage\" class=\"regular-text\" /></td>";
        echo "</tr>";
        echo "<tr id=\"sole_engine_console_llm_row_sourceLanguage\" style=\"display:none;\">";
        echo "<th scope=\"row\"><label for=\"sole_engine_console_llm_sourceLanguage\">sourceLanguage (optional)</label></th>";
        echo "<td><input type=\"text\" id=\"sole_engine_console_llm_sourceLanguage\" class=\"regular-text\" /></td>";
        echo "</tr>";
        echo "<tr id=\"sole_engine_console_llm_row_size\" style=\"display:none;\">";
        echo "<th scope=\"row\"><label for=\"sole_engine_console_llm_size\">size (optional)</label></th>";
        echo "<td><input type=\"text\" id=\"sole_engine_console_llm_size\" class=\"regular-text\" /></td>";
        echo "</tr>";
        echo "<tr id=\"sole_engine_console_llm_row_extra\" style=\"display:none;\">";
        echo "<th scope=\"row\"><label for=\"sole_engine_console_llm_extra\">extra (optional)</label></th>";
        echo "<td><input type=\"text\" id=\"sole_engine_console_llm_extra\" class=\"regular-text\" /></td>";
        echo "</tr>";
        echo "</table>";
        echo "<div style=\"margin-top:8px;\">";
        echo "<button type=\"button\" class=\"button button-primary\" id=\"sole_engine_console_llm_send\">Send</button>";
        echo "</div>";
        echo "<h4 style=\"margin-top:16px;\">Output</h4>";
        echo "<textarea readonly rows=\"10\" id=\"sole_engine_console_llm_output\" style=\"width:100%;font-family:monospace;font-size:12px;\"></textarea>";

        if ($showSemantic) {
            echo "<h3 style=\"margin-top:24px;\">Semantic</h3>";
            echo '<table class="form-table" role="presentation">';
            echo "<tr>";
            echo "<th scope=\"row\"><label for=\"sole_engine_console_semantic_task\">Task</label></th>";
            echo "<td>";
            echo "<select id=\"sole_engine_console_semantic_task\">";
            echo "<option value=\"relevance\">relevance</option>";
            echo "<option value=\"search\">search</option>";
            echo "</select>";
            echo "</td>";
            echo "</tr>";
            echo "<tr>";
            echo "<th scope=\"row\"><label for=\"sole_engine_console_semantic_preset\">Preset</label></th>";
            echo "<td><select id=\"sole_engine_console_semantic_preset\"><option value=\"\">-- select preset --</option></select></td>";
            echo "</tr>";
            echo "<tr id=\"sole_engine_console_semantic_row_text_a\">";
            echo "<th scope=\"row\"><label for=\"sole_engine_console_semantic_text_a\">Text A</label></th>";
            echo "<td><textarea id=\"sole_engine_console_semantic_text_a\" rows=\"3\" class=\"large-text\"></textarea></td>";
            echo "</tr>";
            echo "<tr id=\"sole_engine_console_semantic_row_text_b\">";
            echo "<th scope=\"row\"><label for=\"sole_engine_console_semantic_text_b\">Text B</label></th>";
            echo "<td><textarea id=\"sole_engine_console_semantic_text_b\" rows=\"3\" class=\"large-text\"></textarea></td>";
            echo "</tr>";
            echo "<tr id=\"sole_engine_console_semantic_row_query\" style=\"display:none;\">";
            echo "<th scope=\"row\"><label for=\"sole_engine_console_semantic_query\">Query</label></th>";
            echo "<td><textarea id=\"sole_engine_console_semantic_query\" rows=\"3\" class=\"large-text\"></textarea></td>";
            echo "</tr>";
            echo "<tr id=\"sole_engine_console_semantic_row_top_k\" style=\"display:none;\">";
            echo "<th scope=\"row\"><label for=\"sole_engine_console_semantic_top_k\">top_k (optional)</label></th>";
            echo "<td><input type=\"number\" id=\"sole_engine_console_semantic_top_k\" class=\"small-text\" min=\"1\" max=\"50\" /></td>";
            echo "</tr>";
            echo "<tr id=\"sole_engine_console_semantic_row_post_types\" style=\"display:none;\">";
            echo "<th scope=\"row\"><label for=\"sole_engine_console_semantic_post_types\">post_types (optional, comma-separated)</label></th>";
            echo "<td><input type=\"text\" id=\"sole_engine_console_semantic_post_types\" class=\"regular-text\" /></td>";
            echo "</tr>";
            echo "</table>";
            echo "<div style=\"margin-top:8px;\">";
            echo "<button type=\"button\" class=\"button button-primary\" id=\"sole_engine_console_semantic_send\">Send</button>";
            echo "</div>";
            echo "<h4 style=\"margin-top:16px;\">Output</h4>";
            echo "<textarea readonly rows=\"10\" id=\"sole_engine_console_semantic_output\" style=\"width:100%;font-family:monospace;font-size:12px;\"></textarea>";
        }

        echo "<input type=\"hidden\" id=\"sole_engine_console_nonce\" value=\"" . \esc_attr($consoleNonce) . "\" />";
    }
}

/**
 * Admin diagnostics routine for engine status.
 */
final class Routines_AdminDiagnostics implements Routines_RoutineInterface {
    public function execute(): void {
        \add_action("admin_notices", function (): void {
            if (!\current_user_can("manage_options")) {
                return;
            }
            $credential = Routines_CredentialStatusReading::read(
                (new Config_Factory())->makeStore(),
                (new Diagnostics_Factory())->makeStore()
            );
            if ($credential["state"] === "missing") {
                echo '<div class="notice notice-warning"><p>Sole Engine is not configured yet.</p></div>';
            } elseif ($credential["state"] === "invalid") {
                echo '<div class="notice notice-error"><p>Sole Engine rejected the saved user key. Replace it with a valid key from your SOLE account, then verify it again.</p></div>';
            } elseif ($credential["state"] === "unverified") {
                echo '<div class="notice notice-warning"><p>Sole Engine has a saved user key, but it has not been verified. Open Sole Engine Diagnostics and refresh Account Status.</p></div>';
            }
        });

        \add_action("wp_ajax_sole_engine_health_check", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
            $config = new Config_Factory();
            $store = $config->makeStore();
            $endpoint = $store->getEndpoint();
            if ($endpoint === null) {
                \wp_send_json_error(["error" => "config_missing", "summary" => Routines_DiagnosticsSummary::FALLBACK]);
                return;
            }
            $http = new Http_Factory();
            $client = $http->makeClient(10);
            $url = \rtrim($endpoint, "/") . "/v1/health";
            $response = $client->getJson($url);
            $decoded = $this->decodeJson($response);
            if ($decoded === null) {
                \wp_send_json_error([
                    "error" => "api_response_invalid",
                    "summary" => Routines_DiagnosticsSummary::FALLBACK,
                ]);
                return;
            }
            $status = isset($decoded["status"]) && \is_string($decoded["status"]) ? $decoded["status"] : "unknown";
            $timestamp = \gmdate("c");
            $diagnostics = new Diagnostics_Factory();
            $store = $diagnostics->makeStore();
            $store->recordHealthStatus($status, $timestamp);
            \wp_send_json_success([
                "status" => $status,
                "timestamp" => $timestamp,
                "summary" => $this->readSummary(),
            ]);
        });

        \add_action("wp_ajax_sole_engine_account_status", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
            $config = new Config_Factory();
            $store = $config->makeStore();
            $endpoint = $store->getEndpoint();
            $userKey = $store->getUserKey();
            if ($endpoint === null || $userKey === null) {
                \wp_send_json_error(["error" => "config_missing", "summary" => Routines_DiagnosticsSummary::FALLBACK]);
                return;
            }
            $http = new Http_Factory();
            $client = $http->makeClient(10);
            $payload = [
                "user_key" => $userKey,
                "site_id" => (new Engine_SiteIdResolver())->resolve(\home_url())
            ];
            $url = \rtrim($endpoint, "/") . "/v1/account/status";
            Engine_HumanDemoBridge::mint("account_status", $url, $payload);
            $response = $client->postJson($url, $payload);
            $decoded = $this->decodeJson($response);
            if ($decoded === null) {
                \wp_send_json_error([
                    "error" => "api_response_invalid",
                    "summary" => Routines_DiagnosticsSummary::FALLBACK,
                ]);
                return;
            }
            $reading = Routines_AccountStatusReading::read($decoded);
            $diagnostics = new Diagnostics_Factory();
            $diagnosticsStore = $diagnostics->makeStore();
            if ($reading["ok"] !== true) {
                if ($reading["error"] === "user_key_invalid") {
                    $diagnosticsStore->recordCredentialEvidence($userKey, "invalid", \gmdate("c"));
                }
                \wp_send_json_error([
                    "error" => $reading["error"],
                    "summary" => $this->readAccountSummary(false, $reading["error"]),
                    ...Routines_QuotaPresentation::fromQuota([], false),
                ]);
                return;
            }
            $status = $reading["status"];
            $quota = $reading["quota"];
            $timestamp = \gmdate("c");
            $diagnosticsStore->recordCredentialEvidence($userKey, "verified", $timestamp);
            $diagnosticsStore->recordAccountStatus($status, $quota, $timestamp);
            $presentation = Routines_QuotaPresentation::fromQuota($quota, true);
            \wp_send_json_success([
                "status" => $status,
                "quota" => $quota,
                "timestamp" => $timestamp,
                "summary" => $this->readSummary(),
                ...$presentation,
            ]);
        });

        \add_action("wp_ajax_sole_engine_purge_site", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
            $config = new Config_Factory();
            $store = $config->makeStore();
            $endpoint = $store->getEndpoint();
            $userKey = $store->getUserKey();
            if ($endpoint === null || $userKey === null) {
                \wp_send_json_error(["error" => "config_missing"]);
                return;
            }
            $http = new Http_Factory();
            $client = $http->makeClient(30);
            $payload = [
                "user_key" => $userKey,
                "site_id" => (new Engine_SiteIdResolver())->resolve(\home_url()),
                "reason" => "admin_request"
            ];
            $url = \rtrim($endpoint, "/") . "/v1/sites/purge";
            Engine_HumanDemoBridge::mint("site_purge", $url, $payload);
            $response = $client->postJson($url, $payload);
            if ($response->getError() !== null) {
                \wp_send_json_error(["error" => "network_error"]);
                return;
            }
            if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
                \wp_send_json_error([
                    "error" => "api_error",
                    "status" => $response->getStatus()
                ]);
                return;
            }
            $decoded = \json_decode($response->getBody(), true);
            if (!\is_array($decoded)) {
                \wp_send_json_error(["error" => "api_response_invalid"]);
                return;
            }
            if (isset($decoded["was_accepted"]) && $decoded["was_accepted"] === true) {
                // Purge wipes remote vector store AND job history. Local
                // state must be cleared too, otherwise getResult() keeps
                // returning cached stale replies and the indexing lifecycle
                // sees existing content hashes as "already indexed" and
                // skips re-submission — the site would never reindex.
                $this->clearEngineTransients();
                if (\function_exists("delete_post_meta_by_key")) {
                    \delete_post_meta_by_key(Indexing_ContentProcessor::META_INDEXED_HASH);
                }
                \wp_send_json_success([
                    "message" => "Site data purge initiated. The site namespace is now locked until purge completes. Local cache and indexed-hash metadata cleared."
                ]);
                return;
            }
            $errorCode = isset($decoded["error"]["code"]) && \is_string($decoded["error"]["code"])
                ? $decoded["error"]["code"]
                : "unknown";
            // site_purging is a state, not a failure: a purge is already running.
            // Surface it as an informational success so the admin UI does not
            // show a red error for a benign "already in progress" reply.
            if ($errorCode === "site_purging") {
                \wp_send_json_success([
                    "message" => "Site data purge is already in progress. Wait for completion."
                ]);
                return;
            }
            \wp_send_json_error(["error" => $errorCode]);
        });

        \add_action("wp_ajax_sole_engine_refresh_error_log", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_refresh_error_log", "_wpnonce");
            $diagnostics = new Diagnostics_Factory();
            $store = $diagnostics->makeStore();
            $log = $store->getErrorLog();
            \wp_send_json_success(["log" => $this->formatErrorLogLines($log)]);
        });

        \add_action("wp_ajax_sole_engine_reset_caller_credits", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
            $diagnostics = new Diagnostics_Factory();
            $store = $diagnostics->makeStore();
            $store->resetCallerCredits();
            \wp_send_json_success(["message" => "Caller credit usage reset."]);
        });

        \add_action("wp_ajax_sole_engine_clear_cache", function (): void {
            if (!\current_user_can("manage_options")) {
                \wp_send_json_error(["error" => "forbidden"], 403);
                return;
            }
            \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
            $deleted = $this->clearEngineTransients();
            $message = "Cache cleared. " . $deleted . " entries deleted.";
            if (\function_exists("wp_using_ext_object_cache") && \wp_using_ext_object_cache()) {
                // External object caches store transients outside the DB, so
                // any entries that were never written to DB cannot be
                // enumerated here. They will expire via TTL as usual.
                $message .= " (External object cache detected — entries stored only in the object cache will expire via TTL.)";
            }
            \wp_send_json_success(["message" => $message]);
        });
    }

    public function renderHealthTable(): void {
        $diagnostics = new Diagnostics_Factory();
        $store = $diagnostics->makeStore();
        $lastHealthStatus = $store->getLastHealthStatus();
        $lastHealthAt = $store->getLastHealthAt();
        echo "<table class=\"widefat striped\">";
        echo "<tbody>";
        echo "<tr><th>Engine service health</th><td id=\"sole_engine_health_status\">" . \esc_html($lastHealthStatus ?? "unknown") . " <span class=\"description\">(does not verify the saved user key)</span></td></tr>";
        echo "<tr><th>Health checked at</th><td id=\"sole_engine_health_checked_at\">" . \esc_html($lastHealthAt ?? "never") . "</td></tr>";
        echo "</tbody>";
        echo "</table>";
    }

    public function renderSummary(): void {
        $summary = $this->readSummary();
        echo "<p id=\"sole_engine_diagnostics_summary\" data-fallback-summary=\""
            . \esc_attr(Routines_DiagnosticsSummary::FALLBACK) . "\">" . \esc_html($summary) . "</p>";
    }

    public function renderAccountTable(): void {
        $diagnostics = new Diagnostics_Factory();
        $store = $diagnostics->makeStore();
        $lastAccountStatus = $store->getLastAccountStatus();
        $lastAccountAt = $store->getLastAccountAt();
        $lastAccountQuota = $store->getLastAccountQuota();
        $credential = Routines_CredentialStatusReading::read((new Config_Factory())->makeStore(), $store);
        $presentation = Routines_QuotaPresentation::fromQuota(
            \is_array($lastAccountQuota) ? $lastAccountQuota : [],
            $credential["state"] === "verified"
        );
        if ($credential["state"] !== "verified") {
            $lastAccountStatus = match ($credential["state"]) {
                "missing" => "not configured",
                "invalid" => "unavailable — saved key invalid",
                default => "not verified for saved key",
            };
            $lastAccountAt = null;
        }
        echo "<table class=\"widefat striped\">";
        echo "<tbody>";
        echo "<tr><th>Account status</th><td id=\"sole_engine_account_status\">" . \esc_html($lastAccountStatus ?? "unknown") . "</td></tr>";
        echo "<tr><th>Account checked at</th><td id=\"sole_engine_account_checked_at\">" . \esc_html($lastAccountAt ?? "never") . "</td></tr>";
        echo "<tr><th>Quota used</th><td id=\"sole_engine_quota_used\">" . \esc_html($presentation["quota_display"]) . "</td></tr>";
        echo "<tr><th>Quota usage</th><td id=\"sole_engine_quota_usage\">" . \esc_html($presentation["quota_percent_display"]) . "</td></tr>";
        echo "</tbody>";
        echo "</table>";
    }

    private function readSummary(): string {
        $diagnostics = (new Diagnostics_Factory())->makeStore();
        $credential = Routines_CredentialStatusReading::read((new Config_Factory())->makeStore(), $diagnostics);
        return Routines_DiagnosticsSummary::fromReadings(
            $diagnostics->getLastHealthStatus(),
            $credential["state"],
            $diagnostics->getLastAccountStatus()
        );
    }

    private function readAccountSummary(bool $succeeded, ?string $error): string {
        $diagnostics = (new Diagnostics_Factory())->makeStore();
        $credential = Routines_CredentialStatusReading::read((new Config_Factory())->makeStore(), $diagnostics);
        return Routines_DiagnosticsSummary::forAccountOutcome(
            $succeeded,
            $error,
            $diagnostics->getLastHealthStatus(),
            $credential["state"],
            $diagnostics->getLastAccountStatus()
        );
    }

    public function renderDiagnosticsTable(): void {
        $diagnostics = new Diagnostics_Factory();
        $store = $diagnostics->makeStore();
        $endpoint = \get_option("sole_engine_endpoint");
        $debugMode = \get_option("sole_engine_debug_mode");
        $credential = Routines_CredentialStatusReading::read((new Config_Factory())->makeStore(), $store);
        $endpointStatus = "default";
        if (\is_string($debugMode) && \trim($debugMode) === "1") {
            $endpointStatus = \is_string($endpoint) && \trim($endpoint) !== "" ? "custom" : "default";
        }
        $credentialAcceptedAt = $credential["state"] === "verified" ? ($credential["checked_at"] ?? "unknown") : "none for saved key";
        $lastError = $credential["state"] === "invalid" ? "user_key_invalid" : "none for saved key";
        $lastErrorAt = $credential["state"] === "invalid" ? ($credential["checked_at"] ?? "unknown") : "none for saved key";

        echo "<table class=\"widefat striped\">";
        echo "<tbody>";
        echo "<tr><th>Endpoint</th><td>" . \esc_html($endpointStatus) . "</td></tr>";
        echo "<tr><th>Saved user key</th><td>" . \esc_html($credential["label"]) . "</td></tr>";
        echo "<tr><th>Credential action</th><td>" . \esc_html($credential["remediation"]) . "</td></tr>";
        echo "<tr><th>Saved key accepted by API at</th><td>" . \esc_html($credentialAcceptedAt) . "</td></tr>";
        echo "<tr><th>Last error</th><td>" . \esc_html($lastError) . "</td></tr>";
        echo "<tr><th>Last error at</th><td>" . \esc_html($lastErrorAt) . "</td></tr>";
        echo "</tbody>";
        echo "</table>";
    }

    public function renderErrorLog(): void {
        $diagnostics = new Diagnostics_Factory();
        $store = $diagnostics->makeStore();
        $log = $store->getErrorLog();
        $lines = $this->formatErrorLogLines($log);
        echo "<textarea readonly rows=\"12\" id=\"sole_engine_error_log\" style=\"width:100%;font-family:monospace;font-size:12px;\">";
        echo \esc_textarea($lines);
        echo "</textarea>";
        echo "<div style=\"margin-top:8px;\">";
        echo "<button type=\"button\" class=\"button\" id=\"sole_engine_refresh_error_log\">Refresh</button>";
        echo "</div>";
        // Auto-scroll to bottom on load, and refresh via AJAX.
        echo "<script>";
        echo "(function(){";
        echo "var el=document.getElementById('sole_engine_error_log');";
        echo "if(el){el.scrollTop=el.scrollHeight;}";
        echo "var btn=document.getElementById('sole_engine_refresh_error_log');";
        echo "if(btn){btn.addEventListener('click',function(){";
        echo "btn.disabled=true;btn.textContent='Refreshing...';";
        echo "jQuery.post(ajaxurl,{action:'sole_engine_refresh_error_log',_wpnonce:'" . \esc_js(\wp_create_nonce("sole_engine_refresh_error_log")) . "'},function(resp){";
        echo "btn.disabled=false;btn.textContent='Refresh';";
        echo "if(resp.success&&typeof resp.data.log==='string'){";
        echo "el.value=resp.data.log;el.scrollTop=el.scrollHeight;";
        echo "}});});";
        echo "}";
        echo "})();";
        echo "</script>";
    }

    private function formatErrorLogLines(array $log): string {
        $lines = "";
        foreach ($log as $entry) {
            $timestamp = isset($entry["timestamp"]) && \is_string($entry["timestamp"]) ? $entry["timestamp"] : "";
            $code = isset($entry["code"]) && \is_string($entry["code"]) ? $entry["code"] : "";
            $context = isset($entry["context"]) && \is_string($entry["context"]) ? $entry["context"] : "";
            $lines .= $timestamp . "  " . $code . "  " . $context . "\n";
        }
        return $lines;
    }

    public function renderCallerCreditsTable(): void {
        $diagnostics = new Diagnostics_Factory();
        $store = $diagnostics->makeStore();
        $credits = $store->getCallerCredits();
        echo "<div id=\"sole_engine_caller_credits_wrap\">";
        if (\count($credits) === 0) {
            echo "<p>No usage recorded.</p>";
        } else {
            echo "<table class=\"widefat striped\" style=\"max-width:500px;\">";
            echo "<thead><tr><th>Caller</th><th>Credits used</th></tr></thead>";
            echo "<tbody>";
            foreach ($credits as $caller => $total) {
                echo "<tr><td>" . \esc_html((string) $caller) . "</td><td>" . \esc_html((string) $total) . "</td></tr>";
            }
            echo "</tbody>";
            echo "</table>";
        }
        echo "</div>";
        echo "<div style=\"margin-top:8px;\">";
        echo "<button type=\"button\" class=\"button\" id=\"sole_engine_reset_caller_credits\">Reset</button>";
        echo "<span id=\"sole_engine_caller_credits_result\" style=\"margin-left:8px;\"></span>";
        echo "</div>";
        echo "<script>";
        echo "(function(){";
        echo "var btn=document.getElementById('sole_engine_reset_caller_credits');";
        echo "if(btn){btn.addEventListener('click',function(){";
        echo "if(!confirm('Reset all per-caller credit usage data?'))return;";
        echo "btn.disabled=true;btn.textContent='Resetting...';";
        echo "var nonce=document.getElementById('sole_engine_diagnostics_nonce');";
        echo "jQuery.post(ajaxurl,{action:'sole_engine_reset_caller_credits',_wpnonce:nonce?nonce.value:''},function(resp){";
        echo "btn.disabled=false;btn.textContent='Reset';";
        echo "var el=document.getElementById('sole_engine_caller_credits_result');";
        echo "if(resp.success){";
        echo "var w=document.getElementById('sole_engine_caller_credits_wrap');";
        echo "if(w){w.textContent='No usage recorded.';}";
        echo "if(el){el.textContent='Done.';}";
        echo "}else{if(el){el.textContent='Error.';}}";
        echo "});});";
        echo "}";
        echo "})();";
        echo "</script>";
    }

    private function decodeJson(Http_ResponseInterface $response): ?array {
        if ($response->getError() !== null) {
            return null;
        }
        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            return null;
        }
        $decoded = \json_decode($response->getBody(), true);
        return \is_array($decoded) ? $decoded : null;
    }

    // Clear all SOLE-engine transients (job hashes, job ids, replies, submit
    // errors). Routes removal through delete_transient() so external object
    // caches are invalidated in the same call. Also sweeps leftover timeout
    // rows. Shared by the purge handler and the clear-cache handler.
    private function clearEngineTransients(): int {
        global $wpdb;
        $prefixes = [
            "sole_engine_job_hash_",
            "sole_engine_job_id_",
            "sole_engine_job_reply_",
            "sole_engine_submit_error_",
        ];
        $deleted = 0;
        foreach ($prefixes as $prefix) {
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like("_transient_" . $prefix) . "%"
                )
            );
            foreach ($rows as $optionName) {
                if (\is_string($optionName) && \str_starts_with($optionName, "_transient_")) {
                    $key = \substr($optionName, \strlen("_transient_"));
                    if (\delete_transient($key)) {
                        $deleted++;
                    }
                }
            }
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like("_transient_" . $prefix) . "%",
                    $wpdb->esc_like("_transient_timeout_" . $prefix) . "%"
                )
            );
        }
        return $deleted;
    }
}

/**
 * Admin settings routine for engine configuration.
 */
final class Routines_AdminSettings implements Routines_RoutineInterface {
    private const MENU_SLUG = SOLE_ENGINE_ADMIN_MENU_SLUG;
    private const SETTINGS_GROUP = "sole_engine_wp_settings_group";
    private const SETTINGS_PAGE = "sole_engine_wp_settings_page";

    public function execute(): void {
        \add_action("admin_init", function (): void {
            \register_setting(
                self::SETTINGS_GROUP,
                "sole_engine_endpoint",
                [
                    "type" => "string",
                    "sanitize_callback" => function ($value): string {
                        if (!\is_string($value)) {
                            return "";
                        }
                        return \trim($value);
                    },
                    "default" => "",
                    "show_in_rest" => false
                ]
            );

            \register_setting(
                self::SETTINGS_GROUP,
                "sole_engine_debug_mode",
                [
                    "type" => "boolean",
                    "sanitize_callback" => function ($value): string {
                        if (\is_string($value)) {
                            return \trim($value) === "1" ? "1" : "0";
                        }
                        return $value ? "1" : "0";
                    },
                    "default" => "0",
                    "show_in_rest" => false
                ]
            );

            \register_setting(
                self::SETTINGS_GROUP,
                "sole_engine_user_key",
                [
                    "type" => "string",
                    "sanitize_callback" => function ($value): string {
                        if (!\is_string($value)) {
                            return "";
                        }
                        return \trim($value);
                    },
                    "default" => "",
                    "show_in_rest" => false
                ]
            );

            \register_setting(
                self::SETTINGS_GROUP,
                "sole_engine_retry_attempts",
                [
                    "type" => "integer",
                    "sanitize_callback" => function ($value): int {
                        if (!\is_numeric($value)) {
                            return 3;
                        }
                        $intValue = (int) $value;
                        if ($intValue < 1) {
                            return 1;
                        }
                        if ($intValue > 5) {
                            return 5;
                        }
                        return $intValue;
                    },
                    "default" => 3,
                    "show_in_rest" => false
                ]
            );

            \register_setting(
                self::SETTINGS_GROUP,
                "sole_engine_semantic_enabled",
                [
                    "type" => "boolean",
                    "sanitize_callback" => function ($value): string {
                        if (\is_string($value)) {
                            return \trim($value) === "1" ? "1" : "0";
                        }
                        return $value ? "1" : "0";
                    },
                    "default" => "1",
                    "show_in_rest" => false
                ]
            );

            \register_setting(
                self::SETTINGS_GROUP,
                "sole_engine_sync_timeout",
                [
                    "type" => "integer",
                    // Clamp against Config_Store's single-source bounds so the
                    // stored value never disagrees with the effective timeout.
                    "sanitize_callback" => function ($value): int {
                        if (!\is_numeric($value)) {
                            return Config_Store::SYNC_TIMEOUT_DEFAULT;
                        }
                        $intValue = (int) $value;
                        if ($intValue < Config_Store::SYNC_TIMEOUT_MIN) {
                            return Config_Store::SYNC_TIMEOUT_MIN;
                        }
                        if ($intValue > Config_Store::SYNC_TIMEOUT_CEILING) {
                            return Config_Store::SYNC_TIMEOUT_CEILING;
                        }
                        return $intValue;
                    },
                    "default" => Config_Store::SYNC_TIMEOUT_DEFAULT,
                    "show_in_rest" => false
                ]
            );

            \register_setting(
                self::SETTINGS_GROUP,
                "sole_engine_wp_provider_enabled",
                [
                    "type" => "boolean",
                    "sanitize_callback" => function ($value): string {
                        if (\is_string($value)) {
                            return \trim($value) === "1" ? "1" : "0";
                        }
                        return $value ? "1" : "0";
                    },
                    "default" => "1",
                    "show_in_rest" => false
                ]
            );

            \register_setting(
                self::SETTINGS_GROUP,
                "sole_engine_wp_front_end_allowed",
                [
                    "type" => "boolean",
                    "sanitize_callback" => function ($value): string {
                        if (\is_string($value)) {
                            return \trim($value) === "1" ? "1" : "0";
                        }
                        return $value ? "1" : "0";
                    },
                    "default" => "1",
                    "show_in_rest" => false
                ]
            );

            \register_setting(
                self::SETTINGS_GROUP,
                "sole_engine_caller_blocklist",
                [
                    "type" => "array",
                    "sanitize_callback" => function ($value): array {
                        if (\is_array($value)) {
                            $slugs = \array_map("trim", \array_map("strval", $value));
                        } elseif (\is_string($value)) {
                            $slugs = \array_map("trim", \explode(",", $value));
                        } else {
                            return [];
                        }
                        return \array_values(\array_filter($slugs, function (string $s): bool {
                            return $s !== "";
                        }));
                    },
                    "default" => [],
                    "show_in_rest" => false
                ]
            );

            \add_settings_section(
                "sole_engine_main_section",
                "Sole Engine Settings",
                function (): void {
                    echo "<p>Configure the Sole Engine connection for this site.</p>";
                },
                self::SETTINGS_PAGE
            );
        });

        \add_action("admin_menu", function (): void {
            $hookSuffix = \add_options_page(
                "Sole Engine",
                "Sole Engine",
                "manage_options",
                self::MENU_SLUG,
                [$this, "renderPage"]
            );
            if (\is_string($hookSuffix) && $hookSuffix !== "") {
                \do_action("sole_engine_settings_page_registered", $hookSuffix);
            }
        });
    }

    /**
     * The TTL map handed to the admin script, built BY ASKING THE RESOLVER
     * rather than by restating it.
     *
     * The code list is still written here, but a wrong list yields a MISSING
     * hint and never a WRONG TTL, because every value is the resolver's own
     * answer. Engine_TtlLandmarkConformanceTest covers the values themselves
     * against the published Landmark.
     *
     * @return array<string,int>
     */
    private function buildErrorTtlMap(): array {
        $resolver = new Engine_ErrorTtlResolver();
        $codes = [
            "site_purging", "caller_blocked", "semantic_disabled",
            "user_key_invalid", "account_disabled", "network_error",
            "rate_limited", "provider_unavailable", "provider_error",
            "engine_internal", "config_missing", "quota_exceeded",
            "invalid_payload", "index_invalid", "api_response_invalid",
            "job_not_found", "task_unsupported",
        ];
        $map = [];
        foreach ($codes as $code) {
            $map[$code] = $resolver->getTtlForError($code);
        }
        return $map;
    }

    public function renderPage(): void {
        if (!\current_user_can("manage_options")) {
            return;
        }
        echo '<div class="wrap">';
        echo "<h1>Sole Engine</h1>";
        echo '<h2 class="nav-tab-wrapper" id="sole_engine_tabs">';
        echo '<a href="#console" class="nav-tab nav-tab-active" data-tab="console">Console</a>';
        echo '<a href="#diagnostics" class="nav-tab" data-tab="diagnostics">Diagnostics</a>';
        echo '</h2>';
        echo "<script>
          (function() {
            var tabLinks = document.querySelectorAll('#sole_engine_tabs .nav-tab');
            var switchTab = function(tabId) {
              var i;
              for (i = 0; i < tabLinks.length; i++) {
                if (tabLinks[i].getAttribute('data-tab') === tabId) {
                  tabLinks[i].classList.add('nav-tab-active');
                } else {
                  tabLinks[i].classList.remove('nav-tab-active');
                }
              }
              var panels = document.querySelectorAll('.sole-engine-tab-panel');
              for (i = 0; i < panels.length; i++) {
                if (panels[i].id === 'sole_engine_tab_' + tabId) {
                  panels[i].style.display = '';
                } else {
                  panels[i].style.display = 'none';
                }
              }
            };
            for (var t = 0; t < tabLinks.length; t++) {
              (function(link) {
                link.addEventListener('click', function(e) {
                  e.preventDefault();
                  var id = link.getAttribute('data-tab');
                  switchTab(id);
                  window.location.hash = id;
                });
              })(tabLinks[t]);
            }
            var ready = function() {
              var hash = window.location.hash.replace('#', '');
              if (hash === 'console' || hash === 'diagnostics') {
                switchTab(hash);
              }
            };
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', ready);
            } else {
              ready();
            }
          })();
        </script>";
        echo '<div id="sole_engine_tab_console" class="sole-engine-tab-panel">';
        echo '<form method="post" action="options.php">';
        \settings_fields(self::SETTINGS_GROUP);
        \do_settings_sections(self::SETTINGS_PAGE);

        $userKey = \get_option("sole_engine_user_key");
        if (!\is_string($userKey)) {
            $userKey = "";
        }
        $debugMode = \get_option("sole_engine_debug_mode");
        $debugModeValue = "0";
        if (\is_string($debugMode)) {
            $debugModeValue = \trim($debugMode) === "1" ? "1" : "0";
        } elseif (\is_numeric($debugMode)) {
            $debugModeValue = ((int) $debugMode) === 1 ? "1" : "0";
        } elseif ($debugMode) {
            $debugModeValue = "1";
        }
        $endpointValue = \get_option("sole_engine_endpoint");
        if (!\is_string($endpointValue)) {
            $endpointValue = "";
        }
        $endpointValue = \trim($endpointValue);
        $retryAttempts = \get_option("sole_engine_retry_attempts");
        if (!\is_numeric($retryAttempts)) {
            $retryAttempts = 3;
        }
        $syncTimeout = \get_option("sole_engine_sync_timeout");
        if (!\is_numeric($syncTimeout)) {
            $syncTimeout = Config_Store::SYNC_TIMEOUT_DEFAULT;
        }
        // The one timeout number we can actually read from PHP. The real ceiling
        // (web-server/FPM gateway timeout) is not visible here; we surface this
        // as guidance so the admin can set a sane value for their host.
        $maxExecHint = \function_exists("ini_get") ? (int) \ini_get("max_execution_time") : 0;
        $wpProviderEnabled = \get_option("sole_engine_wp_provider_enabled");
        $wpProviderEnabledValue = $wpProviderEnabled === "0" ? "0" : "1";
        $frontEndAllowed = \get_option("sole_engine_wp_front_end_allowed");
        $frontEndAllowedValue = $frontEndAllowed === "0" ? "0" : "1";
        $semanticEnabled = \get_option("sole_engine_semantic_enabled");
        $semanticEnabledValue = "1";
        if ($semanticEnabled === "0") {
            $semanticEnabledValue = "0";
        }
        $callerBlocklist = \get_option("sole_engine_caller_blocklist");
        $callerBlocklistValue = \is_array($callerBlocklist) ? \implode(", ", $callerBlocklist) : "";

        echo '<table class="form-table" role="presentation">';
        echo "<tr>";
        echo '<th scope="row"><label for="sole_engine_user_key">Engine User Key</label></th>';
        echo "<td>";
        echo '<input type="text" name="sole_engine_user_key" id="sole_engine_user_key" value="' .
            \esc_attr($userKey) .
            '" class="regular-text" />';
        echo "<p class=\"description\">Keep this key private. It authenticates requests to the engine.</p>";
        echo '<p class="description">Create or manage a key in <a href="' .
            \esc_url("https://account.sole.computer/") .
            '">' .
            \esc_html("your SOLE account") .
            '</a>. Copy the full key when it is created, keep it private, paste it into Engine User Key, and choose Save Settings.</p>';
        echo "<p class=\"description\">After saving, this settings page automatically checks Account Status. Reload it, open Diagnostics, and confirm <code>Saved user key</code> shows <code>verified — accepted by API</code>. To force another check at any time, choose Refresh Account Status; a successful forced check immediately says <code>Saved key accepted by API.</code></p>";
        echo "<p class=\"description\">If the key is rejected, create a replacement in your SOLE account, replace and save it here, refresh Account Status again, and revoke keys you no longer use.</p>";
        echo "<p class=\"description\">Submit calls may return cached job ids or results when inputs match recent requests.</p>";
        echo "</td>";
        echo "</tr>";
        echo "</table>";

        echo "<details style=\"margin-top:16px;\">";
        echo "<summary><strong>Advanced settings</strong></summary>";
        echo '<table class="form-table" role="presentation" style="margin-top:8px;">';
        echo "<tr>";
        echo "<th scope=\"row\"><label for=\"sole_engine_retry_attempts\">Total attempts</label></th>";
        echo "<td>";
        echo '<input type="number" min="1" max="5" name="sole_engine_retry_attempts" id="sole_engine_retry_attempts" value="' .
            \esc_attr((string) $retryAttempts) .
            '" class="small-text" />';
        echo "<p class=\"description\">Total number of attempts per request. Applies only to transient network or provider failures.</p>";
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<th scope=\"row\"><label for=\"sole_engine_sync_timeout\">AI provider response timeout</label></th>";
        echo "<td>";
        echo '<input type="number" min="' . \esc_attr((string) Config_Store::SYNC_TIMEOUT_MIN) . '" max="' . \esc_attr((string) Config_Store::SYNC_TIMEOUT_CEILING) . '" name="sole_engine_sync_timeout" id="sole_engine_sync_timeout" value="' .
            \esc_attr((string) $syncTimeout) .
            '" class="small-text" /> seconds';
        echo "<p class=\"description\">How long the WordPress 7 AI Client provider waits for an inline answer before giving up. Only the WP AI Client surface is affected; plugins that call the engine directly use the asynchronous path and are unaffected.</p>";
        if ($maxExecHint > 0) {
            echo "<p class=\"description\">Your server reports PHP <code>max_execution_time</code> = " . \esc_html((string) $maxExecHint) . " seconds. Set this at or below your host's request timeout.</p>";
        } else {
            echo "<p class=\"description\">Your server reports no PHP <code>max_execution_time</code> limit; your web server's gateway timeout is the real ceiling and is not visible here.</p>";
        }
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<th scope=\"row\"><label for=\"sole_engine_wp_provider_enabled\">WordPress AI Client provider</label></th>";
        echo "<td>";
        echo "<label>";
        echo '<input type="checkbox" name="sole_engine_wp_provider_enabled" id="sole_engine_wp_provider_enabled" value="1" ' .
            \checked("1", $wpProviderEnabledValue, false) .
            " />";
        echo "Register SOLE as an AI provider for WordPress 7";
        echo "</label>";
        echo "<p class=\"description\">When enabled (and running on WordPress 7+), other plugins and the block editor can use SOLE through WordPress's native AI Client. Disable to stop registering the provider entirely.</p>";
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<th scope=\"row\"><label for=\"sole_engine_wp_front_end_allowed\">Allow on front-end requests</label></th>";
        echo "<td>";
        echo "<label>";
        echo '<input type="checkbox" name="sole_engine_wp_front_end_allowed" id="sole_engine_wp_front_end_allowed" value="1" ' .
            \checked("1", $frontEndAllowedValue, false) .
            " />";
        echo "Allow the AI Client provider to run during front-end page requests";
        echo "</label>";
        echo "<p class=\"description\">Affects only the WordPress AI Client provider surface. When enabled (the default), a synchronous AI call may run while a visitor is loading a page &mdash; which can block that page render for up to the response timeout above. Turn this off to confine the provider to admin, REST, cron, and AJAX requests and protect front-end page-load performance.</p>";
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<th scope=\"row\"><label for=\"sole_engine_semantic_enabled\">Semantic features</label></th>";
        echo "<td>";
        echo "<label>";
        echo '<input type="checkbox" name="sole_engine_semantic_enabled" id="sole_engine_semantic_enabled" value="1" ' .
            \checked("1", $semanticEnabledValue, false) .
            " />";
        echo "Enable semantic features (relevance, search)";
        echo "</label>";
        echo "<p class=\"description\">Semantic features allow plugins to compare content similarity and search across your site.</p>";
        echo "<p class=\"description\">When enabled, content embeddings are stored on the central API. Disable this if you prefer not to store any site content data remotely.</p>";
        echo "<p class=\"description\"><strong>Note:</strong> Disabling this will break any dependent plugins that rely on semantic search or relevance features. LLM features (chat, translate, summary) remain available.</p>";
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo '<th scope="row"><label for="sole_engine_caller_blocklist">Blocked callers</label></th>';
        echo "<td>";
        echo '<input type="text" name="sole_engine_caller_blocklist" id="sole_engine_caller_blocklist" value="' .
            \esc_attr($callerBlocklistValue) .
            '" class="regular-text" />';
        echo "<p class=\"description\">Comma-separated list of plugin slugs to block from using the engine (e.g. <code>bad-plugin, another-plugin</code>).</p>";
        echo "<p class=\"description\">Blocked plugins receive a <code>caller_blocked</code> error immediately. Leave empty to allow all callers.</p>";
        echo "</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<th scope=\"row\"><label for=\"sole_engine_debug_mode\">Debug mode</label></th>";
        echo "<td>";
        echo '<label>';
        echo '<input type="checkbox" name="sole_engine_debug_mode" id="sole_engine_debug_mode" value="1" ' .
            \checked("1", $debugModeValue, false) .
            " />";
        echo "Enable diagnostics and advanced overrides.";
        echo "</label>";
        echo "<p class=\"description\">Debug mode is for local testing only. Do not change it on production sites.</p>";
        echo "</td>";
        echo "</tr>";
        $endpointStyle = $debugModeValue === "1" ? "" : "display:none;";
        echo "<tr id=\"sole_engine_endpoint_row\" style=\"" . \esc_attr($endpointStyle) . "\">";
        echo "<th scope=\"row\"><label for=\"sole_engine_endpoint\">Custom endpoint</label></th>";
        echo "<td>";
        echo '<input type="text" name="sole_engine_endpoint" id="sole_engine_endpoint" value="' .
            \esc_attr($endpointValue) .
            '" class="regular-text" />';
        echo "<p class=\"description\">For local testing only. Leave blank to use the default engine.</p>";
        echo "</td>";
        echo "</tr>";
        echo "</table>";
        echo "</details>";
        echo "<div id=\"sole_engine_save_bar\" style=\"position:sticky;bottom:0;background:#fff;border-top:1px solid #ccd0d4;padding:4px 0;margin-top:12px;text-align:center;\">";
        \submit_button("Save Settings", "primary", "submit", true, ["id" => "sole_engine_save_button"]);
        echo "<div id=\"sole_engine_save_note\" style=\"color:#b32d2e;display:none;margin-top:6px;\">";
        echo "Changes are not saved until you click Save Settings.";
        echo "</div>";
        echo "</div>";
        echo "</form>";
        $console = new Routines_AdminConsole();
        $console->renderConsole();
        echo "</div>";
        echo '<div id="sole_engine_tab_diagnostics" class="sole-engine-tab-panel" style="display:none;">';
        $diagnosticsNonce = \wp_create_nonce("sole_engine_diagnostics");
        echo "<input type=\"hidden\" id=\"sole_engine_diagnostics_nonce\" value=\"" . \esc_attr($diagnosticsNonce) . "\" />";
        $diagnostics = new Routines_AdminDiagnostics();
        echo "<h2>Diagnostics</h2>";
        $diagnostics->renderSummary();
        echo "<h3>Health</h3>";
        $diagnostics->renderHealthTable();
        echo "<div style=\"margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;\">";
        echo "<button type=\"button\" class=\"button\" id=\"sole_engine_health_check\">Check Health</button>";
        echo "</div>";

        echo "<h3 style=\"margin-top:16px;\">Account</h3>";
        $diagnostics->renderAccountTable();
        echo "<div style=\"margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;\">";
        if ($userKey !== "") {
            echo "<button type=\"button\" class=\"button\" id=\"sole_engine_account_check\">Refresh Account Status</button>";
        }
        echo "<span id=\"sole_engine_account_result\"></span>";
        echo "</div>";

        $testInternal = new Routines_AdminTestInternal();
        $testExternal = new Routines_AdminTestExternal();
        echo "<p class=\"description\">Running tests may use your API quota.</p>";
        echo "<h3 style=\"margin-top:16px;\">Internal Tests</h3>";
        $testInternal->renderTestTable();
        echo "<h3 style=\"margin-top:16px;\">External Tests</h3>";
        $testExternal->renderTestTable();

        echo "<h3 style=\"margin-top:16px;\">Local diagnostics</h3>";
        $diagnostics->renderDiagnosticsTable();
        echo "<h3 style=\"margin-top:16px;\">Per-caller credit usage</h3>";
        $diagnostics->renderCallerCreditsTable();
        echo "<h3 style=\"margin-top:16px;\">Error log</h3>";
        $diagnostics->renderErrorLog();
        echo "<h3 style=\"margin-top:16px;\">Cache management</h3>";
        echo "<div>";
        echo "<button type=\"button\" class=\"button\" id=\"sole_engine_clear_cache\" style=\"color:#b32d2e;\">Clear All Cache</button>";
        echo "<span id=\"sole_engine_clear_cache_spinner\" style=\"display:none;margin-left:8px;\">Clearing...</span>";
        echo "<span id=\"sole_engine_clear_cache_result\" style=\"margin-left:8px;\"></span>";
        echo "<p class=\"description\">Deletes all cached job IDs, replies, and error entries. Use when cached errors are blocking retries.</p>";
        echo "</div>";
        echo "<h3 style=\"margin-top:16px;\">Purge site data</h3>";
        echo "<div>";
        echo "<button type=\"button\" class=\"button\" id=\"sole_engine_purge_site\" style=\"color:#b32d2e;\">Purge All Site Data</button>";
        echo "<span id=\"sole_engine_purge_spinner\" style=\"display:none;margin-left:8px;\">Purging...</span>";
        echo "<span id=\"sole_engine_purge_result\" style=\"margin-left:8px;\"></span>";
        echo "<p class=\"description\">Deletes all stored embeddings and job history for this site from the central API.</p>";
        echo "<p class=\"description\">Sole Engine features will be unavailable for at least 1 minute while the purge completes. Dependent plugins may show errors during this time.</p>";
        echo "<p class=\"description\"><strong>When to use:</strong> After changing your site URL, to clear corrupted data, to reindex content from scratch, or if you want to remove all stored data for privacy reasons.</p>";
        echo "</div>";

        $semanticEnabled = \get_option("sole_engine_semantic_enabled");
        $showContentIndex = !($semanticEnabled === "0");
        if ($showContentIndex) {
            $bulkIndexer = $this->makeBulkIndexer();
            $pendingCount = $bulkIndexer->countPendingPosts();
            $lastBatchRun = $bulkIndexer->getLastBatchRun();
            $cronStale = $bulkIndexer->isCronStale();
            $lastRunDisplay = $lastBatchRun !== null ? \gmdate("Y-m-d H:i:s", (int) $lastBatchRun) . " UTC" : "Never";

            $reindexPoller = $this->makeReindexPoller();
            $lastReindexPoll = $reindexPoller->getLastPoll();
            $lastReindexDisplay = $lastReindexPoll !== null ? \gmdate("Y-m-d H:i:s", (int) $lastReindexPoll) . " UTC" : "Never";

            $spacePoller = $this->makeSpacePoller();
            $spaceIdentity = $spacePoller->getSpaceIdentity();
            $spaceIdentityDisplay = $spaceIdentity !== null ? $spaceIdentity : "Not yet seeded";
            $lastSpacePoll = $spacePoller->getLastPoll();
            $lastSpaceDisplay = $lastSpacePoll !== null ? \gmdate("Y-m-d H:i:s", (int) $lastSpacePoll) . " UTC" : "Never";

            echo "<h3 style=\"margin-top:16px;\">Content Index</h3>";
            echo "<table class=\"widefat striped\" style=\"max-width:500px;\">";
            echo "<tr><td>Posts pending indexing</td><td id=\"sole_engine_index_pending\">" . \esc_html((string) $pendingCount) . "</td></tr>";
            echo "<tr><td>Last batch run</td><td id=\"sole_engine_index_last_run\">" . \esc_html($lastRunDisplay) . "</td></tr>";
            echo "<tr><td>Last reindex poll</td><td>" . \esc_html($lastReindexDisplay) . "</td></tr>";
            echo "<tr><td>Last space poll</td><td>" . \esc_html($lastSpaceDisplay) . "</td></tr>";
            echo "<tr><td>Embedding space</td><td><code style=\"word-break:break-all;\">" . \esc_html($spaceIdentityDisplay) . "</code></td></tr>";
            echo "</table>";
            if ($cronStale) {
                echo "<div class=\"notice notice-warning inline\" style=\"margin:8px 0;\"><p>The scheduled indexing job has not fired recently (WP-Cron needs site traffic to run). Use the button below to run it manually.</p></div>";
            }
            echo "<div style=\"margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;\">";
            echo "<button type=\"button\" class=\"button\" id=\"sole_engine_index_batch_btn\">Run scheduled job now</button>";
            echo "<button type=\"button\" class=\"button\" id=\"sole_engine_index_refresh_btn\">Refresh Status</button>";
            echo "<button type=\"button\" class=\"button\" id=\"sole_engine_reindex_all_btn\">Reindex everything</button>";
            echo "<span id=\"sole_engine_index_spinner\" style=\"display:none;margin-left:8px;\">Processing...</span>";
            echo "<span id=\"sole_engine_index_result\" style=\"margin-left:8px;\"></span>";
            echo "</div>";
            echo "<p class=\"description\">Indexes published posts that have not yet been processed. Runs automatically via WP-Cron when semantic features are enabled.</p>";
            echo "<p class=\"description\">\"Reindex everything\" re-embeds the entire site from scratch (every published post). The plugin does this automatically when the engine's embedding model changes; use the button to force it manually. It consumes engine quota proportional to your content.</p>";
        }

        echo "</div>";
        echo "<script>
          (function() {
            var ajaxUrl = '" . \esc_js(\admin_url("admin-ajax.php")) . "';
            var healthButton = document.getElementById('sole_engine_health_check');
            var accountButton = document.getElementById('sole_engine_account_check');
            var accountResult = document.getElementById('sole_engine_account_result');
            var summaryElement = document.getElementById('sole_engine_diagnostics_summary');
            var diagNonceEl = document.getElementById('sole_engine_diagnostics_nonce');
            var diagNonce = diagNonceEl ? diagNonceEl.value : '';

            var postAjax = function(action, onDone) {
              var xhr = new XMLHttpRequest();
              xhr.open('POST', ajaxUrl, true);
              xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
              xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) {
                  return;
                }
                if (xhr.status < 200 || xhr.status >= 300) {
                  onDone(false, null);
                  return;
                }
                try {
                  var parsed = JSON.parse(xhr.responseText);
                  onDone(true, parsed);
                } catch (e) {
                  onDone(false, null);
                }
              };
              xhr.send('action=' + encodeURIComponent(action) + '&_wpnonce=' + encodeURIComponent(diagNonce));
            };

            var readField = function(id, value) {
              var node = document.getElementById(id);
              if (node) {
                node.textContent = value;
              }
            };

            var applySummary = function(payload) {
              var value = payload && payload.data && typeof payload.data.summary === 'string'
                ? payload.data.summary
                : (summaryElement ? summaryElement.getAttribute('data-fallback-summary') : '');
              if (value) {
                readField('sole_engine_diagnostics_summary', value);
              }
            };

            var applyQuotaDisplay = function(data) {
              if (!data || typeof data.quota_display !== 'string' || typeof data.quota_percent_display !== 'string') {
                return;
              }
              readField('sole_engine_quota_used', data.quota_display);
              readField('sole_engine_quota_usage', data.quota_percent_display);
            };

            if (healthButton) {
              healthButton.addEventListener('click', function() {
                healthButton.disabled = true;
                postAjax('sole_engine_health_check', function(ok, payload) {
                  healthButton.disabled = false;
                  if (!ok || !payload || !payload.success) {
                    readField('sole_engine_health_status', 'error');
                    readField('sole_engine_health_checked_at', 'error');
                    applySummary(payload);
                    return;
                  }
                  readField('sole_engine_health_status', payload.data.status || 'unknown');
                  readField('sole_engine_health_checked_at', payload.data.timestamp || 'unknown');
                  applySummary(payload);
                });
              });
            }
            if (accountButton) {
              accountButton.addEventListener('click', function() {
                accountButton.disabled = true;
                postAjax('sole_engine_account_status', function(ok, payload) {
                  accountButton.disabled = false;
                  if (!ok || !payload || !payload.success) {
                    var errorCode = payload && payload.data ? payload.data.error : '';
                    if (errorCode === 'user_key_invalid') {
                      readField('sole_engine_account_status', 'unavailable — saved key invalid');
                      readField('sole_engine_account_checked_at', 'just now');
                      applyQuotaDisplay(payload.data);
                      if (accountResult) {
                        accountResult.textContent = 'Replace the saved user key with a valid key from your SOLE account, then verify it again.';
                      }
                    } else {
                      readField('sole_engine_account_status', 'error');
                      readField('sole_engine_account_checked_at', 'error');
                      if (accountResult) accountResult.textContent = errorCode || 'Account check failed.';
                    }
                    applySummary(payload);
                    return;
                  }
                  if (accountResult) accountResult.textContent = 'Saved key accepted by API.';
                  readField('sole_engine_account_status', payload.data.status || 'unknown');
                  readField('sole_engine_account_checked_at', payload.data.timestamp || 'unknown');
                  applyQuotaDisplay(payload.data);
                  applySummary(payload);
                });
              });
            }

            if (accountButton) {
              postAjax('sole_engine_account_status', function(ok, payload) {
                if (!ok || !payload || !payload.success) {
                  if (payload && payload.data && payload.data.error === 'user_key_invalid') {
                    readField('sole_engine_account_status', 'unavailable — saved key invalid');
                    readField('sole_engine_account_checked_at', 'just now');
                    applyQuotaDisplay(payload.data);
                  }
                  applySummary(payload);
                  return;
                }
                readField('sole_engine_account_status', payload.data.status || 'unknown');
                readField('sole_engine_account_checked_at', payload.data.timestamp || 'unknown');
                applyQuotaDisplay(payload.data);
                applySummary(payload);
              });
            }

            var runSingleTest = function(category, testId, callback) {
              var prefix = 'sole_engine_test_' + category + '_' + testId;
              var resultEl = document.getElementById(prefix + '_result');
              var detailEl = document.getElementById(prefix + '_detail');
              var btnEl = document.getElementById(prefix + '_run');
              if (resultEl) { resultEl.textContent = 'running...'; resultEl.style.color = ''; }
              if (detailEl) detailEl.textContent = '';
              if (btnEl) btnEl.disabled = true;
              postAjax('sole_engine_test_' + category + '_' + testId, function(ok, payload) {
                if (btnEl) btnEl.disabled = false;
                if (!ok || !payload) {
                  if (resultEl) { resultEl.textContent = 'fail'; resultEl.style.color = '#b32d2e'; }
                  if (detailEl) detailEl.textContent = 'Network error';
                  if (callback) callback();
                  return;
                }
                if (payload.success && payload.data) {
                  var r = payload.data.result || 'unknown';
                  if (resultEl) {
                    resultEl.textContent = r;
                    if (r === 'pass') resultEl.style.color = '#00a32a';
                    else if (r === 'fail') resultEl.style.color = '#b32d2e';
                    else resultEl.style.color = '#666';
                  }
                  if (detailEl) detailEl.textContent = payload.data.detail || '';
                } else {
                  if (resultEl) { resultEl.textContent = 'error'; resultEl.style.color = '#b32d2e'; }
                  if (detailEl) detailEl.textContent = payload.data && payload.data.error ? payload.data.error : 'Unexpected response';
                }
                if (callback) callback();
              });
            };

            var bindTestCategory = function(category) {
              var runAllBtn = document.getElementById('sole_engine_run_all_' + category);
              if (!runAllBtn) return;
              var ids = (runAllBtn.getAttribute('data-test-ids') || '').split(',');
              // Individual buttons and Run-All currently share the same ids.
              // Keep the fallback for older rendered markup that lacks the
              // data-all-ids attribute.
              var allIds = (runAllBtn.getAttribute('data-all-ids') || runAllBtn.getAttribute('data-test-ids') || '').split(',');
              var i;
              for (i = 0; i < allIds.length; i++) {
                (function(testId) {
                  var btn = document.getElementById('sole_engine_test_' + category + '_' + testId + '_run');
                  if (btn) {
                    btn.addEventListener('click', function() {
                      runSingleTest(category, testId, null);
                    });
                  }
                })(allIds[i]);
              }
              var originalLabel = runAllBtn.textContent;
              runAllBtn.addEventListener('click', function() {
                runAllBtn.disabled = true;
                runAllBtn.textContent = 'Running...';
                var idx = 0;
                var next = function() {
                  if (idx >= ids.length) {
                    runAllBtn.disabled = false;
                    runAllBtn.textContent = originalLabel;
                    return;
                  }
                  runSingleTest(category, ids[idx], function() {
                    idx++;
                    next();
                  });
                };
                next();
              });
            };

            bindTestCategory('internal');
            bindTestCategory('external');

            var toggle = document.getElementById('sole_engine_debug_mode');
            var endpointRow = document.getElementById('sole_engine_endpoint_row');
            if (toggle) {
              var syncDebugRows = function() {
                var show = toggle.checked;
                if (endpointRow) {
                  endpointRow.style.display = show ? '' : 'none';
                }
              };
              toggle.addEventListener('change', syncDebugRows);
              syncDebugRows();
            }

            var purgeButton = document.getElementById('sole_engine_purge_site');
            var purgeSpinner = document.getElementById('sole_engine_purge_spinner');
            var purgeResult = document.getElementById('sole_engine_purge_result');
            if (purgeButton) {
              purgeButton.addEventListener('click', function() {
                if (!confirm('This will delete all stored data for this site from the central API.\\n\\nSole Engine features will be unavailable for at least 1 minute.\\n\\nContinue?')) {
                  return;
                }
                purgeButton.disabled = true;
                if (purgeSpinner) {
                  purgeSpinner.style.display = 'inline';
                }
                if (purgeResult) {
                  purgeResult.textContent = '';
                }
                postAjax('sole_engine_purge_site', function(ok, payload) {
                  purgeButton.disabled = false;
                  if (purgeSpinner) {
                    purgeSpinner.style.display = 'none';
                  }
                  if (!ok || !payload) {
                    if (purgeResult) {
                      purgeResult.textContent = 'Error: network failure';
                      purgeResult.style.color = '#b32d2e';
                    }
                    return;
                  }
                  if (!payload.success) {
                    var errMsg = 'Error: ' + (payload.data && payload.data.error ? payload.data.error : 'unknown');
                    if (purgeResult) {
                      purgeResult.textContent = errMsg;
                      purgeResult.style.color = '#b32d2e';
                    }
                    return;
                  }
                  if (purgeResult) {
                    purgeResult.textContent = payload.data.message || 'Purge initiated.';
                    purgeResult.style.color = '#00a32a';
                  }
                });
              });
            }

            var clearCacheButton = document.getElementById('sole_engine_clear_cache');
            var clearCacheSpinner = document.getElementById('sole_engine_clear_cache_spinner');
            var clearCacheResult = document.getElementById('sole_engine_clear_cache_result');
            if (clearCacheButton) {
              clearCacheButton.addEventListener('click', function() {
                if (!confirm('This will delete all cached job IDs, replies, and error entries.\\n\\nContinue?')) {
                  return;
                }
                clearCacheButton.disabled = true;
                if (clearCacheSpinner) {
                  clearCacheSpinner.style.display = 'inline';
                }
                if (clearCacheResult) {
                  clearCacheResult.textContent = '';
                }
                postAjax('sole_engine_clear_cache', function(ok, payload) {
                  clearCacheButton.disabled = false;
                  if (clearCacheSpinner) {
                    clearCacheSpinner.style.display = 'none';
                  }
                  if (!ok || !payload) {
                    if (clearCacheResult) {
                      clearCacheResult.textContent = 'Error: network failure';
                      clearCacheResult.style.color = '#b32d2e';
                    }
                    return;
                  }
                  if (!payload.success) {
                    var errMsg = 'Error: ' + (payload.data && payload.data.error ? payload.data.error : 'unknown');
                    if (clearCacheResult) {
                      clearCacheResult.textContent = errMsg;
                      clearCacheResult.style.color = '#b32d2e';
                    }
                    return;
                  }
                  if (clearCacheResult) {
                    clearCacheResult.textContent = payload.data.message || 'Cache cleared.';
                    clearCacheResult.style.color = '#00a32a';
                  }
                });
              });
            }

            var indexBatchBtn = document.getElementById('sole_engine_index_batch_btn');
            var indexRefreshBtn = document.getElementById('sole_engine_index_refresh_btn');
            var reindexAllBtn = document.getElementById('sole_engine_reindex_all_btn');
            var indexSpinner = document.getElementById('sole_engine_index_spinner');
            var indexResultEl = document.getElementById('sole_engine_index_result');
            var indexPendingEl = document.getElementById('sole_engine_index_pending');
            var indexLastRunEl = document.getElementById('sole_engine_index_last_run');

            if (indexBatchBtn) {
              indexBatchBtn.addEventListener('click', function() {
                indexBatchBtn.disabled = true;
                if (indexSpinner) indexSpinner.style.display = 'inline';
                if (indexResultEl) indexResultEl.textContent = '';
                postAjax('sole_engine_bulk_index_trigger', function(ok, payload) {
                  indexBatchBtn.disabled = false;
                  if (indexSpinner) indexSpinner.style.display = 'none';
                  if (!ok || !payload || !payload.success) {
                    if (indexResultEl) {
                      indexResultEl.textContent = 'Error running batch.';
                      indexResultEl.style.color = '#b32d2e';
                    }
                    return;
                  }
                  var d = payload.data;
                  if (indexResultEl) {
                    indexResultEl.textContent = 'Processed: ' + d.processed + ' | Remaining: ' + d.pending;
                    indexResultEl.style.color = '#00a32a';
                  }
                  if (indexPendingEl) indexPendingEl.textContent = d.pending;
                });
              });
            }

            if (indexRefreshBtn) {
              indexRefreshBtn.addEventListener('click', function() {
                indexRefreshBtn.disabled = true;
                if (indexResultEl) indexResultEl.textContent = '';
                postAjax('sole_engine_index_status', function(ok, payload) {
                  indexRefreshBtn.disabled = false;
                  if (!ok || !payload || !payload.success) {
                    if (indexResultEl) {
                      indexResultEl.textContent = 'Error fetching status.';
                      indexResultEl.style.color = '#b32d2e';
                    }
                    return;
                  }
                  var d = payload.data;
                  if (indexPendingEl) indexPendingEl.textContent = d.pending;
                  if (indexLastRunEl) {
                    if (d.last_batch_run) {
                      var dt = new Date(parseInt(d.last_batch_run, 10) * 1000);
                      indexLastRunEl.textContent = dt.toISOString().replace('T', ' ').replace(/\\.\\d+Z$/, ' UTC');
                    } else {
                      indexLastRunEl.textContent = 'Never';
                    }
                  }
                  if (indexResultEl) {
                    indexResultEl.textContent = 'Status refreshed.';
                    indexResultEl.style.color = '#00a32a';
                  }
                });
              });
            }

            if (reindexAllBtn) {
              reindexAllBtn.addEventListener('click', function() {
                if (!window.confirm('Re-embed the entire site from scratch? This re-indexes every published post and consumes engine quota proportional to your content.')) {
                  return;
                }
                reindexAllBtn.disabled = true;
                if (indexSpinner) indexSpinner.style.display = 'inline';
                if (indexResultEl) indexResultEl.textContent = '';
                postAjax('sole_engine_reindex_all_trigger', function(ok, payload) {
                  reindexAllBtn.disabled = false;
                  if (indexSpinner) indexSpinner.style.display = 'none';
                  if (!ok || !payload || !payload.success) {
                    if (indexResultEl) {
                      indexResultEl.textContent = 'Error starting full reindex.';
                      indexResultEl.style.color = '#b32d2e';
                    }
                    return;
                  }
                  var d = payload.data;
                  if (indexResultEl) {
                    indexResultEl.textContent = 'Full reindex queued — ' + d.pending + ' posts pending.';
                    indexResultEl.style.color = '#00a32a';
                  }
                  if (indexPendingEl) indexPendingEl.textContent = d.pending;
                });
              });
            }

            var form = document.querySelector('form[action=\"options.php\"]');
            var saveButton = document.getElementById('sole_engine_save_button');
            var saveNote = document.getElementById('sole_engine_save_note');
            if (!form || !saveButton || !saveNote) {
              return;
            }

            var serialize = function() {
              var items = [];
              var elements = form.elements;
              var i;
              for (i = 0; i < elements.length; i += 1) {
                var el = elements[i];
                if (!el || !el.name) {
                  continue;
                }
                if (el.type === 'checkbox') {
                  items.push(el.name + '=' + (el.checked ? '1' : '0'));
                  continue;
                }
                if (el.type === 'radio') {
                  if (el.checked) {
                    items.push(el.name + '=' + el.value);
                  }
                  continue;
                }
                items.push(el.name + '=' + el.value);
              }
              items.sort();
              return items.join('&');
            };

            var baseline = serialize();
            var updateSaveState = function() {
              var current = serialize();
              if (current === baseline) {
                saveButton.disabled = true;
                saveNote.style.display = 'none';
              } else {
                saveButton.disabled = false;
                saveNote.style.display = 'block';
              }
            };

            updateSaveState();
            form.addEventListener('input', updateSaveState);
            form.addEventListener('change', updateSaveState);

            var consoleNonceEl = document.getElementById('sole_engine_console_nonce');
            var consoleNonce = consoleNonceEl ? consoleNonceEl.value : '';

            var postConsole = function(action, data, onDone) {
              var xhr = new XMLHttpRequest();
              xhr.open('POST', ajaxUrl, true);
              xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
              xhr.onreadystatechange = function() {
                if (xhr.readyState !== 4) return;
                if (xhr.status < 200 || xhr.status >= 300) {
                  onDone(false, null);
                  return;
                }
                try {
                  onDone(true, JSON.parse(xhr.responseText));
                } catch (e) {
                  onDone(false, null);
                }
              };
              var parts = ['action=' + encodeURIComponent(action), '_wpnonce=' + encodeURIComponent(consoleNonce)];
              for (var key in data) {
                if (data.hasOwnProperty(key)) {
                  parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
                }
              }
              xhr.send(parts.join('&'));
            };

            var appendOutput = function(el, line) {
              if (el.value !== '') el.value += '\\n';
              el.value += line;
              el.scrollTop = el.scrollHeight;
            };

            // EMITTED FROM Engine_ErrorTtlResolver, NOT TRANSCRIBED FROM IT.
            // This was a hand-written copy of the resolver's table carrying a
            // comment asking a human to keep it in sync. It was one of FOUR
            // hand-maintained copies, and a ratified TTL change had to be
            // applied to each by hand -- which is how the fourth was found, by
            // the suite going red in a place nobody had listed. The values now
            // come from the resolver, so a rule and its display cannot differ.
            var errorTtl = " . \wp_json_encode($this->buildErrorTtlMap()) . ";

            var errorDesc = {
              config_missing: 'Plugin not configured. Set API endpoint and user key in settings above.',
              user_key_invalid: 'User key rejected by API. Verify it matches your SOLE account.',
              account_disabled: 'Account disabled. Contact SOLE support.',
              quota_exceeded: 'Quota limit reached. Wait for reset or upgrade plan.',
              rate_limited: 'Too many requests. Wait a few seconds.',
              invalid_payload: 'Payload malformed or invalid. Check fields and try again.',
              task_unsupported: 'Task name not recognized by the API.',
              job_not_found: 'Job ID expired or does not exist. Submit a new request.',
              provider_error: 'AI provider failed to process the job. Usually transient — retry shortly.',
              engine_internal: 'Unexpected engine error. Usually transient — retry shortly.',
              api_response_invalid: 'API returned unparseable response. Check network/proxy.',
              network_error: 'Network failure. Check connectivity and endpoint URL.',
              site_purging: 'Site data is being purged. Wait for completion.',
              caller_blocked: 'Caller plugin is on the blocklist. Check Caller Blocklist in settings above.'
            };

            var errorHint = function(code) {
              var ttl = errorTtl[code];
              if (ttl === undefined) ttl = 180;
              var desc = errorDesc[code] || 'Unknown error.';
              var cache = ttl === 0
                ? 'Not cached — retry anytime.'
                : 'Cached for ' + ttl + 's — retry after that.';
              return desc + ' ' + cache;
            };

            var llmPresets = {
              chat: {
                greeting: { label: 'Greeting', payload: 'Hello, how are you?' },
                explain_wp: { label: 'Explain WP', payload: 'Explain what WordPress is in two sentences.' }
              },
              translate: {
                en_fr: { label: 'English to French', payload: 'The weather is nice today', targetLanguage: 'french' },
                auto_en: { label: 'Auto to English', payload: 'Hola, buenos días', targetLanguage: 'english' }
              },
              summary: {
                short_article: {
                  label: 'Short article',
                  payload: 'WordPress is a free and open-source content management system written in PHP and paired with a MySQL or MariaDB database. Features include a plugin architecture and a template system, referred to within WordPress as Themes. WordPress was originally created as a blog-publishing system but has evolved to support other web content types including more traditional mailing lists and forums, media galleries, membership sites, learning management systems, and online stores.'
                }
              }
            };

            var semanticPresets = {
              relevance: {
                similar: { label: 'Similar', textA: 'The weather is sunny and warm today', textB: 'It is a bright and hot day outside' },
                different: { label: 'Different', textA: 'The weather is sunny today', textB: 'I enjoy eating pizza for dinner' }
              },
              search: {
                basic: { label: 'Basic search', query: 'How does WordPress work?' }
              }
            };

            var semanticTaskFields = {
              relevance: ['text_a', 'text_b'],
              search: ['query', 'top_k', 'post_types']
            };
            var allSemanticFields = ['text_a', 'text_b', 'query', 'top_k', 'post_types'];

            var llmTaskFields = {
              chat: [],
              translate: ['targetLanguage', 'sourceLanguage', 'extra'],
              summary: ['size', 'extra']
            };
            var allLlmOptionFields = ['targetLanguage', 'sourceLanguage', 'size', 'extra'];

            var taskSelect = document.getElementById('sole_engine_console_llm_task');
            var presetSelect = document.getElementById('sole_engine_console_llm_preset');

            var syncTaskFields = function() {
              if (!taskSelect) return;
              var task = taskSelect.value;
              var fields = llmTaskFields[task] || [];
              var i;
              for (i = 0; i < allLlmOptionFields.length; i++) {
                var row = document.getElementById('sole_engine_console_llm_row_' + allLlmOptionFields[i]);
                if (row) {
                  row.style.display = fields.indexOf(allLlmOptionFields[i]) !== -1 ? '' : 'none';
                }
              }
              if (presetSelect) {
                presetSelect.innerHTML = '<option value=\"\">-- select preset --</option>';
                var presets = llmPresets[task] || {};
                for (var key in presets) {
                  if (presets.hasOwnProperty(key)) {
                    var opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = presets[key].label;
                    presetSelect.appendChild(opt);
                  }
                }
              }
            };

            if (taskSelect) {
              taskSelect.addEventListener('change', function() {
                syncTaskFields();
                var payloadEl = document.getElementById('sole_engine_console_llm_payload');
                if (payloadEl) payloadEl.value = '';
                for (var i = 0; i < allLlmOptionFields.length; i++) {
                  var el = document.getElementById('sole_engine_console_llm_' + allLlmOptionFields[i]);
                  if (el) el.value = '';
                }
                if (presetSelect) presetSelect.value = '';
              });
              syncTaskFields();
            }

            if (presetSelect) {
              presetSelect.addEventListener('change', function() {
                if (!taskSelect) return;
                var task = taskSelect.value;
                var key = presetSelect.value;
                if (key === '' || !llmPresets[task] || !llmPresets[task][key]) return;
                var preset = llmPresets[task][key];
                var payloadEl = document.getElementById('sole_engine_console_llm_payload');
                if (payloadEl) payloadEl.value = preset.payload || '';
                for (var i = 0; i < allLlmOptionFields.length; i++) {
                  var el = document.getElementById('sole_engine_console_llm_' + allLlmOptionFields[i]);
                  if (el) el.value = preset[allLlmOptionFields[i]] || '';
                }
              });
            }

            var llmSendBtn = document.getElementById('sole_engine_console_llm_send');
            var llmOutput = document.getElementById('sole_engine_console_llm_output');

            if (llmSendBtn && llmOutput) {
              llmSendBtn.addEventListener('click', function() {
                var task = taskSelect ? taskSelect.value : 'chat';
                var payloadEl = document.getElementById('sole_engine_console_llm_payload');
                var payload = payloadEl ? payloadEl.value : '';
                if (payload.trim() === '') return;

                llmSendBtn.disabled = true;
                llmOutput.value = '';

                var options = {};
                var fields = llmTaskFields[task] || [];
                for (var i = 0; i < fields.length; i++) {
                  var el = document.getElementById('sole_engine_console_llm_' + fields[i]);
                  if (el && el.value.trim() !== '') {
                    options[fields[i]] = el.value.trim();
                  }
                }
                var optionsStr = Object.keys(options).length > 0 ? JSON.stringify(options) : '';

                var summary = payload.length > 60 ? payload.substring(0, 60) + '...' : payload;
                appendOutput(llmOutput, '[Submit] Task: ' + task + ' | Payload: \"' + summary + '\"');

                postConsole('sole_engine_console_llm_submit', {
                  task: task,
                  payload: payload,
                  options: optionsStr
                }, function(ok, resp) {
                  if (!ok || !resp || !resp.success) {
                    var errMsg = resp && resp.data && resp.data.error ? resp.data.error : 'network_error';
                    appendOutput(llmOutput, '[Ticket] Rejected | Error: ' + errMsg);
                    llmSendBtn.disabled = false;
                    return;
                  }
                  var d = resp.data;
                  if (!d.was_accepted) {
                    appendOutput(llmOutput, '[Ticket] Rejected | Error: ' + (d.error || 'unknown'));
                    appendOutput(llmOutput, '[Info] ' + errorHint(d.error || ''));
                    llmSendBtn.disabled = false;
                    return;
                  }
                  appendOutput(llmOutput, '[Ticket] Accepted | Job ID: ' + d.job_id);

                  var jobId = d.job_id;
                  var pollCount = 0;
                  var maxPolls = 15;
                  var pollInterval = 2000;

                  var poll = function() {
                    pollCount++;
                    postConsole('sole_engine_console_llm_poll', { job_id: jobId }, function(ok2, resp2) {
                      if (!ok2 || !resp2 || !resp2.success) {
                        appendOutput(llmOutput, '[Poll ' + pollCount + '] Error: network failure');
                        llmSendBtn.disabled = false;
                        return;
                      }
                      var r = resp2.data;
                      if (r.is_pending) {
                        appendOutput(llmOutput, '[Poll ' + pollCount + '] Pending...');
                        if (pollCount < maxPolls) {
                          setTimeout(poll, pollInterval);
                        } else {
                          appendOutput(llmOutput, '[Poll ' + pollCount + '] Gave up after ' + maxPolls + ' attempts.');
                          llmSendBtn.disabled = false;
                        }
                        return;
                      }
                      if (r.is_successful) {
                        appendOutput(llmOutput, '[Poll ' + pollCount + '] Success');
                        appendOutput(llmOutput, '[Reply] ' + (r.reply || ''));
                      } else {
                        appendOutput(llmOutput, '[Poll ' + pollCount + '] Failed | Error: ' + (r.error || 'unknown'));
                        appendOutput(llmOutput, '[Info] ' + errorHint(r.error || ''));
                      }
                      llmSendBtn.disabled = false;
                    });
                  };

                  setTimeout(poll, pollInterval);
                });
              });
            }

            var semTaskSelect = document.getElementById('sole_engine_console_semantic_task');
            var semPresetSelect = document.getElementById('sole_engine_console_semantic_preset');
            var semSendBtn = document.getElementById('sole_engine_console_semantic_send');
            var semOutput = document.getElementById('sole_engine_console_semantic_output');

            var syncSemanticFields = function() {
              if (!semTaskSelect) return;
              var task = semTaskSelect.value;
              var fields = semanticTaskFields[task] || [];
              var i;
              for (i = 0; i < allSemanticFields.length; i++) {
                var row = document.getElementById('sole_engine_console_semantic_row_' + allSemanticFields[i]);
                if (row) {
                  row.style.display = fields.indexOf(allSemanticFields[i]) !== -1 ? '' : 'none';
                }
              }
              if (semPresetSelect) {
                semPresetSelect.innerHTML = '<option value=\"\">-- select preset --</option>';
                var presets = semanticPresets[task] || {};
                for (var key in presets) {
                  if (presets.hasOwnProperty(key)) {
                    var opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = presets[key].label;
                    semPresetSelect.appendChild(opt);
                  }
                }
              }
            };

            if (semTaskSelect) {
              semTaskSelect.addEventListener('change', function() {
                syncSemanticFields();
                for (var i = 0; i < allSemanticFields.length; i++) {
                  var el = document.getElementById('sole_engine_console_semantic_' + allSemanticFields[i]);
                  if (el) el.value = '';
                }
                if (semPresetSelect) semPresetSelect.value = '';
              });
              syncSemanticFields();
            }

            if (semPresetSelect) {
              semPresetSelect.addEventListener('change', function() {
                if (!semTaskSelect) return;
                var task = semTaskSelect.value;
                var key = semPresetSelect.value;
                if (key === '' || !semanticPresets[task] || !semanticPresets[task][key]) return;
                var preset = semanticPresets[task][key];
                var textAEl = document.getElementById('sole_engine_console_semantic_text_a');
                var textBEl = document.getElementById('sole_engine_console_semantic_text_b');
                var queryEl = document.getElementById('sole_engine_console_semantic_query');
                if (textAEl) textAEl.value = preset.textA || '';
                if (textBEl) textBEl.value = preset.textB || '';
                if (queryEl) queryEl.value = preset.query || '';
              });
            }

            if (semSendBtn && semOutput) {
              semSendBtn.addEventListener('click', function() {
                var task = semTaskSelect ? semTaskSelect.value : 'relevance';
                var payload = '';
                var options = {};

                if (task === 'relevance') {
                  var textAEl = document.getElementById('sole_engine_console_semantic_text_a');
                  var textBEl = document.getElementById('sole_engine_console_semantic_text_b');
                  var textA = textAEl ? textAEl.value : '';
                  var textB = textBEl ? textBEl.value : '';
                  if (textA.trim() === '' || textB.trim() === '') return;
                  payload = JSON.stringify({ a: textA, b: textB });
                } else if (task === 'search') {
                  var queryEl = document.getElementById('sole_engine_console_semantic_query');
                  var query = queryEl ? queryEl.value : '';
                  if (query.trim() === '') return;
                  // The `search` payload carries { query, top_k };
                  // only `post_types` belongs in options.
                  var payloadObj = { query: query };
                  var topKEl = document.getElementById('sole_engine_console_semantic_top_k');
                  var postTypesEl = document.getElementById('sole_engine_console_semantic_post_types');
                  if (topKEl && topKEl.value.trim() !== '') {
                    payloadObj.top_k = parseInt(topKEl.value, 10);
                  }
                  payload = JSON.stringify(payloadObj);
                  if (postTypesEl && postTypesEl.value.trim() !== '') {
                    options.post_types = postTypesEl.value.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s !== ''; });
                  }
                }

                var optionsStr = Object.keys(options).length > 0 ? JSON.stringify(options) : '';

                semSendBtn.disabled = true;
                semOutput.value = '';

                var summary = payload.length > 60 ? payload.substring(0, 60) + '...' : payload;
                appendOutput(semOutput, '[Submit] Task: ' + task + ' | Payload: \"' + summary + '\"');

                postConsole('sole_engine_console_semantic_submit', {
                  task: task,
                  payload: payload,
                  options: optionsStr
                }, function(ok, resp) {
                  if (!ok || !resp || !resp.success) {
                    var errMsg = resp && resp.data && resp.data.error ? resp.data.error : 'network_error';
                    appendOutput(semOutput, '[Ticket] Rejected | Error: ' + errMsg);
                    semSendBtn.disabled = false;
                    return;
                  }
                  var d = resp.data;
                  if (!d.was_accepted) {
                    appendOutput(semOutput, '[Ticket] Rejected | Error: ' + (d.error || 'unknown'));
                    appendOutput(semOutput, '[Info] ' + errorHint(d.error || ''));
                    semSendBtn.disabled = false;
                    return;
                  }
                  appendOutput(semOutput, '[Ticket] Accepted | Job ID: ' + d.job_id);

                  var jobId = d.job_id;
                  var pollCount = 0;
                  var maxPolls = 15;
                  var pollInterval = 2000;

                  var poll = function() {
                    pollCount++;
                    postConsole('sole_engine_console_semantic_poll', { job_id: jobId }, function(ok2, resp2) {
                      if (!ok2 || !resp2 || !resp2.success) {
                        appendOutput(semOutput, '[Poll ' + pollCount + '] Error: network failure');
                        semSendBtn.disabled = false;
                        return;
                      }
                      var r = resp2.data;
                      if (r.is_pending) {
                        appendOutput(semOutput, '[Poll ' + pollCount + '] Pending...');
                        if (pollCount < maxPolls) {
                          setTimeout(poll, pollInterval);
                        } else {
                          appendOutput(semOutput, '[Poll ' + pollCount + '] Gave up after ' + maxPolls + ' attempts.');
                          semSendBtn.disabled = false;
                        }
                        return;
                      }
                      if (r.is_successful) {
                        appendOutput(semOutput, '[Poll ' + pollCount + '] Success');
                        appendOutput(semOutput, '[Reply] ' + (r.reply || ''));
                      } else {
                        appendOutput(semOutput, '[Poll ' + pollCount + '] Failed | Error: ' + (r.error || 'unknown'));
                        appendOutput(semOutput, '[Info] ' + errorHint(r.error || ''));
                      }
                      semSendBtn.disabled = false;
                    });
                  };

                  setTimeout(poll, pollInterval);
                });
              });
            }

          })();
        </script>";
        echo "</div>";
    }

    private function makeBulkIndexer(): Indexing_BulkIndexer {
        $configFactory = new Config_Factory();
        $configStore = $configFactory->makeStore();
        $httpFactory = new Http_Factory();
        $processor = new Indexing_ContentProcessor();
        $diagnosticsFactory = new Diagnostics_Factory();
        $diagnostics = $diagnosticsFactory->makeStore();
        $client = new Indexing_EmbeddingsClient($configStore, $httpFactory->makeClient(30), $diagnostics);
        return new Indexing_BulkIndexer($processor, $client, $configStore, $diagnostics);
    }

    private function makeReindexPoller(): Indexing_ReindexPoller {
        $configFactory = new Config_Factory();
        $configStore = $configFactory->makeStore();
        $httpFactory = new Http_Factory();
        $processor = new Indexing_ContentProcessor();
        $diagnosticsFactory = new Diagnostics_Factory();
        $diagnostics = $diagnosticsFactory->makeStore();
        $client = new Indexing_EmbeddingsClient($configStore, $httpFactory->makeClient(30), $diagnostics);
        return new Indexing_ReindexPoller($processor, $client, $configStore, $diagnostics);
    }

    private function makeSpacePoller(): Indexing_SpacePoller {
        $configFactory = new Config_Factory();
        $configStore = $configFactory->makeStore();
        $httpFactory = new Http_Factory();
        $processor = new Indexing_ContentProcessor();
        $diagnosticsFactory = new Diagnostics_Factory();
        $diagnostics = $diagnosticsFactory->makeStore();
        $client = new Indexing_EmbeddingsClient($configStore, $httpFactory->makeClient(30), $diagnostics);
        $bulkIndexer = new Indexing_BulkIndexer($processor, $client, $configStore, $diagnostics);
        return new Indexing_SpacePoller($client, $bulkIndexer, $configStore, $diagnostics);
    }
}

/**
 * External self-tests: verify the public contract as consumer plugins would use it.
 * Only calls sole_engine_llm(), sole_engine_semantic(), and interfaces from landmarks.md.
 */
final class Routines_AdminTestExternal implements Routines_RoutineInterface {
    private const MAX_POLL_ATTEMPTS = 5;
    private const POLL_INTERVAL_SECONDS = 2;
    private const TESTS = [
        "llm_access" => "LLM access point returns valid instance",
        "llm_full_flow" => "LLM submit + poll returns text reply",
        "semantic_access" => "Semantic access point returns valid instance; disabled stub rejects with semantic_disabled",
        "semantic_full_flow" => "Semantic submit + poll returns JSON relevance reply",
        "llm_dedup" => "Identical LLM submits return same cached job ID",
        "llm_bad_job" => "Polling non-existent job returns error, not success",
        "ticket_shape" => "Job ticket fields match contract (wasAccepted, getJobId, getError)",
        "semantic_search_flow" => "Semantic search submit + poll returns JSON reply with hits array",
        "caller_blocklist" => "Blocked caller is rejected with caller_blocked error",
        "caller_credits" => "Successful submit records per-caller credits",
    ];

    public function execute(): void {
        foreach (self::TESTS as $id => $description) {
            \add_action("wp_ajax_sole_engine_test_external_" . $id, function () use ($id): void {
                if (!\current_user_can("manage_options")) {
                    \wp_send_json_error(["error" => "forbidden"], 403);
                    return;
                }
                \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
                $method = "run_" . $id;
                $this->$method();
            });
        }
    }

    public function renderTestTable(): void {
        echo "<table class=\"widefat striped\">";
        echo "<thead><tr><th>Test</th><th>Description</th><th style=\"width:70px;\">Result</th><th>Detail</th><th style=\"width:50px;\"></th></tr></thead>";
        echo "<tbody>";
        foreach (self::TESTS as $id => $description) {
            $prefix = "sole_engine_test_external_" . $id;
            echo "<tr id=\"" . \esc_attr($prefix) . "_row\">";
            echo "<td>" . \esc_html($id) . "</td>";
            echo "<td>" . \esc_html($description) . "</td>";
            echo "<td id=\"" . \esc_attr($prefix) . "_result\">-</td>";
            echo "<td id=\"" . \esc_attr($prefix) . "_detail\" style=\"white-space:pre-wrap;\">-</td>";
            echo "<td><button type=\"button\" class=\"button\" id=\"" . \esc_attr($prefix) . "_run\">Run</button></td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";
        echo "<div style=\"margin-top:8px;\">";
        echo "<button type=\"button\" class=\"button\" id=\"sole_engine_run_all_external\" data-category=\"external\" data-test-ids=\"" . \esc_attr(\implode(",", \array_keys(self::TESTS))) . "\">Run All External</button>";
        echo "</div>";
    }

    private function run_llm_access(): void {
        $llm = sole_engine_llm();
        if ($llm instanceof \SoleEngineLLMInterface) {
            \wp_send_json_success(["result" => "pass", "detail" => "sole_engine_llm() returns a valid SoleEngineLLMInterface instance."]);
        } else {
            \wp_send_json_success(["result" => "fail", "detail" => "sole_engine_llm() did not return a SoleEngineLLMInterface."]);
        }
    }

    private function run_llm_full_flow(): void {
        $llm = sole_engine_llm();
        $ticket = $llm->submit("sole-engine-self-test", "chat", "Self-test from Sole Engine WP at " . \gmdate("c") . ". Reply with OK.");
        if (!$ticket->wasAccepted()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Submit rejected: " . ($ticket->getError() ?? "unknown")]);
            return;
        }
        $jobId = $ticket->getJobId();
        if ($jobId === null) {
            \wp_send_json_success(["result" => "fail", "detail" => "Submit accepted but getJobId() returned null."]);
            return;
        }
        $result = $this->pollLlm($llm, $jobId);
        if ($result === null) {
            \wp_send_json_success(["result" => "fail", "detail" => "getResult() returned null after polling."]);
            return;
        }
        if ($result->isPending()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Still pending after " . self::MAX_POLL_ATTEMPTS . " poll attempts."]);
            return;
        }
        if ($result->isSuccessful()) {
            $reply = $result->getReply();
            if (!\is_string($reply) || $reply === "") {
                \wp_send_json_success(["result" => "fail", "detail" => "isSuccessful() is true but getReply() returned empty or non-string."]);
                return;
            }
            \wp_send_json_success(["result" => "pass", "detail" => "Job " . $jobId . " completed. Reply: " . $reply]);
            return;
        }
        \wp_send_json_success(["result" => "fail", "detail" => "Job failed with error: " . ($result->getError() ?? "unknown")]);
    }

    private function run_semantic_access(): void {
        $semantic = sole_engine_semantic();
        if (!($semantic instanceof \SoleEngineSemanticInterface)) {
            \wp_send_json_success(["result" => "fail", "detail" => "sole_engine_semantic() returned an unexpected type."]);
            return;
        }
        if (!sole_engine_semantic_enabled()) {
            $ticket = $semantic->submit("sole-engine-self-test", "relevance", "{}");
            if (!$ticket->wasAccepted() && $ticket->getError() === "semantic_disabled") {
                \wp_send_json_success(["result" => "pass", "detail" => "sole_engine_semantic() returns valid instance; disabled stub correctly rejects with semantic_disabled."]);
                return;
            }
            \wp_send_json_success(["result" => "fail", "detail" => "Semantic disabled but stub did not return semantic_disabled error."]);
            return;
        }
        \wp_send_json_success(["result" => "pass", "detail" => "sole_engine_semantic() returns a valid SoleEngineSemanticInterface instance."]);
    }

    private function run_semantic_full_flow(): void {
        if (!sole_engine_semantic_enabled()) {
            \wp_send_json_success(["result" => "skip", "detail" => "Semantic features are disabled. Enable in Advanced settings to run this test."]);
            return;
        }
        $semantic = sole_engine_semantic();
        $timestamp = \gmdate("c");
        $textA = "The weather is sunny and warm today. " . $timestamp;
        $textB = "It is a bright and hot day outside. " . $timestamp;
        $payload = \json_encode(["a" => $textA, "b" => $textB]);
        $ticket = $semantic->submit("sole-engine-self-test", "relevance", $payload);
        if (!$ticket->wasAccepted()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Submit rejected: " . ($ticket->getError() ?? "unknown")]);
            return;
        }
        $jobId = $ticket->getJobId();
        if ($jobId === null) {
            \wp_send_json_success(["result" => "fail", "detail" => "Submit accepted but getJobId() returned null."]);
            return;
        }
        $result = $this->pollSemantic($semantic, $jobId);
        if ($result === null) {
            \wp_send_json_success(["result" => "fail", "detail" => "getResult() returned null after polling."]);
            return;
        }
        if ($result->isPending()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Still pending after " . self::MAX_POLL_ATTEMPTS . " poll attempts."]);
            return;
        }
        if ($result->isSuccessful()) {
            $replyStr = $result->getReply();
            if (!\is_string($replyStr) || $replyStr === "") {
                \wp_send_json_success(["result" => "fail", "detail" => "isSuccessful() is true but getReply() returned empty or non-string."]);
                return;
            }
            $decoded = \json_decode($replyStr, true);
            if (!\is_array($decoded) || !isset($decoded["value"])) {
                \wp_send_json_success(["result" => "fail", "detail" => "getReply() returned string but JSON decode failed or missing 'value' key. Raw: " . $replyStr]);
                return;
            }
            \wp_send_json_success(["result" => "pass", "detail" => "Job " . $jobId . " completed. Relevance: " . $decoded["value"]]);
            return;
        }
        \wp_send_json_success(["result" => "fail", "detail" => "Job failed with error: " . ($result->getError() ?? "unknown")]);
    }

    private function run_llm_dedup(): void {
        $llm = sole_engine_llm();
        $payload = "Dedup self-test from Sole Engine WP.";
        $ticket1 = $llm->submit("sole-engine-self-test", "chat", $payload);
        if (!$ticket1->wasAccepted()) {
            \wp_send_json_success(["result" => "fail", "detail" => "First submit rejected: " . ($ticket1->getError() ?? "unknown")]);
            return;
        }
        $jobId1 = $ticket1->getJobId();
        $ticket2 = $llm->submit("sole-engine-self-test", "chat", $payload);
        if (!$ticket2->wasAccepted()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Second submit rejected: " . ($ticket2->getError() ?? "unknown")]);
            return;
        }
        $jobId2 = $ticket2->getJobId();
        if ($jobId1 === $jobId2 && $jobId1 !== null) {
            \wp_send_json_success(["result" => "pass", "detail" => "Both submits returned same job ID (cache dedup working): " . $jobId1]);
        } else {
            \wp_send_json_success(["result" => "fail", "detail" => "Different job IDs returned: " . ($jobId1 ?? "null") . " vs " . ($jobId2 ?? "null")]);
        }
    }

    private function run_llm_bad_job(): void {
        $llm = sole_engine_llm();
        $result = $llm->getResult("nonexistent_job_" . \time());
        if ($result->isSuccessful()) {
            \wp_send_json_success(["result" => "fail", "detail" => "getResult() returned successful for a non-existent job ID."]);
            return;
        }
        if ($result->isPending()) {
            \wp_send_json_success(["result" => "fail", "detail" => "getResult() returned pending for a non-existent job ID."]);
            return;
        }
        $error = $result->getError();
        if (!\is_string($error) || $error === "") {
            \wp_send_json_success(["result" => "fail", "detail" => "getError() should return a non-empty string for a failed result."]);
            return;
        }
        \wp_send_json_success(["result" => "pass", "detail" => "Non-existent job correctly returns failure with error: " . $error]);
    }

    private function run_ticket_shape(): void {
        $llm = sole_engine_llm();
        $ticket = $llm->submit("sole-engine-self-test", "chat", "Shape test from Sole Engine WP at " . \gmdate("c") . ".");
        $checks = [];
        if (!\is_bool($ticket->wasAccepted())) {
            $checks[] = "wasAccepted() must return bool";
        }
        if ($ticket->wasAccepted()) {
            if (!\is_string($ticket->getJobId()) || $ticket->getJobId() === "") {
                $checks[] = "accepted ticket: getJobId() must return non-empty string";
            }
            if ($ticket->getError() !== null) {
                $checks[] = "accepted ticket: getError() must return null";
            }
        } else {
            if ($ticket->getJobId() !== null) {
                $checks[] = "rejected ticket: getJobId() must return null";
            }
            if (!\is_string($ticket->getError()) || $ticket->getError() === "") {
                $checks[] = "rejected ticket: getError() must return non-empty string";
            }
        }
        if (\count($checks) === 0) {
            $status = $ticket->wasAccepted() ? "accepted, jobId=" . $ticket->getJobId() : "rejected, error=" . $ticket->getError();
            \wp_send_json_success(["result" => "pass", "detail" => "Ticket shape correct. Status: " . $status]);
        } else {
            \wp_send_json_success(["result" => "fail", "detail" => \implode("; ", $checks)]);
        }
    }

    private function run_semantic_search_flow(): void {
        if (!sole_engine_semantic_enabled()) {
            \wp_send_json_success(["result" => "skip", "detail" => "Semantic features are disabled. Enable in Advanced settings to run this test."]);
            return;
        }
        $semantic = sole_engine_semantic();
        $payload = \json_encode(["query" => "test search from Sole Engine self-test"]);
        $ticket = $semantic->submit("sole-engine-self-test", "search", $payload);
        if (!$ticket->wasAccepted()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Search submit rejected: " . ($ticket->getError() ?? "unknown")]);
            return;
        }
        $jobId = $ticket->getJobId();
        if ($jobId === null) {
            \wp_send_json_success(["result" => "fail", "detail" => "Search submit accepted but getJobId() returned null."]);
            return;
        }
        $result = $this->pollSemantic($semantic, $jobId);
        if ($result === null) {
            \wp_send_json_success(["result" => "fail", "detail" => "getResult() returned null after polling."]);
            return;
        }
        if ($result->isPending()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Still pending after " . self::MAX_POLL_ATTEMPTS . " poll attempts."]);
            return;
        }
        if (!$result->isSuccessful()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Search job failed with error: " . ($result->getError() ?? "unknown")]);
            return;
        }
        $replyStr = $result->getReply();
        if (!\is_string($replyStr) || $replyStr === "") {
            \wp_send_json_success(["result" => "fail", "detail" => "isSuccessful() is true but getReply() returned empty or non-string."]);
            return;
        }
        $decoded = \json_decode($replyStr, true);
        if (!\is_array($decoded)) {
            \wp_send_json_success(["result" => "fail", "detail" => "getReply() returned string but JSON decode failed. Raw: " . $replyStr]);
            return;
        }
        if (!\array_key_exists("hits", $decoded)) {
            \wp_send_json_success(["result" => "fail", "detail" => "JSON reply missing 'hits' key. Keys present: " . \implode(", ", \array_keys($decoded))]);
            return;
        }
        if (!\is_array($decoded["hits"])) {
            \wp_send_json_success(["result" => "fail", "detail" => "'hits' key is not an array. Type: " . \gettype($decoded["hits"])]);
            return;
        }
        $hitCount = \count($decoded["hits"]);
        \wp_send_json_success(["result" => "pass", "detail" => "Job " . $jobId . " completed. Search returned " . $hitCount . " hit(s)."]);
    }

    private function run_caller_blocklist(): void {
        $testCaller = "sole-engine-blocklist-test";
        $optionName = "sole_engine_caller_blocklist";

        try {
            // Read current blocklist and add the test caller.
            $current = \get_option($optionName, []);
            if (!\is_array($current)) {
                $current = [];
            }
            $alreadyBlocked = \in_array($testCaller, $current, true);
            if (!$alreadyBlocked) {
                $current[] = $testCaller;
                \update_option($optionName, $current);
            }

            // Submit with the blocked caller — should be rejected immediately.
            $llm = sole_engine_llm();
            $ticket = $llm->submit($testCaller, "chat", "Blocklist self-test — this should never reach the API.");

            // Restore: re-read fresh state so concurrent admin edits are preserved.
            if (!$alreadyBlocked) {
                $this->removeTestCallerFromBlocklist($optionName, $testCaller);
            }
        } catch (\Throwable $e) {
            if (isset($alreadyBlocked) && !$alreadyBlocked) {
                $this->removeTestCallerFromBlocklist($optionName, $testCaller);
            }
            \wp_send_json_success(["result" => "fail", "detail" => "Exception: " . $e->getMessage()]);
            return;
        }

        if ($ticket->wasAccepted()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Blocked caller was accepted — blocklist did not gate the submit."]);
            return;
        }
        if ($ticket->getError() !== "caller_blocked") {
            \wp_send_json_success(["result" => "fail", "detail" => "Expected error 'caller_blocked', got: " . ($ticket->getError() ?? "null")]);
            return;
        }
        \wp_send_json_success(["result" => "pass", "detail" => "Blocked caller correctly rejected with caller_blocked error."]);
    }

    private function removeTestCallerFromBlocklist(string $optionName, string $testCaller): void {
        $fresh = \get_option($optionName, []);
        if (!\is_array($fresh)) {
            $fresh = [];
        }
        $restored = \array_values(\array_filter($fresh, fn(string $c): bool => $c !== $testCaller));
        \update_option($optionName, $restored);
    }

    private function run_caller_credits(): void {
        $store = new Diagnostics_Store();
        $creditsBefore = $store->getCallerCredits();
        $before = $creditsBefore["sole-engine-self-test"] ?? 0.0;

        // Submit a chat task (reuses self-test caller).
        $llm = sole_engine_llm();
        $ticket = $llm->submit("sole-engine-self-test", "chat", "Credit recording self-test at " . \gmdate("c") . ". Reply with OK.");
        if (!$ticket->wasAccepted()) {
            \wp_send_json_success(["result" => "fail", "detail" => "Submit rejected: " . ($ticket->getError() ?? "unknown")]);
            return;
        }

        $creditsAfter = $store->getCallerCredits();
        $after = $creditsAfter["sole-engine-self-test"] ?? 0.0;
        if ($after > $before) {
            \wp_send_json_success(["result" => "pass", "detail" => "Credits recorded. Before: " . $before . ", after: " . $after . " (+" . ($after - $before) . ")."]);
            return;
        }
        \wp_send_json_success(["result" => "fail", "detail" => "Credits not recorded. Before: " . $before . ", after: " . $after . "."]);
    }

    private function pollLlm(\SoleEngineLLMInterface $llm, string $jobId): ?\SoleEngineReplyStringInterface {
        $result = null;
        for ($i = 0; $i < self::MAX_POLL_ATTEMPTS; $i++) {
            $result = $llm->getResult($jobId);
            if (!$result->isPending()) {
                return $result;
            }
            if ($i < self::MAX_POLL_ATTEMPTS - 1) {
                \sleep(self::POLL_INTERVAL_SECONDS);
            }
        }
        return $result;
    }

    private function pollSemantic(\SoleEngineSemanticInterface $semantic, string $jobId): ?\SoleEngineReplyStringInterface {
        $result = null;
        for ($i = 0; $i < self::MAX_POLL_ATTEMPTS; $i++) {
            $result = $semantic->getResult($jobId);
            if (!$result->isPending()) {
                return $result;
            }
            if ($i < self::MAX_POLL_ATTEMPTS - 1) {
                \sleep(self::POLL_INTERVAL_SECONDS);
            }
        }
        return $result;
    }
}

/**
 * Internal self-tests: verify internal components directly.
 * Can access module-private classes and internals for thorough testing.
 */
final class Routines_AdminTestInternal implements Routines_RoutineInterface {
    private const TESTS = [
        "ttl_resolver" => "Each error code returns the expected TTL value",
        "site_normalizer" => "URL normalization handles edge cases correctly",
        "error_log_write" => "Error log records and retrieves entries correctly",
        "config_readable" => "Config store reads endpoint and user key without error",
        "raw_health" => "Direct HTTP to API health endpoint returns valid response",
        "raw_submit" => "Direct HTTP submit to API returns valid response shape",
        "content_chunking" => "Content chunking splits text correctly at paragraph, sentence, and hard boundaries",
        "content_hash" => "Content hash is deterministic and changes with content",
    ];

    public function execute(): void {
        foreach (self::TESTS as $id => $description) {
            \add_action("wp_ajax_sole_engine_test_internal_" . $id, function () use ($id): void {
                if (!\current_user_can("manage_options")) {
                    \wp_send_json_error(["error" => "forbidden"], 403);
                    return;
                }
                \check_ajax_referer("sole_engine_diagnostics", "_wpnonce");
                $method = "run_" . $id;
                $this->$method();
            });
        }
    }

    public function renderTestTable(): void {
        echo "<table class=\"widefat striped\">";
        echo "<thead><tr><th>Test</th><th>Description</th><th style=\"width:70px;\">Result</th><th>Detail</th><th style=\"width:50px;\"></th></tr></thead>";
        echo "<tbody>";
        foreach (self::TESTS as $id => $description) {
            $prefix = "sole_engine_test_internal_" . $id;
            echo "<tr id=\"" . \esc_attr($prefix) . "_row\">";
            echo "<td>" . \esc_html($id) . "</td>";
            echo "<td>" . \esc_html($description) . "</td>";
            echo "<td id=\"" . \esc_attr($prefix) . "_result\">-</td>";
            echo "<td id=\"" . \esc_attr($prefix) . "_detail\" style=\"white-space:pre-wrap;\">-</td>";
            echo "<td><button type=\"button\" class=\"button\" id=\"" . \esc_attr($prefix) . "_run\">Run</button></td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";
        echo "<div style=\"margin-top:8px;\">";
        $allIds = \array_keys(self::TESTS);
        echo "<button type=\"button\" class=\"button\" id=\"sole_engine_run_all_internal\" data-category=\"internal\" data-test-ids=\"" . \esc_attr(\implode(",", $allIds)) . "\" data-all-ids=\"" . \esc_attr(\implode(",", $allIds)) . "\">Run All Internal</button>";
        echo "</div>";
    }

    private function run_ttl_resolver(): void {
        // The judgement lives in Routines_TtlPostureCheck so the unit suite can
        // exercise its branches. This handler only reports what it is told.
        $resolver = new Engine_ErrorTtlResolver();
        $check = new Routines_TtlPostureCheck();
        $violations = $check->findViolations($resolver);

        if (\count($violations) === 0) {
            \wp_send_json_success([
                "result" => "pass",
                "detail" => "Caching posture holds: administrator-cure faults uncached, quota_exceeded cached, retired terms inert, default "
                    . $check->describeDefault($resolver) . "s."
            ]);
            return;
        }
        \wp_send_json_success(["result" => "fail", "detail" => "Posture violated: " . \implode("; ", $violations)]);
    }

    private function run_site_normalizer(): void {
        $normalizer = new Engine_SiteIdNormalizer();
        $cases = [
            ["https://example.com/", "https://example.com"],
            ["HTTPS://Example.COM/path/", "https://example.com/path"],
            ["http://example.com:80", "http://example.com"],
            ["https://example.com:443", "https://example.com"],
            ["http://example.com:8080", "http://example.com:8080"],
            ["", ""],
        ];
        $failures = [];
        foreach ($cases as $case) {
            $actual = $normalizer->normalize($case[0]);
            if ($actual !== $case[1]) {
                $failures[] = "'" . $case[0] . "' => expected '" . $case[1] . "', got '" . $actual . "'";
            }
        }
        if (\count($failures) === 0) {
            \wp_send_json_success(["result" => "pass", "detail" => "All " . \count($cases) . " normalization cases passed."]);
        } else {
            \wp_send_json_success(["result" => "fail", "detail" => \implode("; ", $failures)]);
        }
    }

    private function run_error_log_write(): void {
        $diagnostics = new Diagnostics_Factory();
        $store = $diagnostics->makeStore();
        $testCode = "selftest_" . \time();
        $store->recordError($testCode, "selftest", \gmdate("c"));
        $log = $store->getErrorLog();
        $found = false;
        foreach ($log as $entry) {
            if (isset($entry["code"]) && $entry["code"] === $testCode && isset($entry["context"]) && $entry["context"] === "selftest") {
                $found = true;
                break;
            }
        }
        if ($found) {
            \wp_send_json_success(["result" => "pass", "detail" => "Recorded error '" . $testCode . "' and found it in the log (" . \count($log) . " entries total)."]);
        } else {
            \wp_send_json_success(["result" => "fail", "detail" => "Recorded error '" . $testCode . "' but could not find it in the log."]);
        }
    }

    private function run_config_readable(): void {
        $config = new Config_Factory();
        $store = $config->makeStore();
        $endpoint = $store->getEndpoint();
        $endpointStatus = $endpoint !== null ? "set (" . \strlen($endpoint) . " chars)" : "not set";
        $credential = Routines_CredentialStatusReading::read($store, (new Diagnostics_Factory())->makeStore());
        \wp_send_json_success(["result" => "pass", "detail" => "Endpoint: " . $endpointStatus . ". Saved user key: " . $credential["label"] . "."]);
    }

    private function run_raw_health(): void {
        $config = new Config_Factory();
        $store = $config->makeStore();
        $endpoint = $store->getEndpoint();
        if ($endpoint === null) {
            \wp_send_json_success(["result" => "skip", "detail" => "No endpoint configured. Set a user key or custom endpoint first."]);
            return;
        }
        $http = new Http_Factory();
        $client = $http->makeClient(10);
        $response = $client->getJson(\rtrim($endpoint, "/") . "/v1/health");
        if ($response->getError() !== null) {
            \wp_send_json_success(["result" => "fail", "detail" => "HTTP error reaching " . $endpoint . "/v1/health"]);
            return;
        }
        if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
            \wp_send_json_success(["result" => "fail", "detail" => "Health endpoint returned HTTP " . $response->getStatus()]);
            return;
        }
        $decoded = \json_decode($response->getBody(), true);
        if (!\is_array($decoded)) {
            \wp_send_json_success(["result" => "fail", "detail" => "Health endpoint returned invalid JSON."]);
            return;
        }
        $status = isset($decoded["status"]) && \is_string($decoded["status"]) ? $decoded["status"] : "unknown";
        \wp_send_json_success(["result" => "pass", "detail" => "Engine service health responded (this does not verify the saved user key): status=" . $status]);
    }

    private function run_raw_submit(): void {
        $config = new Config_Factory();
        $store = $config->makeStore();
        $endpoint = $store->getEndpoint();
        $userKey = $store->getUserKey();
        if ($endpoint === null || $userKey === null) {
            \wp_send_json_success(["result" => "skip", "detail" => "Endpoint or user key not configured."]);
            return;
        }
        $http = new Http_Factory();
        $client = $http->makeClient(10);
        $payload = [
            "user_key" => $userKey,
            "site_id" => (new Engine_SiteIdResolver())->resolve(\home_url()),
            "task" => "chat",
            "payload" => "Raw submit self-test at " . \gmdate("c"),
            "options" => null,
        ];
        Engine_HumanDemoBridge::mint("raw_submit", $endpoint . "/v1/tasks/submit", $payload);
        $response = $client->postJson($endpoint . "/v1/tasks/submit", $payload);
        if ($response->getError() !== null) {
            \wp_send_json_success(["result" => "fail", "detail" => "HTTP error reaching submit endpoint."]);
            return;
        }
        $decoded = \json_decode($response->getBody(), true);
        if (!\is_array($decoded)) {
            \wp_send_json_success(["result" => "fail", "detail" => "Submit endpoint returned HTTP " . $response->getStatus() . " with invalid JSON."]);
            return;
        }
        if (isset($decoded["was_accepted"]) && $decoded["was_accepted"] === true) {
            (new Diagnostics_Factory())->makeStore()->recordCredentialEvidence($userKey, "verified", \gmdate("c"));
            $jobId = isset($decoded["job_id"]) ? $decoded["job_id"] : "none";
            \wp_send_json_success(["result" => "pass", "detail" => "Submit accepted. job_id=" . $jobId]);
        } else {
            $errorCode = isset($decoded["error"]["code"]) ? $decoded["error"]["code"] : "unknown";
            if ($errorCode === "user_key_invalid") {
                (new Diagnostics_Factory())->makeStore()->recordCredentialEvidence($userKey, "invalid", \gmdate("c"));
                \wp_send_json_success(["result" => "fail", "detail" => "Submit rejected by API: user_key_invalid. Replace the saved user key with a valid key from your SOLE account, then verify it again."]);
                return;
            }
            \wp_send_json_success(["result" => "fail", "detail" => "Submit rejected by API: " . $errorCode]);
        }
    }

    private function run_content_chunking(): void {
        $processor = new Indexing_ContentProcessor();
        $failures = [];

        // Test 1: short text -> single content chunk.
        $post1 = new \WP_Post((object) [
            "ID" => 0,
            "post_title" => "Test Title",
            "post_content" => "Short paragraph here.",
            "post_excerpt" => "",
        ]);
        $chunks1 = $processor->chunkPost($post1);
        $contentChunks1 = \array_values(\array_filter($chunks1, function ($c) { return $c["field"] === "post_content"; }));
        if (\count($contentChunks1) !== 1) {
            $failures[] = "short text: expected 1 content chunk, got " . \count($contentChunks1);
        }

        // Test 2: double newlines -> splits on paragraphs.
        $post2 = new \WP_Post((object) [
            "ID" => 0,
            "post_title" => "",
            "post_content" => "Paragraph one.\n\nParagraph two.\n\nParagraph three.",
            "post_excerpt" => "",
        ]);
        $chunks2 = $processor->chunkPost($post2);
        $contentChunks2 = \array_values(\array_filter($chunks2, function ($c) { return $c["field"] === "post_content"; }));
        if (\count($contentChunks2) !== 3) {
            $failures[] = "paragraph split: expected 3 content chunks, got " . \count($contentChunks2);
        }

        // Test 3: long paragraph (>1500 chars) -> splits on sentence boundaries.
        $longParagraph = "";
        for ($i = 0; $i < 25; $i++) {
            $longParagraph .= "This is sentence number " . $i . " in a very long paragraph that keeps going and going. ";
        }
        $post3 = new \WP_Post((object) [
            "ID" => 0,
            "post_title" => "",
            "post_content" => $longParagraph,
            "post_excerpt" => "",
        ]);
        $chunks3 = $processor->chunkPost($post3);
        $contentChunks3 = \array_values(\array_filter($chunks3, function ($c) { return $c["field"] === "post_content"; }));
        if (\count($contentChunks3) < 2) {
            $failures[] = "sentence split: expected >=2 content chunks for long paragraph, got " . \count($contentChunks3);
        }
        foreach ($contentChunks3 as $chunk) {
            if (\mb_strlen($chunk["text"]) > 1500) {
                $failures[] = "sentence split: chunk exceeds 1500 chars (" . \mb_strlen($chunk["text"]) . ")";
                break;
            }
        }

        // Test 4: long sentence (>1500 chars, no punctuation) -> hard-splits.
        $longSentence = \str_repeat("word ", 400);
        $post4 = new \WP_Post((object) [
            "ID" => 0,
            "post_title" => "",
            "post_content" => \trim($longSentence),
            "post_excerpt" => "",
        ]);
        $chunks4 = $processor->chunkPost($post4);
        $contentChunks4 = \array_values(\array_filter($chunks4, function ($c) { return $c["field"] === "post_content"; }));
        if (\count($contentChunks4) < 2) {
            $failures[] = "hard split: expected >=2 content chunks for 2000-char sentence, got " . \count($contentChunks4);
        }
        foreach ($contentChunks4 as $chunk) {
            if (\mb_strlen($chunk["text"]) > 1500) {
                $failures[] = "hard split: chunk exceeds 1500 chars (" . \mb_strlen($chunk["text"]) . ")";
                break;
            }
        }

        // Test 5: CJK sentence boundaries.
        $cjkText = \str_repeat("This is a test sentence with mixed content here", 20) . "\xe3\x80\x82 " . \str_repeat("Another sentence follows here with more text added", 20) . "\xef\xbc\x81";
        $post5 = new \WP_Post((object) [
            "ID" => 0,
            "post_title" => "",
            "post_content" => $cjkText,
            "post_excerpt" => "",
        ]);
        $chunks5 = $processor->chunkPost($post5);
        $contentChunks5 = \array_values(\array_filter($chunks5, function ($c) { return $c["field"] === "post_content"; }));
        if (\count($contentChunks5) < 2) {
            $failures[] = "CJK split: expected >=2 content chunks with CJK sentence boundaries, got " . \count($contentChunks5);
        }

        if (\count($failures) === 0) {
            \wp_send_json_success(["result" => "pass", "detail" => "All 5 chunking cases passed (short, paragraph, sentence, hard, CJK)."]);
        } else {
            \wp_send_json_success(["result" => "fail", "detail" => \implode("; ", $failures)]);
        }
    }

    private function run_content_hash(): void {
        $processor = new Indexing_ContentProcessor();
        $failures = [];

        // Test 1: same content -> same hash.
        $postA = new \WP_Post((object) [
            "ID" => 0,
            "post_title" => "Determinism Test",
            "post_content" => "The quick brown fox jumps over the lazy dog.",
            "post_excerpt" => "A test excerpt.",
        ]);
        $postB = new \WP_Post((object) [
            "ID" => 0,
            "post_title" => "Determinism Test",
            "post_content" => "The quick brown fox jumps over the lazy dog.",
            "post_excerpt" => "A test excerpt.",
        ]);
        $hashA = $processor->computeContentHash($postA);
        $hashB = $processor->computeContentHash($postB);
        if ($hashA !== $hashB) {
            $failures[] = "determinism: same content produced different hashes";
        }

        // Test 2: different content -> different hash.
        $postC = new \WP_Post((object) [
            "ID" => 0,
            "post_title" => "Different Title",
            "post_content" => "Completely different content here.",
            "post_excerpt" => "",
        ]);
        $hashC = $processor->computeContentHash($postC);
        if ($hashA === $hashC) {
            $failures[] = "uniqueness: different content produced the same hash";
        }

        // Test 3: hash is 64 hex chars (SHA256).
        if (!\preg_match('/^[0-9a-f]{64}$/', $hashA)) {
            $failures[] = "format: hash is not 64 lowercase hex chars, got: " . $hashA;
        }

        if (\count($failures) === 0) {
            \wp_send_json_success(["result" => "pass", "detail" => "All 3 hash tests passed (determinism, uniqueness, format)."]);
        } else {
            \wp_send_json_success(["result" => "fail", "detail" => \implode("; ", $failures)]);
        }
    }
}

/**
 * Registers SOLE as a WordPress 7 AI Client provider. Lazy-loads
 * ai_provider.php (whose classes implement SDK interfaces) only when the SDK is
 * actually present, so nothing loads on WordPress without the AI Client. Gated
 * by the admin enable toggle, which is the kill-switch for the whole surface.
 */
final class Routines_AiProvider implements Routines_RoutineInterface {
    public function execute(): void {
        // WordPress snapshots the AI provider registry into its Connector API
        // at init priority 15. Register before that snapshot so every connector
        // consumer can discover SOLE through WordPress's standard integration.
        \add_action("init", function (): void {
            if (!\function_exists("wp_ai_client_prompt")) {
                return;
            }
            // Concrete check: the adapter `implements` this interface, so it
            // must exist before ai_provider.php is loaded or the class
            // declaration would fatal.
            if (!\interface_exists("WordPress\\AiClient\\Providers\\Contracts\\ProviderInterface")) {
                return;
            }
            $config = (new Config_Factory())->makeStore();
            if (!($config instanceof Config_WpAiInterface) || !$config->isWpProviderEnabled()) {
                return;
            }
            require_once __DIR__ . "/ai_provider.php";
            \WordPress\AiClient\AiClient::defaultRegistry()->registerProvider(AiProvider_Provider::class);
        }, 10);
    }
}

/**
 * Main routine orchestrating plugin wiring.
 */
final class Routines_Main implements Routines_RoutineInterface {
    public function execute(): void {
        (new Routines_AdminConsole())->execute();
        (new Routines_AdminDiagnostics())->execute();
        (new Routines_AdminSettings())->execute();
        (new Routines_AdminTestExternal())->execute();
        (new Routines_AdminTestInternal())->execute();
        (new Routines_Indexing())->execute();
        (new Routines_AiProvider())->execute();
    }
}
