<?php

/**
 * @file
 * Interface for AI provider adapters.
 *
 * Adapters must implement the same method names/signatures that
 * `OpenAIApi` delegates to. This keeps all providers interchangeable.
 */

interface AIClientInterface {

  /**
   * List available models from the provider.
   *
   * @return array
   *   Array of model_id => label.
   */
  public function getModels(): array;

  /**
   * Text completion (legacy Completions API shape).
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE);

  /**
   * Chat completion (Chat Completions API or equivalent).
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE);

  /**
   * Image generation.
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL);

  /**
   * Text-to-speech.
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format);

  /**
   * Speech-to-text.
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json');

  /**
   * Moderation.
   *
   * @return array
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array;

  /**
   * Embedding.
   *
   * @return array
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array;
}
