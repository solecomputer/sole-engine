<?php

declare(strict_types=1);

// Braced namespaces are deliberate here, as in contracts.php: the global block
// at the foot of this file is the plugin's published access surface — the
// "holy access points" consumer plugins call — and everything above it is ours.
// Keeping them in one file puts the boundary in front of the reader instead of
// leaving it to convention. See epoch4_plan.md delta 2 (proposed).

namespace SoleEngineWP {
    if (!defined("ABSPATH")) {
        exit;
    }

    require_once __DIR__ . "/contracts.php";
    require_once __DIR__ . "/cache.php";
    require_once __DIR__ . "/config.php";
    require_once __DIR__ . "/diagnostics.php";
    require_once __DIR__ . "/engine.php";
    require_once __DIR__ . "/http.php";
    require_once __DIR__ . "/indexing.php";
    require_once __DIR__ . "/routines.php";

    // Registry interface for shared engine instances. Module-private (declared
    // here, not in contracts.php): only Core_Registry implements it and only the
    // global access-point functions call it. getLlmSync() lives here alongside its
    // async siblings for consistency, since getInstance() is typed to this interface.
    interface Core_RegistryInterface {
        public function getLlm(): \SoleEngineLLMInterface;
        public function getSemantic(): \SoleEngineSemanticInterface;
        public function getLlmSync(): SoleEngineLLMSyncInterface;
        public function isSemanticEnabled(): bool;
        public function getCredentialReadiness(): \SoleEngineCredentialReadinessInterface;
    }

    // Core plugin bootstrap.
    final class Core_Plugin implements Core_PluginInterface {
        private Routines_RoutineInterface $main;

        public function __construct(?Routines_RoutineInterface $main = null) {
            $this->main = $main ?? new Routines_Main();
        }

        public function boot(): void {
            $this->main->execute();
        }
    }

    // Core registry for shared engine instances.
    final class Core_Registry implements Core_RegistryInterface {
        private static ?Core_RegistryInterface $instance = null;
        private ?\SoleEngineLLMInterface $llm = null;
        private ?\SoleEngineSemanticInterface $semantic = null;
        private ?SoleEngineLLMSyncInterface $llmSync = null;
        private ?Cache_StoreInterface $cacheStore = null;
        private ?Config_StoreInterface $configStore = null;
        private ?Diagnostics_StoreInterface $diagnosticsStore = null;
        private ?Http_ClientInterface $httpClient = null;
        // Distinct from $httpClient (30s): the sync bridge needs its own client at
        // the admin-configured sync timeout.
        private ?Http_ClientInterface $httpClientSync = null;

        private function __construct() {}

        public static function getInstance(): Core_RegistryInterface {
            if (self::$instance === null) {
                self::$instance = new Core_Registry();
            }
            return self::$instance;
        }

        public function getLlm(): \SoleEngineLLMInterface {
            if ($this->llm === null) {
                $this->llm = new Engine_Llm(
                    $this->getCacheStore(),
                    $this->getConfigStore(),
                    $this->getHttpClient(),
                    $this->getDiagnosticsStore()
                );
            }
            return $this->llm;
        }

        public function getSemantic(): \SoleEngineSemanticInterface {
            if ($this->semantic === null) {
                if (!$this->getConfigStore()->isSemanticEnabled()) {
                    $this->semantic = new Engine_SemanticDisabled();
                } else {
                    $this->semantic = new Engine_Semantic(
                        $this->getCacheStore(),
                        $this->getConfigStore(),
                        $this->getHttpClient(),
                        $this->getDiagnosticsStore(),
                        new Indexing_SearchHitResolver(new Indexing_ContentProcessor())
                    );
                }
            }
            return $this->semantic;
        }

        public function getLlmSync(): SoleEngineLLMSyncInterface {
            if ($this->llmSync === null) {
                $this->llmSync = new Engine_LlmSync(
                    $this->getConfigStore(),
                    $this->getHttpClientSync(),
                    $this->getDiagnosticsStore()
                );
            }
            return $this->llmSync;
        }

        public function isSemanticEnabled(): bool {
            return $this->getConfigStore()->isSemanticEnabled();
        }

        public function getCredentialReadiness(): \SoleEngineCredentialReadinessInterface {
            // Do not cache this snapshot: consumers may call again after an
            // account check or engine request records evidence in this request.
            return new Engine_CredentialReadiness($this->getConfigStore(), $this->getDiagnosticsStore());
        }

        private function getConfigStore(): Config_StoreInterface {
            if ($this->configStore === null) {
                $factory = new Config_Factory();
                $this->configStore = $factory->makeStore();
            }
            return $this->configStore;
        }

        private function getHttpClient(): Http_ClientInterface {
            if ($this->httpClient === null) {
                $factory = new Http_Factory();
                $this->httpClient = $factory->makeClient(30);
            }
            return $this->httpClient;
        }

        private function getHttpClientSync(): Http_ClientInterface {
            if ($this->httpClientSync === null) {
                $configStore = $this->getConfigStore();
                // The concrete Config_Store implements Config_WpAiInterface; the
                // instanceof guard keeps this honest if a different store is ever
                // injected, falling back to a safe default.
                $timeout = $configStore instanceof Config_WpAiInterface ? $configStore->getSyncTimeout() : 60;
                $factory = new Http_Factory();
                $this->httpClientSync = $factory->makeClient($timeout);
            }
            return $this->httpClientSync;
        }

        private function getDiagnosticsStore(): Diagnostics_StoreInterface {
            if ($this->diagnosticsStore === null) {
                $factory = new Diagnostics_Factory();
                $this->diagnosticsStore = $factory->makeStore();
            }
            return $this->diagnosticsStore;
        }

        private function getCacheStore(): Cache_StoreInterface {
            if ($this->cacheStore === null) {
                $factory = new Cache_Factory();
                $this->cacheStore = $factory->makeStore();
            }
            return $this->cacheStore;
        }
    }

    // @internal — Synchronous bridge accessor for the WordPress 7 AI Client
    // provider ONLY. NOT a consumer access point: every plugin that calls the
    // SOLE engine directly uses the asynchronous sole_engine_llm() in the global
    // block below. See compass.md "Sole exception".
    //
    // Namespaced by Landmarks delta 3 (ministry ruling 2026-08-10 18:01). It was
    // global while its own docblock and Landmarks both called it @internal; this
    // makes the published claim true rather than merely asserted. Every call site
    // is unqualified, so the move needed no call-site edit.
    function sole_engine_llm_sync(): SoleEngineLLMSyncInterface {
        return Core_Registry::getInstance()->getLlmSync();
    }

}

namespace {
    // The published access points. These are declared in the GLOBAL namespace
    // deliberately and permanently: consumer plugins call them unqualified from
    // inside their own namespaces, where PHP falls back to global — it does not
    // fall back to ours. Moving them would break every consumer silently.

    function sole_engine_llm(): SoleEngineLLMInterface {
        return \SoleEngineWP\Core_Registry::getInstance()->getLlm();
    }

    function sole_engine_semantic(): SoleEngineSemanticInterface {
        return \SoleEngineWP\Core_Registry::getInstance()->getSemantic();
    }

    function sole_engine_semantic_enabled(): bool {
        return \SoleEngineWP\Core_Registry::getInstance()->isSemanticEnabled();
    }

    function sole_engine_credential_readiness(): SoleEngineCredentialReadinessInterface {
        return \SoleEngineWP\Core_Registry::getInstance()->getCredentialReadiness();
    }
}
