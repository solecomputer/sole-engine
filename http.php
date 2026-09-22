<?php

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

require_once __DIR__ . "/contracts.php";

// Module: HTTP client.
// Rationale: keep outbound engine calls isolated and mockable.


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
final class Http_Client implements Http_ClientInterface {
    private int $timeoutSeconds;

    public function __construct(int $timeoutSeconds) {
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getJson(string $url): Http_ResponseInterface {
        if (!\function_exists("wp_remote_get")) {
            return new Http_Response(0, "", "network_error");
        }
        $args = [
            "timeout" => $this->timeoutSeconds,
            "headers" => [
                "Content-Type" => "application/json"
            ]
        ];
        $response = \wp_remote_get($url, $args);
        if (\is_wp_error($response)) {
            return new Http_Response(0, "", "network_error: " . $response->get_error_message());
        }
        $status = \wp_remote_retrieve_response_code($response);
        $body = \wp_remote_retrieve_body($response);
        if (!\is_string($body)) {
            $body = "";
        }
        return new Http_Response((int) $status, $body, null);
    }

    public function postJson(string $url, array $payload): Http_ResponseInterface {
        if (!\function_exists("wp_remote_post")) {
            return new Http_Response(0, "", "network_error");
        }
        $args = [
            "timeout" => $this->timeoutSeconds,
            "headers" => [
                "Content-Type" => "application/json"
            ],
            "body" => \wp_json_encode($payload)
        ];
        $response = \wp_remote_post($url, $args);
        if (\is_wp_error($response)) {
            return new Http_Response(0, "", "network_error: " . $response->get_error_message());
        }
        $status = \wp_remote_retrieve_response_code($response);
        $body = \wp_remote_retrieve_body($response);
        if (!\is_string($body)) {
            $body = "";
        }
        return new Http_Response((int) $status, $body, null);
    }
}

final class Http_Factory implements Http_FactoryInterface {
    public function makeClient(int $timeoutSeconds): Http_ClientInterface {
        return new Http_Client($timeoutSeconds);
    }
}

final class Http_Response implements Http_ResponseInterface {
    private int $status;
    private string $body;
    private ?string $error;

    public function __construct(int $status, string $body, ?string $error) {
        $this->status = $status;
        $this->body = $body;
        $this->error = $error;
    }

    public function getStatus(): int {
        return $this->status;
    }

    public function getBody(): string {
        return $this->body;
    }

    public function getError(): ?string {
        return $this->error;
    }
}
