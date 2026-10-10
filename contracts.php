<?php

declare(strict_types=1);

                                                                         
                                                                               
                                                         
  
                                                                                 
                                                                                  
                                                                             
                                                                             
                                                             

namespace {
    if (!defined("ABSPATH")) {
        exit;
    }

                                                        
    interface SoleEngineLLMInterface {
        public function submit(string $callerId, string $taskName, string $mainPayload, ?array $options = null): SoleEngineJobTicketInterface;
        public function getResult(string $jobId): SoleEngineReplyStringInterface;
    }

                                                      
    interface SoleEngineSemanticInterface {
        public function submit(string $callerId, string $taskName, string $mainPayload, ?array $options = null): SoleEngineJobTicketInterface;
        public function getResult(string $jobId): SoleEngineReplyStringInterface;
        public function getSiteId(): string;
    }

                                                                                
    interface SoleEngineCredentialReadinessInterface {
        public const STATE_MISSING = "missing";
        public const STATE_UNVERIFIED = "unverified";
        public const STATE_INVALID = "invalid";
        public const STATE_VERIFIED = "verified";

        public function getState(): string;
        public function isReady(): bool;
        public function getCheckedAt(): ?string;
    }

                                                  
    interface SoleEngineJobTicketInterface {
        public function wasAccepted(): bool;
        public function getJobId(): ?string;
        public function getError(): ?string;
    }

                                  
    interface SoleEngineReplyInterface {
        public function isPending(): bool;
        public function isSuccessful(): bool;
        public function getError(): ?string;
    }

                                      
    interface SoleEngineReplyStringInterface extends SoleEngineReplyInterface {
        public function getReply(): ?string;
    }

}

namespace SoleEngineWP {
                                                                                 
                                                                                   
                                                                                   
    const SOLE_ENGINE_ADMIN_MENU_SLUG = "sole-engine-wp-settings";

                                                                   
    interface Engine_LlmInterface extends \SoleEngineLLMInterface {}

                                                                        
    interface Engine_SemanticInterface extends \SoleEngineSemanticInterface {}

                                                                                 
                                                                           
                                                                               
                              
      
                                                                               
                                                                                
                                                                             
    interface SoleEngineSyncReplyInterface {
        public function isSuccessful(): bool;
        public function getReply(): ?string;
        public function getError(): ?string;
    }

                                                                              
                                                                                 
                                                                           
                                                                                
                                                    
    interface SoleEngineLLMSyncInterface {
        public function generate(string $callerId, string $taskName, string $mainPayload, ?array $options = null): SoleEngineSyncReplyInterface;
    }

                                                                           
    interface Engine_LlmSyncInterface extends SoleEngineLLMSyncInterface {}

                                       
    interface Core_PluginInterface {
        public function boot(): void;
    }

                                  
    interface Config_StoreInterface {
        public function getEndpoint(): ?string;
        public function getUserKey(): ?string;
        public function getTotalAttempts(): int;
        public function isSemanticEnabled(): bool;
        public function isCallerBlocked(string $callerId): bool;
    }

                                                                           
                                                                             
                                                                           
    enum Config_EndpointVerdict: string {
        case DEFAULT = "default";
        case VALID = "valid";
        case INVALID = "invalid";
    }

    interface Config_EndpointPolicyInterface {
        public function classify(mixed $value): Config_EndpointVerdict;
    }

                                        
    interface Config_FactoryInterface {
        public function makeStore(): Config_StoreInterface;
        public function makeEndpointPolicy(): Config_EndpointPolicyInterface;
    }

                                                                                  
                                                                                 
                                                                                   
                                                                            
                                                                                     
                                                                                    
                                                                                     
                                                                                
                                                     
    interface Config_WpAiInterface {
        public function getSyncTimeout(): int;
        public function isWpProviderEnabled(): bool;
        public function isFrontEndRenderAllowed(): bool;
    }

                                              
    interface Http_ResponseInterface {
        public function getStatus(): int;
        public function getBody(): string;
        public function getError(): ?string;
    }

                                    
    interface Http_ClientInterface {
        public function getJson(string $url): Http_ResponseInterface;
        public function postJson(string $url, array $payload): Http_ResponseInterface;
    }

                           
    interface Http_FactoryInterface {
        public function makeClient(int $timeoutSeconds): Http_ClientInterface;
    }

                                                          
    interface Routines_RoutineInterface {
        public function execute(): void;
    }

                                                  
    interface Diagnostics_StoreInterface {
        public function recordSuccess(string $timestamp): void;
                                                               
        public function recordError(string $errorCode, string $context, string $timestamp): void;
        public function getLastSuccess(): ?string;
        public function getLastError(): ?string;
        public function getLastErrorAt(): ?string;
                                                                      
        public function getErrorLog(): array;
        public function recordHealthStatus(string $status, string $timestamp): void;
        public function recordAccountStatus(string $status, array $quota, string $timestamp): void;
        public function getLastHealthStatus(): ?string;
        public function getLastHealthAt(): ?string;
        public function getLastAccountStatus(): ?string;
        public function getLastAccountAt(): ?string;
        public function getLastAccountQuota(): ?array;
        public function recordCallerCredits(string $callerId, float $credits): void;
                                                                       
        public function getCallerCredits(): array;
        public function resetCallerCredits(): void;
                                                                            
        public function recordCredentialEvidence(string $userKey, string $state, string $timestamp): void;
                                                                 
        public function getCredentialEvidence(string $userKey): ?array;
    }

                                     
    interface Diagnostics_FactoryInterface {
        public function makeStore(): Diagnostics_StoreInterface;
    }

                                                          
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

                               
    interface Cache_FactoryInterface {
        public function makeStore(): Cache_StoreInterface;
    }

                                                                                   
    interface Engine_SiteIdNormalizerInterface {
        public function normalize(string $rawUrl): string;
    }

    interface Engine_SiteIdentityReaderInterface {
        public function readPersistedHome(): ?string;
    }

                                                                           
                                                             
    interface Engine_SiteIdResolverInterface {
        public function resolve(string $requestDerivedFallback): string;
    }

                                                                               
                                                                            
                                                                               
                                                                             
    interface Engine_TransportInterface {
        public const ERROR_NETWORK = "network_error";
        public const ERROR_API_INVALID = "api_response_invalid";

                                                                              
                                                                                    
                                                                                
                                                                             
                                                                                
                                                                              
        public function sendRequest(string $url, array $payload): array;

                                                                                 
                                                                               
        public function buildHash(string $task, string $payload, ?array $options, string $siteId, string $userKey, string $endpoint): string;

                                                                                   
        public function readErrorCode(array $decoded): ?string;

                                                                               
        public function buildErrorContext(string $phase, array $decoded): string;

                                                                                    
        public function readHomeUrl(): string;

                                                                                    
        public function recordSuccess(): void;
        public function recordError(string $code, string $context): void;
    }

                                                                                
                                                                                   
                                                                                  
                                                                              
                                                                                
                                                                                
                                                                            
                                                           
                                                                                
                    
                                                                                 
                                                   
    interface Indexing_SearchHitResolverInterface {
        public function computeContentVersion(\WP_Post $post): string;
        public function resolveChunkText(\WP_Post $post, string $field, int $chunkOrd): ?string;
    }
}
