<?php

declare(strict_types=1);

namespace SoleEngineWP;

if (!defined("ABSPATH")) {
    exit;
}

require_once __DIR__ . "/contracts.php";

                               
                                                                  


                                                                             
                              
                                                                             
  
         


                                                                             
         
                                                                             
  
         


                                                                             
        
                                                                             
  
         


                                                                             
          
                                                                             
final class Config_Factory implements Config_FactoryInterface {
    public function makeStore(): Config_StoreInterface {
        return new Config_Store();
    }

    public function makeEndpointPolicy(): Config_EndpointPolicyInterface {
        return new Config_EndpointPolicy();
    }
}

final class Config_EndpointPolicy implements Config_EndpointPolicyInterface {
    public function classify(mixed $value): Config_EndpointVerdict {
        if (!\is_string($value)) {
            return Config_EndpointVerdict::INVALID;
        }

        $trimmed = \trim($value);
        if ($trimmed === "") {
            return Config_EndpointVerdict::DEFAULT;
        }
        if ($trimmed !== $value) {
            return Config_EndpointVerdict::INVALID;
        }

                                                                            
                                                                             
                                                                             
        $forbidden = \preg_match('/[\x{0000}-\x{0020}\x{007F}\x{0085}\p{Z}\\\\]/u', $value);
        if ($forbidden !== 0) {
            return Config_EndpointVerdict::INVALID;
        }

                                                                              
                                                                             
        if (\str_contains($value, "?") || \str_contains($value, "#")) {
            return Config_EndpointVerdict::INVALID;
        }

        $parts = \wp_parse_url($value);
        if (!\is_array($parts)) {
            return Config_EndpointVerdict::INVALID;
        }
        $scheme = $parts["scheme"] ?? null;
        $host = $parts["host"] ?? null;
        if (!\is_string($scheme) || \strcasecmp($scheme, "https") !== 0) {
            return Config_EndpointVerdict::INVALID;
        }
        if (!\is_string($host) || $host === "") {
            return Config_EndpointVerdict::INVALID;
        }
        if (\array_key_exists("user", $parts) || \array_key_exists("pass", $parts)) {
            return Config_EndpointVerdict::INVALID;
        }

        return Config_EndpointVerdict::VALID;
    }
}

                                                                     
                                                                         
                                                                            
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
        if ($debugMode !== "1") {
            return \rtrim(self::DEFAULT_ENDPOINT, "/");
        }

        $missing = new \stdClass();
        $value = \get_option(self::OPTION_ENDPOINT, $missing);
        if ($value === $missing) {
            return \rtrim(self::DEFAULT_ENDPOINT, "/");
        }

        $verdict = (new Config_EndpointPolicy())->classify($value);
        if ($verdict === Config_EndpointVerdict::DEFAULT) {
            return \rtrim(self::DEFAULT_ENDPOINT, "/");
        }
        if ($verdict === Config_EndpointVerdict::INVALID || !\is_string($value)) {
            return null;
        }

                                                                            
                                                                              
                                                        
        return \rtrim($value, "/");
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
                                                                                
                                                                          
        return $this->readBoolOption(self::OPTION_WP_PROVIDER_ENABLED, true);
    }

    public function isFrontEndRenderAllowed(): bool {
                                                                           
                                                                                
                                                                               
                                                                             
                                                                    
        return $this->readBoolOption(self::OPTION_FRONT_END_ALLOWED, true);
    }

                                                                                
                                                                               
                                                                                
                                                                             
                                                                       
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
