<?php

require_once BACKDROP_ROOT . '/' . backdrop_get_path('module', 'openai') . '/vendor/autoload.php';

use OpenAI\Client as OpenAIClient;
use OpenAI\Exceptions\TransporterException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpenAIApi {

  /** @var OpenAIClient */
  protected $client;

  /** @var \BackdropCacheInterface */
  protected $cache;

  public function __construct($apiKey) {
    // Initialize the cache bin used by this module.
    $this->cache = cache('data');
    // Initialize the OpenAI client via the SDK factory (no custom transporter).
    $this->client = $this->initializeClient($apiKey);
  }

  private function initializeClient($apiKey) {
    return \OpenAI::client($apiKey);
  }

  // -----------------
  // Models
  // -----------------

  public function getModels(): array {
    $models = [];

    $cache_data = $this->cache->get('openai_models');
    if (!empty($cache_data)) {
      return $cache_data->data;
    }

    $list = $this->client->models()->list()->toArray();
    foreach ($list['data'] as $model) {
      if (($model['owned_by'] ?? '') === 'openai-dev') {
        continue;
      }

      if (!preg_match('/^(gpt|text|tts|whisper|dall-e|o1|o2|o3|o4|o5|.*moderation)/i', $model['id'])) {
        continue;
      }

      // Skip unused, hidden, or deprecated models.
      if (preg_match('/(search|similarity|edit|1p|instruct)/i', $model['id'])) {
        continue;
      }

      $models[$model['id']] = $model['id'];
    }

    if (!empty($models)) {
      asort($models);
      $this->cache->set('openai_models', $models);
    }
    return $models;
  }

  /**
   * Filter specific models from the list of models by prefix.
   */
  public function filterModels(array $model_type): array {
    $models = [];
    $types = implode('|', $model_type);
    foreach ($this->getModels() as $id => $model) {
      if (preg_match("/^({$types})/i", $model)) {
        $models[$id] = $model;
      }
    }
    return $models;
  }

  // -----------------
  // Text (legacy Completions)
  // -----------------

  /**
   * Legacy Completions API helper.
   *
   * @return string|\stdClass
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    try {
      $normalizedTemp = $this->normalizeTemperature($model, $temperature);
      $base = [
        'model' => $model,
        'prompt' => trim($prompt),
        'temperature' => (float) $normalizedTemp,
      ];
      // Completions uses max_tokens (not max_output_tokens).
      if ((int) $max_tokens > 0) {
        $base['max_tokens'] = (int) $max_tokens;
      }

      if ($stream_response) {
        $stream = $this->client->completions()->createStreamed($base);

        return new StreamedResponse(function () use ($stream) {
          foreach ($stream as $data) {
            echo $data->choices[0]->delta->content;
            @ob_flush(); @flush();
          }
        }, 200, [
          'Cache-Control' => 'no-cache, must-revalidate',
          'Content-Type' => 'text/event-stream',
          'X-Accel-Buffering' => 'no',
        ]);
      } else {
        $response = $this->client->completions()->create($base)->toArray();
        return trim($response['choices'][0]['text'] ?? '');
      }
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'There was an issue obtaining a response from OpenAI completions. The error was @error.', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
  }

  // -----------------
  // Chat (Chat Completions OR Responses)
  // -----------------

  /**
   * Chat endpoint for all models, auto-selecting the right API.
   *
   * @return string|\stdClass
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE) {
    try {
      $normalizedTemp = $this->normalizeTemperature($model, $temperature);

      if ($this->modelUsesResponsesApi($model)) {
        // ----- Responses API path for GPT-5 / o-series -----
        [$inputItems, $instructions] = $this->toResponsesItemsAndInstructions($messages);

        $payload = [
          'model' => $model,
          'input' => $inputItems,
          // System prompt maps to 'instructions'
          'instructions' => $instructions,
          // Reasoning effort can help reduce token burn
          'reasoning' => ['effort' => 'low'],
        ];
        $payload = $this->applyMaxTokens($payload, $max_tokens, $model);
        if (!$this->modelIgnoresTemperature($model)) {
          $payload['temperature'] = (float) $normalizedTemp;
        }

        // Final safety pass: ensure assistant parts aren't input_text.
        $payload = $this->sanitizeResponsesPayload($payload);

        // Only log debug payloads if error reporting is verbose (development mode).
        if (config_get('system.core', 'error_level') === 'verbose') {
          watchdog('openai', 'Responses API payload for @model: @payload', [
            '@model' => $model,
            '@payload' => json_encode($payload, JSON_PRETTY_PRINT),
          ], WATCHDOG_DEBUG);
        }

        $result = $this->client->responses()->create($payload)->toArray();

        // If we hit the cap, auto-retry once with higher cap & lower effort.
        if (($result['status'] ?? '') === 'incomplete') {
          $reason = $result['incomplete_details']['reason'] ?? 'unknown';
          watchdog('openai', 'Responses run incomplete for @model (reason=@reason, max_output_tokens=@mot, output_tokens=@ot).',
            [
              '@model' => $model,
              '@reason' => $reason,
              '@mot' => $payload['max_output_tokens'] ?? 'n/a',
              '@ot' => $result['usage']['output_tokens'] ?? 'n/a',
            ],
            WATCHDOG_WARNING
          );

          if ($reason === 'max_output_tokens') {
            $payload['max_output_tokens'] = max(1024, (int) ($payload['max_output_tokens'] ?? 128) * 2);
            $payload['reasoning'] = ['effort' => 'low'];

            $retry = $this->client->responses()->create($payload)->toArray();
            if (!empty($retry['output_text'])) {
              return trim($retry['output_text']);
            }
            $stitchedRetry = $this->collapseOutputParts($retry);
            if ($stitchedRetry !== '') {
              return $stitchedRetry;
            }
          }

          // Return whatever we can from the incomplete run.
          $partial = $this->collapseOutputParts($result);
          if ($partial !== '') {
            return $partial;
          }
        }

        // Normal success path
        if (!empty($result['output_text'])) {
          return trim($result['output_text']);
        }
        return $this->collapseOutputParts($result);
      }

      // ----- Classic Chat Completions path -----
      $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => (float) $normalizedTemp,
      ];
      // Chat Completions uses max_tokens.
      if ((int) $max_tokens > 0) {
        $payload['max_tokens'] = (int) $max_tokens;
      }

      watchdog('openai', 'Chat API payload for @model: @payload', [
        '@model' => $model,
        '@payload' => json_encode($payload, JSON_PRETTY_PRINT),
      ], WATCHDOG_DEBUG);

      if ($stream_response) {
        $stream = $this->client->chat()->createStreamed($payload);
        return new StreamedResponse(function () use ($stream) {
          foreach ($stream as $data) {
            echo $data->choices[0]->delta->content;
            @ob_flush(); @flush();
          }
        }, 200, [
          'Cache-Control' => 'no-cache, must-revalidate',
          'Content-Type' => 'text/event-stream',
          'X-Accel-Buffering' => 'no',
        ]);
      } else {
        $result = $this->client->chat()->create($payload)->toArray();

        watchdog('openai', 'Chat API response for @model: @response', [
          '@model' => $model,
          '@response' => json_encode($result, JSON_PRETTY_PRINT),
        ], WATCHDOG_DEBUG);

        return trim($result['choices'][0]['message']['content'] ?? '');
      }
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'There was an issue obtaining a response from OpenAI chat. The error was @error.', [
        '@error' => $e->getMessage()
      ], WATCHDOG_ERROR);
      return '';
    }
  }

  // -----------------
  // Images
  // -----------------

  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = null) {
    try {
      $parameters = [
        'prompt' => $prompt,
        'model' => $model,
        'size' => $size,
      ];

      // Only add response_format for DALL-E models, not for gpt-image models
      if (strpos($model, 'dall-e') === 0) {
        $parameters['response_format'] = $response_format;

        if ($model === 'dall-e-3') {
          $parameters['quality'] = $quality;
          $parameters['style'] = $style;
        }
      } elseif (strpos($model, 'gpt-image') === 0) {
        // gpt-image models use different parameters
        $parameters['quality'] = $quality;
        if (!empty($output_format)) {
          $parameters['output_format'] = $output_format;
        }
      }

      $response = $this->client->images()->create($parameters)->toArray();
      
      // Always return the full response array - let the calling code handle processing
      return $response;
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'There was an issue obtaining a response from OpenAI Images. The error was @error.', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
  }

  // -----------------
  // Audio
  // -----------------

  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    try {
      return $this->client->audio()->speech([
        'model' => $model,
        'voice' => $voice,
        'input' => $input,
        'response_format' => $response_format,
      ]);
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'There was an issue obtaining a response from OpenAI textToSpeech. The error was @error.', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
  }

  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    if (!in_array($task, ['transcribe', 'translate'], TRUE)) {
      throw new \InvalidArgumentException('The $task parameter must be one of transcribe or translate.');
    }

    try {
      $response = $this->client->audio()->$task([
        'model' => $model,
        'file' => fopen($file, 'r'),
        'temperature' => (float) $temperature,
        'response_format' => $response_format,
      ])->toArray();

      return $response['text'] ?? '';
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'There was an issue obtaining a response from OpenAI speechToText. The error was @error.', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
  }

  // -----------------
  // Moderation / Embeddings
  // -----------------

  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    try {
      return $this->client->moderations()->create([
        'model' => $model,
        'input' => trim($input),
      ])->toArray();
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'There was an issue obtaining a response from OpenAI moderation. The error was @error.', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return [];
    }
  }

  public function embedding(string $input, string $model): array {
    if (empty($model)) {
      throw new \InvalidArgumentException('A model must be provided for generating embeddings.');
    }

    try {
      $response = $this->client->embeddings()->create([
        'model' => $model,
        'input' => $input,
      ])->toArray();

      return $response['data'][0]['embedding'] ?? [];
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'There was an issue obtaining a response from OpenAI embedding. The error was @error.', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return [];
    }
  }

  // -----------------
  // Vision helper
  // -----------------

  /**
   * Describe an image using Chat (kept for compatibility with GPT-4o etc.).
   */
  public function describeImage(string $imageUrl, bool $sendImageData = TRUE): string {
    $config = config('openai_alt.settings');
    $describePrompt = $config->get('prompt');
    $model = $config->get('model');

    if (empty($describePrompt)) {
      watchdog('openai_alt', 'The prompt configuration is missing or empty.', [], WATCHDOG_ERROR);
      return '';
    }

    if ($sendImageData) {
      $data = @file_get_contents($imageUrl);
      if ($data !== FALSE) {
        $imageUrl = 'data:image/jpeg;base64,' . base64_encode($data);
      }
    }

    try {
      $payload = [
        'model' => $model,
        'messages' => [
          [
            'role'    => 'user',
            'content' => [
              ['type' => 'text', 'text' => $describePrompt],
              ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']],
            ],
          ],
        ],
      ];
      // Keep a small cap for the caption; Chat Completions expects max_tokens.
      $payload['max_tokens'] = 300;

      $result = $this->client->chat()->create($payload)->toArray();
      return trim($result['choices'][0]['message']['content'] ?? '');
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'Error communicating with OpenAI: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
  }

  // -----------------
  // Helpers
  // -----------------

  /**
   * Map/insert correct token parameter and apply safe floors for reasoning models.
   */
  private function applyMaxTokens(array $payload, $max_tokens, string $model): array {
    $requestedTokens    = (int) $max_tokens;
    $minReasoningFloor  = $this->minCapForModel($model);

    // If caller provided a value, enforce the floor for reasoning models.
    $finalMaxTokens = $requestedTokens > 0
      ? max($requestedTokens, $minReasoningFloor)
      : $minReasoningFloor;

    if ($this->modelUsesResponsesApi($model)) {
      $payload['max_output_tokens'] = $finalMaxTokens;
    } else {
      $payload['max_tokens'] = $finalMaxTokens;
    }

    return $payload;
  }

  /**
   * Reasonable token floors; GPT-5 / o-series need room for reasoning.
   */
  protected function minCapForModel(string $model): int {
    if ($this->modelUsesResponsesApi($model)) {
      return 512; // you can raise to 1024 if you prefer
    }
    return 128;
  }

  /** Public wrapper for minCapForModel method. */
  public function getMinCapForModel(string $model): int {
    return $this->minCapForModel($model);
  }

  /**
   * Normalize temperature. Force 1.0 for gpt-5 family.
   */
  private function normalizeTemperature(string $model, $temperature): float {
    $normalizedTemperature = is_numeric($temperature) ? (float) $temperature : 1.0;
    if ($normalizedTemperature < 0.0) $normalizedTemperature = 0.0;
    if ($normalizedTemperature > 2.0) $normalizedTemperature = 2.0;

    if (preg_match('/^gpt-5/i', $model)) {
      if (abs($normalizedTemperature - 1.0) > 0.0001) {
        watchdog('openai', 'Temperature overridden to 1.0 for model @model (original @orig).', [
          '@model' => $model, '@orig' => $temperature
        ], WATCHDOG_DEBUG);
      }
      return 1.0;
    }

    return $normalizedTemperature;
  }


  /** gpt-5* and o*-series use the Responses API */
  private function modelUsesResponsesApi(string $model): bool {
    return (bool) preg_match('/^(gpt-5|o[0-9])/i', $model);
  }

  /** Some reasoning models ignore/forbid temperature */
  private function modelIgnoresTemperature(string $model): bool {
    return (bool) preg_match('/^o[0-9]/i', $model);
  }

  /**
   * Convert Chat-style messages → Responses API (input[], instructions).
   * - System message(s) are concatenated into 'instructions'.
   * - User/assistant turns are mapped with correct part types.
   */
  private function toResponsesItemsAndInstructions(array $messages): array {
    $inputItems  = [];
    $instructions = '';

    foreach ($messages as $message) {
      $role    = strtolower($message['role'] ?? 'user');
      $content = $message['content'] ?? '';

      if ($role === 'system') {
        if (is_string($content)) {
          $instructions .= trim($content) . "\n";
        } elseif (is_array($content)) {
          foreach ($content as $systemPart) {
            if (is_string($systemPart)) {
              $instructions .= trim($systemPart) . "\n";
            } elseif (is_array($systemPart) && isset($systemPart['text'])) {
              $instructions .= trim((string) $systemPart['text']) . "\n";
            }
          }
        }
        continue;
      }

      $contentParts = [];
      $appendTextPart = function (string $text) use (&$contentParts, $role) {
        $contentParts[] = [
          'type' => ($role === 'assistant') ? 'output_text' : 'input_text',
          'text' => $text,
        ];
      };

      if (is_string($content)) {
        $appendTextPart($content);
      } elseif (is_array($content)) {
        foreach ($content as $contentItem) {
          if (is_string($contentItem)) {
            $appendTextPart($contentItem);
          } elseif (is_array($contentItem)) {
            $type = $contentItem['type'] ?? '';
            if ($type === 'text' && isset($contentItem['text'])) {
              $appendTextPart((string) $contentItem['text']);
            } elseif ($type === 'image_url' && isset($contentItem['image_url'])) {
              if ($role !== 'assistant') {
                $contentParts[] = ['type' => 'input_image', 'image_url' => $contentItem['image_url']];
              }
            } elseif (($type === 'input_text' || $type === 'output_text') && isset($contentItem['text'])) {
              $contentParts[] = [
                'type' => ($role === 'assistant') ? 'output_text' : 'input_text',
                'text' => (string) $contentItem['text'],
              ];
            }
          }
        }
      }

      if (!$contentParts) {
        $appendTextPart('');
      }

      $inputItems[] = ['role' => $role, 'content' => $contentParts];
    }

    return [$inputItems, trim($instructions)];
  }

  /**
   * Ensure assistant parts never contain input_text; fix legacy shapes.
   */
  private function sanitizeResponsesPayload(array $payload): array {
    if (empty($payload['input']) || !is_array($payload['input'])) {
      return $payload;
    }

    foreach ($payload['input'] as &$inputItem) {
      $role = strtolower($inputItem['role'] ?? 'user');
      $content = $inputItem['content'] ?? [];
      if (!is_array($content)) {
        $content = [];
      }

      $normalizedParts = [];
      foreach ($content as $part) {
        if (is_string($part)) {
          $part = [
            'type' => ($role === 'assistant') ? 'output_text' : 'input_text',
            'text' => $part
          ];
        }
        if (!is_array($part)) {
          continue;
        }

        $type = $part['type'] ?? null;

        if ($role === 'assistant') {
          // Assistant may emit only output_text or refusal.
          if ($type === 'output_text' && isset($part['text'])) {
            $normalizedParts[] = ['type' => 'output_text', 'text' => (string) $part['text']];
          } elseif ($type === 'refusal' && isset($part['text'])) {
            $normalizedParts[] = ['type' => 'refusal', 'text' => (string) $part['text']];
          } elseif ($type === 'input_text' && isset($part['text'])) {
            $normalizedParts[] = ['type' => 'output_text', 'text' => (string) $part['text']];
          } elseif ($type === 'text' && isset($part['text'])) {
            $normalizedParts[] = ['type' => 'output_text', 'text' => (string) $part['text']];
          }
          // Drop other types for assistant.
        } else {
          // Non-assistant: allow input_text / input_image; normalize legacy shapes.
          if ($type === 'input_text' && isset($part['text'])) {
            $normalizedParts[] = ['type' => 'input_text', 'text' => (string) $part['text']];
          } elseif ($type === 'text' && isset($part['text'])) {
            $normalizedParts[] = ['type' => 'input_text', 'text' => (string) $part['text']];
          } elseif ($type === 'image_url' && isset($part['image_url'])) {
            $normalizedParts[] = ['type' => 'input_image', 'image_url' => $part['image_url']];
          } elseif ($type === 'input_image' && isset($part['image_url'])) {
            $normalizedParts[] = $part;
          }
        }
      }

      $inputItem['content'] = $normalizedParts ?: [
        ['type' => $role === 'assistant' ? 'output_text' : 'input_text', 'text' => '']
      ];
    }

    return $payload;
  }

  /**
   * Best-effort extraction of text from a Responses API result.
   */
  private function collapseOutputParts(array $result): string {
    if (!empty($result['output_text'])) {
      return trim((string) $result['output_text']);
    }

    $outputText = '';

    if (!empty($result['output']) && is_array($result['output'])) {
      foreach ($result['output'] as $outputItem) {
        if (!empty($outputItem['content']) && is_array($outputItem['content'])) {
          foreach ($outputItem['content'] as $contentPart) {
            if (isset($contentPart['text']) && is_string($contentPart['text'])) {
              $outputText .= $contentPart['text'];
            }
          }
        }
      }
    }

    return trim($outputText);
  }

  /**
   * Whitelist of model prefixes for validation.
   * This can be extended dynamically as new model families are released.
   */
  private static $modelWhitelist = [
    'gpt-4',
    'gpt-3.5',
    'gpt-5',
    'o1',
    'o3',
  ];

  /**
   * Get the whitelist of model prefixes.
   *
   * @return array
   *   The whitelist of model prefixes.
   */
  public static function getModelWhitelist() {
    return self::$modelWhitelist;
  }

  /**
   * Add a new model prefix to the whitelist.
   *
   * @param string $prefix
   *   The model prefix to add.
   */
  public static function addModelPrefix($prefix) {
    if (!in_array($prefix, self::$modelWhitelist)) {
      self::$modelWhitelist[] = $prefix;
    }
  }

}
