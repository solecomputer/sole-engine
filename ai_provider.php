<?php

declare(strict_types=1);

namespace SoleEngineWP;

// Module: WordPress 7 AI Client provider adapter.
//
// LAZY-LOADED by Routines_AiProvider only when the WordPress AI Client SDK is
// present. It is deliberately NOT required by core.php: its classes `implements`
// SDK interfaces that exist only when the SDK is loaded, so an unconditional
// require would fatal on WordPress installs without the AI Client. This is the
// documented exception to "core.php requires every module" (see ADR-0002).
//
// It bridges WordPress's SYNCHRONOUS generateTextResult() to the engine's
// @internal synchronous bridge (sole_engine_llm_sync()). The async submit/poll
// engine and every consumer plugin are unaffected. See compass.md
// "Sole exception", ADR-0002, and map.md.
//
// The SDK public contract is unscoped (WordPress\AiClient\…) on both WP 7 core
// and the WP 6.x wp-ai-client polyfill (verified); only vendored transport deps
// are scoped, and this adapter never touches those — it uses the engine's own
// HTTP path via the sync bridge.

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


// ==========================================================================
// INTERFACES (module-private)
// ==========================================================================
//
// (none — the adapter implements external SDK contracts)


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
// Availability: SOLE is "configured" once a user key is present. The engine
// endpoint is fixed (or debug-overridden), so the key is the only gate.
final class AiProvider_Availability implements ProviderAvailabilityInterface {
    public function isConfigured(): bool {
        $config = (new Config_Factory())->makeStore();
        return $config->getUserKey() !== null;
    }
}

// Model directory: exactly one virtual model, "sole-auto". The engine's real
// model pool is never published — the central rectifier picks the actual model
// per request. Capabilities are limited to what the adapter honestly fulfils:
// text generation over a (flattened) multi-turn conversation.
final class AiProvider_ModelDirectory implements ModelMetadataDirectoryInterface {
    public const MODEL_ID = "sole-auto";

    /** @return list<ModelMetadata> */
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

    /**
     * Options this adapter actually fulfils.
     *
     * WordPress 7.1 derives input/output modality requirements from an ordinary
     * text prompt, and consumers such as Chagency add a system instruction. The
     * adapter accepts text parts, returns text, and folds the instruction into
     * the engine payload, so advertising these three is exact. It deliberately
     * does not advertise function declarations or generation knobs it ignores.
     *
     * The guards preserve loading on an older WordPress 7.0 AI Client that may
     * not expose the newer option DTOs/constants. On such a client the historic
     * empty option list remains valid because it does not derive those newer
     * requirements.
     *
     * @return list<SupportedOption>
     */
    private function supportedOptions(): array {
        if (!\class_exists(SupportedOption::class) || !\class_exists(OptionEnum::class)) {
            return [];
        }

        $options = [];
        // OptionEnum factories are supplied by AbstractEnum::__callStatic(), so
        // method_exists() cannot detect them. INPUT_MODALITIES is the factory's
        // authoritative case; output/system cases are derived dynamically from
        // the corresponding ModelConfig KEY_* constants.
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

// Provider entry point. Registered as SERVER type so WordPress does not render a
// duplicate API-key field under Settings > AI Credentials (SOLE already owns its
// own user-key field) and the registry does not inject auth into the model.
// All four methods are static, per the SDK contract.
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
        $metadata = $directory->getModelMetadata($modelId); // throws on unknown id
        return new AiProvider_TextModel(
            $modelConfig ?? new ModelConfig(),
            self::metadata(),
            $metadata
        );
    }
}

// The text model. generateTextResult() is the whole bridge: guard the request
// context, flatten the prompt, call the engine's synchronous bridge, and wrap
// the reply in the SDK result envelope. Nothing engine-specific lives here —
// the engine work is entirely behind sole_engine_llm_sync().
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

    /**
     * @param list<Message> $prompt
     */
    public function generateTextResult(array $prompt): GenerativeAiResult {
        // Whether a synchronous AI call may run here is the site owner's call,
        // not ours: WordPress's provider model leaves authorization to the
        // caller (no bundled provider gates by request context — verified
        // against WP 7 core). By default every context is permitted, including
        // front-end renders. A site owner who turns the front-end toggle OFF
        // restricts the blocking call to contexts where no visitor is awaiting a
        // render — admin, REST, cron, or AJAX — and front-end requests then fail
        // fast with a clean exception (→ WP_Error) instead of a blocking wait.
        if (!self::isBlockingContextAllowed()) {
            throw new \RuntimeException("sole-engine: synchronous AI generation is not permitted on front-end page requests.");
        }

        // flattenPrompt runs FIRST so that an unsupported part (a file, a tool
        // call) still throws its own accurate error rather than being reported
        // as an empty prompt.
        $systemInstruction = $this->config->getSystemInstruction();
        $promptText = self::flattenPrompt($prompt, $systemInstruction);

        // A structurally empty prompt would still bill an /v1/tasks/execute call
        // for a no-op. Refuse it before spending a credit.
        //
        // The test is on the CONTENT, not on the flattened string. Flattening
        // prefixes every part with its role, so a message carrying an empty text
        // part flattens to "User: " — which trim() leaves non-empty, so a
        // string-emptiness check passed and the caller was billed for a prompt
        // with nothing in it. The guard read as protection while protecting
        // nothing, in the billing path, and what it cost was the user's credit.
        if (!self::hasAnswerableContent($prompt, $systemInstruction)) {
            throw new \RuntimeException("sole-engine: empty_prompt");
        }
        // ModelConfig knobs other than systemInstruction are not honoured in v1.
        // This is honest by contract, not a silent drop: the model advertises
        // text input, text output, and systemInstruction, and honours all three;
        // a caller setting e.g. temperature is using an unadvertised knob. A
        // per-request log/diagnostics note was considered and rejected — it
        // would fire on most calls (temperature is commonly set) and flood the
        // bounded diagnostics ring.

        // Deliberately UNQUALIFIED: this adapter and its @internal accessor both
        // live in SoleEngineWP. A leading "\" would wrongly request the forbidden,
        // nonexistent global accessor on this synchronous foreign-caller edge.
        $reply = sole_engine_llm_sync()->generate(self::CALLER_ID, "chat", $promptText);
        if (!$reply->isSuccessful()) {
            // Surface the engine's real error code so the WP boundary yields a
            // meaningful WP_Error (callers can read it from the exception).
            throw new \RuntimeException("sole-engine: " . ($reply->getError() ?? "provider_error"));
        }

        try {
            return self::buildResult($reply->getReply() ?? "", $this->providerMetadata, $this->modelMetadata);
        } catch (\Throwable $e) {
            // SDK result assembly must never fatal WordPress — if a shape ever
            // drifts, surface a clean exception instead of a white screen.
            throw new \RuntimeException("sole-engine: result_assembly_failed: " . $e->getMessage());
        }
    }

    /**
     * Flatten WordPress's message list into the engine's single-string chat
     * payload, folding any system instruction in as a leading directive. The
     * engine's chat task ignores structured options, so folding is the only way
     * to honour the system instruction. Unsupported parts (files, tool calls)
     * throw rather than being silently dropped.
     *
     * @param list<Message> $messages
     */
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
                    // Files and tool-call parts are not supported in v1.
                    throw new \RuntimeException("sole-engine: unsupported non-text message part (files and tool calls are not supported).");
                }
                $lines[] = $role . ": " . $text;
            }
        }
        return \implode("\n\n", $lines);
    }

    /**
     * Is there anything here a model could answer?
     *
     * A prompt is empty when nothing answerable survives — not when the
     * flattened string happens to be zero-length. Those are different questions,
     * and conflating them is what let a message carrying an empty text part bill
     * a call: flattening had already added "User: " to it.
     *
     * Deliberately NOT implemented by making flattenPrompt skip empty parts.
     * That would make the symptom disappear while turning an honest contract
     * into a silent one — flattenPrompt promises that unsupported input throws
     * rather than being dropped, and quietly discarding parts is the antipattern
     * this codebase treats as worst. Flattening stays faithful to what it was
     * given; emptiness is asked as its own question, here.
     *
     * An empty part ALONGSIDE a real one is a real prompt and must still bill:
     * refusing work a caller legitimately asked for would be a defect in the
     * other direction, and a worse one, because the caller sees a failure
     * instead of an answer.
     *
     * A non-blank system instruction counts as content, for the same reason: a
     * caller who supplied a directive has asked for something, and a model can
     * act on it. Whitespace-only text counts as nothing, since nothing survives
     * trimming for a model to answer.
     *
     * Validation is not this method's job — flattenPrompt owns it and has
     * already run. Shapes it would have rejected are simply not counted as
     * content here.
     *
     * @param list<Message> $messages
     */
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

    /**
     * Assemble the SDK result envelope from the engine's reply text. Token
     * counts are NOT available from the engine (it returns credits only, which
     * Engine_LlmSync records to diagnostics), so TokenUsage is zeros and no
     * usage is attached to the result — the WP surface reports no token counts.
     */
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
        // Owner toggle (default on): when front-end renders are allowed, no
        // context restriction applies. Read through the config store so the
        // setting is the single source of truth. A store that predates this
        // capability (no Config_WpAiInterface) keeps the permissive default.
        $config = (new Config_Factory())->makeStore();
        if (!$config instanceof Config_WpAiInterface || $config->isFrontEndRenderAllowed()) {
            return true;
        }
        // Toggle off: permit only contexts where no visitor is awaiting a
        // render — admin, cron, AJAX, or REST — and refuse front-end requests.
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
