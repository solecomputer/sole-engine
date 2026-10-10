<?php

declare(strict_types=1);

                                                                               
                                                                            
                                                                                  
                                                                               
                                                                   

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

                                                                               
                                                                                 
                                                                                   
                                                                                      
    interface Core_RegistryInterface {
        public function getLlm(): \SoleEngineLLMInterface;
        public function getSemantic(): \SoleEngineSemanticInterface;
        public function getLlmSync(): SoleEngineLLMSyncInterface;
        public function isSemanticEnabled(): bool;
        public function getCredentialReadiness(): \SoleEngineCredentialReadinessInterface;
    }

                             
    final class Core_Plugin implements Core_PluginInterface {
        private Routines_RoutineInterface $main;

        public function __construct(?Routines_RoutineInterface $main = null) {
            $this->main = $main ?? new Routines_Main();
        }

        public function boot(): void {
            $this->main->execute();
        }
    }

                                                 
    final class Core_Registry implements Core_RegistryInterface {
        private static ?Core_RegistryInterface $instance = null;
        private ?\SoleEngineLLMInterface $llm = null;
        private ?\SoleEngineSemanticInterface $semantic = null;
        private ?SoleEngineLLMSyncInterface $llmSync = null;
        private ?Cache_StoreInterface $cacheStore = null;
        private ?Config_StoreInterface $configStore = null;
        private ?Diagnostics_StoreInterface $diagnosticsStore = null;
        private ?Http_ClientInterface $httpClient = null;
                                                                                   
                                             
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

                                                                              
                                                                              
                                                                                 
                                                    
      
                                                                                 
                                                                                 
                                                                                  
                                                            
    function sole_engine_llm_sync(): SoleEngineLLMSyncInterface {
        return Core_Registry::getInstance()->getLlmSync();
    }

}

namespace {
                                                                              
                                                                                
                                                                                  
                                                                          

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
