<?php

declare(strict_types=1);

namespace SoleEngineWP;

                                                  
  
                                                                              
                                                                                 
                                                                             
                                                                               
                                                                           
  
                                                                          
                                                                               
                                                                  
                                          
  
                                                                                 
                                                                                
                                                                                
                                 

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ProviderInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

if (!defined("ABSPATH")) {
    exit;
}


                                                                             
                              
                                                                             
  
                                                           


                                                                             
         
                                                                             
  
         


                                                                             
        
                                                                             
  
         


                                                                             
          
                                                                             
                                                                            
                                                                        
final class AiProvider_Availability implements ProviderAvailabilityInterface {
    public function isConfigured(): bool {
        $config = (new Config_Factory())->makeStore();
        return $config->getUserKey() !== null;
    }
}

                                                                             
                                                                                 
                                                                              
                                                              
final class AiProvider_ModelDirectory implements ModelMetadataDirectoryInterface {
    public const MODEL_ID = "sole-auto";

                                      
    public function listModelMetadata(): array {
        return [$this->soleAutoMetadata()];
    }

    public function hasModelMetadata(string $modelId): bool {
        return $modelId === self::MODEL_ID;
    }

    public function getModelMetadata(string $modelId): ModelMetadata {
        if ($modelId !== self::MODEL_ID) {
            throw new \InvalidArgumentException("Unknown SOLE model: " . $modelId);
        }
        return $this->soleAutoMetadata();
    }

    private function soleAutoMetadata(): ModelMetadata {
        return new ModelMetadata(
            self::MODEL_ID,
            "SOLE Auto-Select",
            [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
            $this->supportedOptions()
        );
    }

       
                                             
      
                                                                                
                                                                                
                                                                               
                                                                               
                                                                               
      
                                                                               
                                                                                
                                                                             
                    
      
                                    
       
    private function supportedOptions(): array {
        if (!\class_exists(SupportedOption::class) || !\class_exists(OptionEnum::class)) {
            return [];
        }

        $options = [];
                                                                                
                                                                                
                                                                               
                                                         
        if (
            \class_exists(ModalityEnum::class)
            && \defined(OptionEnum::class . "::INPUT_MODALITIES")
            && \defined(ModalityEnum::class . "::TEXT")
        ) {
            $options[] = new SupportedOption(
                OptionEnum::inputModalities(),
                [[ModalityEnum::text()]]
            );
        }
        if (
            \class_exists(ModalityEnum::class)
            && \defined(ModelConfig::class . "::KEY_OUTPUT_MODALITIES")
            && \defined(ModalityEnum::class . "::TEXT")
        ) {
            $options[] = new SupportedOption(
                OptionEnum::outputModalities(),
                [[ModalityEnum::text()]]
            );
        }
        if (\defined(ModelConfig::class . "::KEY_SYSTEM_INSTRUCTION")) {
            $options[] = new SupportedOption(OptionEnum::systemInstruction());
        }

        return $options;
    }
}

                                                                                 
                                                                                 
                                                                            
                                                     
final class AiProvider_Provider implements ProviderInterface {
    public const PROVIDER_ID = "sole";

    public static function metadata(): ProviderMetadata {
        return new ProviderMetadata(
            self::PROVIDER_ID,
            "Sole Engine",
            ProviderTypeEnum::server()
        );
    }

    public static function availability(): ProviderAvailabilityInterface {
        return new AiProvider_Availability();
    }

    public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
        return new AiProvider_ModelDirectory();
    }

    public static function model(string $modelId, ?ModelConfig $modelConfig = null): ModelInterface {
        $directory = new AiProvider_ModelDirectory();
        $metadata = $directory->getModelMetadata($modelId);                        
        return new AiProvider_TextModel(
            $modelConfig ?? new ModelConfig(),
            self::metadata(),
            $metadata
        );
    }
}

                                                                              
                                                                              
                                                                               
                                                             
final class AiProvider_TextModel implements ModelInterface, TextGenerationModelInterface {
    private const CALLER_ID = "wp_ai_client";

    private ModelConfig $config;
    private ProviderMetadata $providerMetadata;
    private ModelMetadata $modelMetadata;

    public function __construct(ModelConfig $config, ProviderMetadata $providerMetadata, ModelMetadata $modelMetadata) {
        $this->config = $config;
        $this->providerMetadata = $providerMetadata;
        $this->modelMetadata = $modelMetadata;
    }

    public function metadata(): ModelMetadata {
        return $this->modelMetadata;
    }

    public function providerMetadata(): ProviderMetadata {
        return $this->providerMetadata;
    }

    public function setConfig(ModelConfig $config): void {
        $this->config = $config;
    }

    public function getConfig(): ModelConfig {
        return $this->config;
    }

       
                                   
       
    public function generateTextResult(array $prompt): GenerativeAiResult {
                                                                               
                                                                           
                                                                            
                                                                               
                                                                             
                                                                                 
                                                                                     
                                                                                 
        if (!self::isBlockingContextAllowed()) {
            throw new \RuntimeException("sole-engine: synchronous AI generation is not permitted on front-end page requests.");
        }

                                                                               
                                                                               
                              
        $systemInstruction = $this->config->getSystemInstruction();
        $promptText = self::flattenPrompt($prompt, $systemInstruction);

                                                                                 
                                                           
          
                                                                              
                                                                                 
                                                                            
                                                                               
                                                                            
                                                                                
        if (!self::hasAnswerableContent($prompt, $systemInstruction)) {
            throw new \RuntimeException("sole-engine: empty_prompt");
        }
                                                                                 
                                                                              
                                                                                 
                                                                             
                                                                              
                                                                               
                                    

                                                                                 
                                                                                   
                                                                               
        $reply = sole_engine_llm_sync()->generate(self::CALLER_ID, "chat", $promptText);
        if (!$reply->isSuccessful()) {
                                                                               
                                                                            
            throw new \RuntimeException("sole-engine: " . ($reply->getError() ?? "provider_error"));
        }

        try {
            return self::buildResult($reply->getReply() ?? "", $this->providerMetadata, $this->modelMetadata);
        } catch (\Throwable $e) {
                                                                                 
                                                                           
            throw new \RuntimeException("sole-engine: result_assembly_failed: " . $e->getMessage());
        }
    }

       
                                                                            
                                                                             
                                                                                
                                                                              
                                                
      
                                     
       
    public static function flattenPrompt(array $messages, ?string $systemInstruction): string {
        $lines = [];
        if (\is_string($systemInstruction) && \trim($systemInstruction) !== "") {
            $lines[] = "System: " . \trim($systemInstruction);
        }
        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                throw new \RuntimeException("sole-engine: unsupported prompt entry (expected a Message).");
            }
            $role = $message->getRole()->isModel() ? "Assistant" : "User";
            foreach ($message->getParts() as $part) {
                if (!$part instanceof MessagePart) {
                    throw new \RuntimeException("sole-engine: unsupported message part.");
                }
                $text = $part->getText();
                if ($text === null) {
                                                                         
                    throw new \RuntimeException("sole-engine: unsupported non-text message part (files and tool calls are not supported).");
                }
                $lines[] = $role . ": " . $text;
            }
        }
        return \implode("\n\n", $lines);
    }

       
                                                   
      
                                                                          
                                                                                 
                                                                                 
                                                           
      
                                                                             
                                                                             
                                                                                 
                                                                                 
                                                                              
                                                           
      
                                                                               
                                                                             
                                                                          
                            
      
                                                                               
                                                                               
                                                                                
                                      
      
                                                                            
                                                                           
                    
      
                                     
       
    public static function hasAnswerableContent(array $messages, ?string $systemInstruction): bool {
        if (\is_string($systemInstruction) && \trim($systemInstruction) !== "") {
            return true;
        }
        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                continue;
            }
            foreach ($message->getParts() as $part) {
                if (!$part instanceof MessagePart) {
                    continue;
                }
                $text = $part->getText();
                if (\is_string($text) && \trim($text) !== "") {
                    return true;
                }
            }
        }

        return false;
    }

       
                                                                           
                                                                               
                                                                            
                                                                                  
       
    public static function buildResult(string $text, ProviderMetadata $providerMetadata, ModelMetadata $modelMetadata): GenerativeAiResult {
        $message = new ModelMessage([new MessagePart($text)]);
        $candidate = new Candidate($message, FinishReasonEnum::stop());
        return new GenerativeAiResult(
            \uniqid("sole-", true),
            [$candidate],
            new TokenUsage(0, 0, 0),
            $providerMetadata,
            $modelMetadata
        );
    }

    private static function isBlockingContextAllowed(): bool {
                                                                            
                                                                            
                                                                            
                                                                             
        $config = (new Config_Factory())->makeStore();
        if (!$config instanceof Config_WpAiInterface || $config->isFrontEndRenderAllowed()) {
            return true;
        }
                                                                          
                                                                                   
        if (\function_exists("is_admin") && \is_admin()) {
            return true;
        }
        if (\function_exists("wp_doing_cron") && \wp_doing_cron()) {
            return true;
        }
        if (\function_exists("wp_doing_ajax") && \wp_doing_ajax()) {
            return true;
        }
        if (\defined("REST_REQUEST") && REST_REQUEST) {
            return true;
        }
        return false;
    }
}
