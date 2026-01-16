<?php

/**
 * @file
 * OpenAI adapter that wraps the existing OpenAIApi logic.
 *
 * This adapter implements AIClientInterface using the OpenAI PHP SDK,
 * preserving all existing functionality including Responses API support.
 */

// Prefer Composer Manager's autoloader when available; fall back to module vendor.
$__openai_sdk_source =& backdrop_static('openai_sdk_source');
if (module_exists('composer_manager')) {
  if (function_exists('composer_manager_register_autoloader')) {
    composer_manager_register_autoloader();
  }
  $__openai_sdk_source = 'composer_manager';
}
else {
  $autoload = BACKDROP_ROOT . '/' . backdrop_get_path('module', 'openai') . '/vendor/autoload.php';
  if (file_exists($autoload)) {
    require_once $autoload;
    $__openai_sdk_source = 'module_vendor';
  }
  else {
    $__openai_sdk_source = $__openai_sdk_source ?: 'unknown';
  }
}

use OpenAI\Client as OpenAIClient;
use OpenAI\Exceptions\TransporterException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OpenAIAdapter implements AIClientInterface {

  /** @var OpenAIClient */
  protected $client;

  /** @var OpenAIApi Reference to parent API for helper methods */
  protected $api;

  /**
   * Constructor.
   *
   * @param string $apiKey
   *   OpenAI API key.
   * @param OpenAIApi|null $api
   *   Optional reference to parent API for accessing helper methods.
   */
  public function __construct($apiKey, ?OpenAIApi $api = NULL) {
    // Trim the API key to remove any whitespace/newlines that may have been stored
    $apiKey = trim($apiKey);
    $this->client = \OpenAI::client($apiKey);
    $this->api = $api;
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    $models = [];
    try {
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
      }
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'Failed to fetch models: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
    }
    return $models;
  }

  /**
   * {@inheritdoc}
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    try {
      $base = [
        'model' => $model,
        'prompt' => trim($prompt),
        'temperature' => (float) $temperature,
      ];

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
      }

      $response = $this->client->completions()->create($base)->toArray();
      return trim($response['choices'][0]['text'] ?? '');
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'Completions error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE) {
    try {
      // Use Responses API for gpt-5* and o-series models
      if ($this->modelUsesResponsesApi($model)) {
        return $this->chatWithResponsesApi($model, $messages, $temperature, $max_tokens);
      }

      // Standard Chat Completions API
      $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => (float) $temperature,
      ];

      if ((int) $max_tokens > 0) {
        $payload['max_tokens'] = (int) $max_tokens;
      }

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
      }

      $result = $this->client->chat()->create($payload)->toArray();
      return trim($result['choices'][0]['message']['content'] ?? '');
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'Chat error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
  }

  /**
   * Handle chat using OpenAI Responses API.
   */
  protected function chatWithResponsesApi(string $model, array $messages, $temperature, $max_tokens) {
    [$inputItems, $instructions] = $this->toResponsesItemsAndInstructions($messages);

    $payload = [
      'model' => $model,
      'input' => $inputItems,
      'instructions' => $instructions,
      'reasoning' => ['effort' => 'low'],
    ];

    // Apply max_output_tokens for Responses API
    $minTokens = 512;
    $finalTokens = max((int) $max_tokens, $minTokens);
    $payload['max_output_tokens'] = $finalTokens;

    // Some models ignore temperature
    if (!$this->modelIgnoresTemperature($model)) {
      $payload['temperature'] = (float) $temperature;
    }

    $payload = $this->sanitizeResponsesPayload($payload);

    $result = $this->client->responses()->create($payload)->toArray();

    // Handle incomplete responses
    if (($result['status'] ?? '') === 'incomplete') {
      $reason = $result['incomplete_details']['reason'] ?? 'unknown';
      watchdog('openai', 'Responses incomplete (@reason), retrying with higher cap', ['@reason' => $reason], WATCHDOG_WARNING);

      if ($reason === 'max_output_tokens') {
        $payload['max_output_tokens'] = max(1024, $finalTokens * 2);
        $payload['reasoning'] = ['effort' => 'low'];

        $retry = $this->client->responses()->create($payload)->toArray();
        if (!empty($retry['output_text'])) {
          return trim($retry['output_text']);
        }
        return $this->collapseOutputParts($retry);
      }

      return $this->collapseOutputParts($result);
    }

    if (!empty($result['output_text'])) {
      return trim($result['output_text']);
    }
    return $this->collapseOutputParts($result);
  }

  /**
   * {@inheritdoc}
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    try {
      $parameters = [
        'prompt' => $prompt,
        'model' => $model,
        'size' => $size,
      ];

      if (strpos($model, 'dall-e') === 0) {
        $parameters['response_format'] = $response_format;
        if ($model === 'dall-e-3') {
          $parameters['quality'] = $quality;
          $parameters['style'] = $style;
        }
      } elseif (strpos($model, 'gpt-image') === 0) {
        $parameters['quality'] = $quality;
        if (!empty($output_format)) {
          $parameters['output_format'] = $output_format;
        }
      }

      return $this->client->images()->create($parameters)->toArray();
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'Images error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    try {
      return $this->client->audio()->speech([
        'model' => $model,
        'voice' => $voice,
        'input' => $input,
        'response_format' => $response_format,
      ]);
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'TTS error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    if (!in_array($task, ['transcribe', 'translate'], TRUE)) {
      throw new \InvalidArgumentException('Task must be transcribe or translate.');
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
      watchdog('openai', 'STT error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    try {
      return $this->client->moderations()->create([
        'model' => $model,
        'input' => trim($input),
      ])->toArray();
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'Moderation error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    try {
      $response = $this->client->embeddings()->create([
        'model' => $model,
        'input' => $input,
      ])->toArray();

      return $response['data'][0]['embedding'] ?? [];
    } catch (TransporterException | \Exception $e) {
      watchdog('openai', 'Embedding error: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return [];
    }
  }

  // Helper methods for Responses API

  protected function modelUsesResponsesApi(string $model): bool {
    return (bool) preg_match('/^(gpt-5|o[0-9])/i', $model);
  }

  protected function modelIgnoresTemperature(string $model): bool {
    return (bool) preg_match('/^o[0-9]/i', $model);
  }

  protected function toResponsesItemsAndInstructions(array $messages): array {
    $inputItems = [];
    $instructions = '';

    foreach ($messages as $message) {
      $role = strtolower($message['role'] ?? 'user');
      $content = $message['content'] ?? '';

      if ($role === 'system') {
        if (is_string($content)) {
          $instructions .= trim($content) . "\n";
        } elseif (is_array($content)) {
          foreach ($content as $part) {
            if (is_string($part)) {
              $instructions .= trim($part) . "\n";
            } elseif (is_array($part) && isset($part['text'])) {
              $instructions .= trim((string) $part['text']) . "\n";
            }
          }
        }
        continue;
      }

      $contentParts = [];
      if (is_string($content)) {
        $contentParts[] = [
          'type' => ($role === 'assistant') ? 'output_text' : 'input_text',
          'text' => $content,
        ];
      } elseif (is_array($content)) {
        foreach ($content as $item) {
          if (is_string($item)) {
            $contentParts[] = [
              'type' => ($role === 'assistant') ? 'output_text' : 'input_text',
              'text' => $item,
            ];
          } elseif (is_array($item)) {
            $type = $item['type'] ?? '';
            if ($type === 'text' && isset($item['text'])) {
              $contentParts[] = [
                'type' => ($role === 'assistant') ? 'output_text' : 'input_text',
                'text' => (string) $item['text'],
              ];
            } elseif ($type === 'image_url' && isset($item['image_url']) && $role !== 'assistant') {
              $contentParts[] = ['type' => 'input_image', 'image_url' => $item['image_url']];
            }
          }
        }
      }

      if (!$contentParts) {
        $contentParts[] = [
          'type' => ($role === 'assistant') ? 'output_text' : 'input_text',
          'text' => '',
        ];
      }

      $inputItems[] = ['role' => $role, 'content' => $contentParts];
    }

    return [$inputItems, trim($instructions)];
  }

  protected function sanitizeResponsesPayload(array $payload): array {
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

        $type = $part['type'] ?? NULL;

        if ($role === 'assistant') {
          if ($type === 'output_text' && isset($part['text'])) {
            $normalizedParts[] = ['type' => 'output_text', 'text' => (string) $part['text']];
          } elseif (in_array($type, ['input_text', 'text']) && isset($part['text'])) {
            $normalizedParts[] = ['type' => 'output_text', 'text' => (string) $part['text']];
          }
        } else {
          if (in_array($type, ['input_text', 'text']) && isset($part['text'])) {
            $normalizedParts[] = ['type' => 'input_text', 'text' => (string) $part['text']];
          } elseif (in_array($type, ['image_url', 'input_image']) && isset($part['image_url'])) {
            $normalizedParts[] = ['type' => 'input_image', 'image_url' => $part['image_url']];
          }
        }
      }

      $inputItem['content'] = $normalizedParts ?: [
        ['type' => $role === 'assistant' ? 'output_text' : 'input_text', 'text' => '']
      ];
    }

    return $payload;
  }

  protected function collapseOutputParts(array $result): string {
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

}

