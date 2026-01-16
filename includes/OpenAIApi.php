<?php

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
  } else {
    $__openai_sdk_source = $__openai_sdk_source ?: 'unknown';
  }
}

use GuzzleHttp\Client as GuzzleClient;
use OpenAI\Client as OpenAIClient;
use OpenAI\Transporters\HttpTransporter;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\Headers;
use OpenAI\ValueObjects\Transporter\QueryParams;
use OpenAI\ValueObjects\ApiKey;

class OpenAIApi {

  protected $client;

  protected $cache;

  protected $provider;

  /**
   * Helper function to log API requests and responses.
   */
  protected function log($operation, $model, $request_data, $response_data, $status, $duration, $error_message = NULL) {
    // Only log if enabled in settings.
    if (!config_get('openai.settings', 'openai_log_enabled')) {
      return;
    }

    try {
      global $user;

      // Determine which module called this. We try to find it from the backtrace.
      $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
      $module = 'openai';
      foreach ($backtrace as $step) {
        if (isset($step['function']) && str_starts_with($step['function'], 'openai_')) {
          // If it's a wrapper function in openai.module, keep looking for the caller.
          if ($step['function'] === 'openai_chat' || $step['function'] === 'openai_moderation') {
            continue;
          }
          // Extract module name from function name (e.g. openai_devel_generate_content -> openai_devel)
          if (preg_match('/^(openai_[a-z0-9_]+?)_/', $step['function'], $matches)) {
            $module = $matches[1];
            break;
          }
        }
      }

      $log = [
        'timestamp' => REQUEST_TIME,
        'uid' => $user->uid,
        'module' => $module,
        'operation' => $operation,
        'model' => $model,
        'provider' => $this->provider,
        'request_data' => is_string($request_data) ? $request_data : json_encode($request_data),
        'response_data' => is_string($response_data) ? $response_data : json_encode($response_data),
        'status' => $status ? 1 : 0,
        'duration' => (float) $duration,
        'error_message' => $error_message,
      ];

      db_insert('openai_log')
        ->fields($log)
        ->execute();
    }
    catch (\Exception $e) {
      // Don't let logging failures break the main functionality.
      watchdog('openai', 'Failed to log API request: @error', ['@error' => $e->getMessage()], WATCHDOG_DEBUG);
    }
  }

  /**
   * Constructor.
   *
   * @param string $apiKey
   *   The API key for authentication.
   * @param string $provider
   *   (Optional) The provider to use: 'openai', 'openrouter', 'ollama', 'litellm'.
   *   Defaults to 'openai'.
   */
  public function __construct($apiKey, $provider = 'openai') {
    // Initialize the cache
    $this->cache = cache('data'); // Using the 'data' cache bin
    $this->provider = $provider;

    // Initialize the client based on provider
    if ($provider !== 'openai') {
      $this->client = $this->initializeProviderClient($apiKey, $provider);
    }
    else {
      $this->client = $this->initializeClient($apiKey);
    }
  }

  private function initializeClient($apiKey) {
    $httpClient = new GuzzleClient();

    $baseUri = BaseUri::from('https://api.openai.com/v1');
    $apiKeyObject = ApiKey::from($apiKey);
    $headers = Headers::withAuthorization($apiKeyObject);
    $queryParams = QueryParams::create([]);
    $streamHandler = function($request) use ($httpClient) {
      return $httpClient->send($request, ['stream' => TRUE]);
    };

    $transporter = new HttpTransporter(
      $httpClient,
      $baseUri,
      $headers,
      $queryParams,
      $streamHandler
    );

    return new OpenAIClient($transporter);
  }

  /**
   * Initialize a client for a provider adapter.
   */
  private function initializeProviderClient($apiKey, $provider) {
    $providers = function_exists('openai_get_providers') ? openai_get_providers() : module_invoke_all('openai_provider_info');

    if (!isset($providers[$provider])) {
      throw new \Exception("Unknown provider: $provider");
    }

    $class = $providers[$provider]['class'];
    if (!class_exists($class)) {
      throw new \Exception("Provider class not found: $class");
    }

    // Instantiate adapter using reflection so we can support adapters with
    // different constructor signatures (some accept only $apiKey, others
    // accept ($apiKey, $wrapper)). This avoids argument count mismatches.
    try {
      $ref = new \ReflectionClass($class);
      $ctor = $ref->getConstructor();
      if ($ctor) {
        $param_count = $ctor->getNumberOfParameters();
        if ($param_count >= 2) {
          // Adapter wants the api key and the OpenAIApi wrapper.
          return $ref->newInstance($apiKey, $this);
        }
        elseif ($param_count === 1) {
          // Adapter only needs the api key.
          return $ref->newInstance($apiKey);
        }
        else {
          // No constructor args.
          return $ref->newInstance();
        }
      }
      else {
        return $ref->newInstance();
      }
    }
    catch (\Exception $e) {
      throw new \Exception("Failed to instantiate provider adapter $class: " . $e->getMessage());
    }
  }

  public function getModels(): array {
    // If using a provider adapter, delegate to it and ensure alphabetical order.
    if ($this->provider !== 'openai' && method_exists($this->client, 'getModels')) {
      $start_time = microtime(TRUE);
      try {
        $models = $this->client->getModels();
        if (is_array($models)) {
          asort($models);
        }
        return $models;
      } catch (\Exception $e) {
        throw $e;
      }
    }

    // Original OpenAI implementation
    $models = [];

    $cache_data = $this->cache->get('openai_models', $models);

    if (!empty($cache_data)) {
      return $cache_data->data;
    }

    $list = $this->client->models()->list()->toArray();

    foreach ($list['data'] as $model) {
      if ($model['owned_by'] === 'openai-dev') {
        continue;
      }

      if (!preg_match('/^(gpt|text|tts|whisper|dall-e|o1)/i',
        $model['id'])) {
        continue;
      }

      // Skip unused. hidden, or deprecated models.
      if (preg_match('/(search|similarity|edit|1p|instruct)/i', $model['id'])) {
        continue;
      }

      if (in_array($model['id'], ['tts-1-hd-1106', 'tts-1-1106'])) {
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
   * Filter specific models from the list of models.
   *
   * @param array $model_type
   *   The type of the model, gpt, text, dall, tts, whisper.
   *
   * @return array
   *   The filtered models.
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

  public function getChatModels(): array {
    // If using a provider adapter that has getModelsByCapability, use it
    if ($this->provider !== 'openai' && method_exists($this->client, 'getModelsByCapability')) {
      $models = $this->client->getModelsByCapability('text');
      if (is_array($models)) {
        asort($models);
      }
      return $models;
    }

    // For OpenAI, filter by known chat model patterns
    return $this->filterModels(['gpt', 'o1', 'o3', 'o4']);
  }

  /**
   * Get embedding models from the provider.
   *
   * @return array
   *   Array of embedding model IDs => names.
   */
  public function getEmbeddingModels(): array {
    // If using a provider adapter that has getEmbeddingModels, use it
    if ($this->provider !== 'openai' && method_exists($this->client, 'getEmbeddingModels')) {
      $models = $this->client->getEmbeddingModels();
      if (is_array($models)) {
        asort($models);
      }
      return $models;
    }

    // For OpenAI, filter models by embedding prefix
    return $this->filterModels(['text-embedding-']);
  }

  /**
   * Get moderation models from the provider.
   *
   * @return array
   *   Array of moderation model IDs => names.
   */
  public function getModerationModels(): array {
    // If using a provider adapter that has getModerationModels, use it
    if ($this->provider !== 'openai') {
      if (method_exists($this->client, 'getModerationModels')) {
        $models = $this->client->getModerationModels();
        if (is_array($models)) {
          asort($models);
        }
        return $models;
      }
      return [];
    }

    // For OpenAI, return the known moderation models
    // These are not listed in the /models endpoint but are documented
    $mods = [
      'omni-moderation-latest' => 'omni-moderation-latest',
      'omni-moderation-2024-09-26' => 'omni-moderation-2024-09-26',
      'text-moderation-latest' => 'text-moderation-latest',
      'text-moderation-stable' => 'text-moderation-stable',
      'text-moderation-007' => 'text-moderation-007',
    ];

    asort($mods);
    return $mods;
  }

  /**
   * Get image generation models from the provider.
   *
   * @return array
   *   Array of image model IDs => names.
   */
  public function getImageModels(): array {
    // If using a provider adapter that has getModelsByCapability, use it
    if ($this->provider !== 'openai' && method_exists($this->client, 'getModelsByCapability')) {
      $models = $this->client->getModelsByCapability('image');
      if (is_array($models)) {
        asort($models);
      }
      return $models;
    }

    // For OpenAI, we need to fetch from the API which models support image generation
    // The /models endpoint doesn't include capability info, so we rely on known model patterns
    $all_models = $this->getModels();
    $image_models = [];

    foreach ($all_models as $id => $name) {
      // Match known image generation model patterns
      if (preg_match('/^(dall-e|gpt-image)/i', $id)) {
        $image_models[$id] = $name;
      }
    }

    if (!empty($image_models)) {
      asort($image_models);
    }
    return $image_models;
  }

  public function getVisionModels(): array {
    // If using a provider adapter that has getModelsByCapability, use it
    if ($this->provider !== 'openai' && method_exists($this->client, 'getModelsByCapability')) {
      $models = $this->client->getModelsByCapability('vision');
      if (is_array($models)) {
        asort($models);
      }
      return $models;
    }

    // For OpenAI, filter by known vision model patterns
    // GPT-4o, GPT-4 Turbo, and vision-specific models support image input
    $models = $this->filterModels(['gpt-4o', 'gpt-4-turbo', 'gpt-4-vision', 'o1-', 'o3-']);
    asort($models);
    return $models;
  }

  /**
   * Return a ready to use answer from the completion endpoint.
   *
   * Note that the stream argument will not work in cases like Backdrop's Form API
   * AJAX responses at this time. It will however work in client side applications.
   *
   * @param string $model
   *   The model to use.
   * @param string $prompt
   *   The prompt to use.
   * @param $temperature
   *   The temperature setting.
   * @param $max_tokens
   *   The max tokens for the input and response.
   * @param bool $stream
   *   If the response should be streamed. Useful for dynamic typed output over JavaScript.
   *
   * @return string
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    $start_time = microtime(TRUE);
    $params = [
      'model' => $model,
      'prompt' => trim($prompt),
      'temperature' => (float) $temperature,
      'max_tokens' => (int) $max_tokens,
    ];

    // Delegate to provider if not OpenAI — wrap so we log adapter calls.
    if ($this->provider !== 'openai' && method_exists($this->client, 'completions')) {
      try {
        $res = $this->client->completions($model, $prompt, $temperature, $max_tokens, $stream_response);
        $duration = microtime(TRUE) - $start_time;
        $this->log('completions', $model, $params, $res, TRUE, $duration);
        return $res;
      }
      catch (\Exception $e) {
        $duration = microtime(TRUE) - $start_time;
        $this->log('completions', $model, $params, NULL, FALSE, $duration, $e->getMessage());
        throw $e;
      }
    }

    try {
      if ($stream_response) {
        $stream = $this->client->completions()->create(
          [
            'model' => $model,
            'prompt' => trim($prompt),
            'temperature' => (int) $temperature,
            'max_tokens' => (int) $max_tokens,
          ]
        );

        // Collect streamed chunks into a single string and return it. This
        // avoids requiring a StreamedResponse class in the runtime.
        $out = '';
        foreach ($stream as $data) {
          $chunk = '';
          // Support both object and array shapes
          if (is_object($data) && isset($data->choices[0]->delta->content)) {
            $chunk = $data->choices[0]->delta->content;
          }
          elseif (is_array($data) && isset($data['choices'][0]['delta']['content'])) {
            $chunk = $data['choices'][0]['delta']['content'];
          }
          $out .= $chunk;
        }
        // Log streamed completion
        $duration = microtime(TRUE) - $start_time;
        $this->log('completions', $model, $params, $out, TRUE, $duration);
        return $out;
      } else {
        $response = $this->client->completions()->create(
          [
            'model' => $model,
            'prompt' => trim($prompt),
            'temperature' => (int) $temperature,
            'max_tokens' => (int) $max_tokens,
          ],
        );

        $result = $response->toArray();
        // Log completion
        $duration = microtime(TRUE) - $start_time;
        $this->log('completions', $model, $params, $result, TRUE, $duration);
        return trim($result['choices'][0]['text']);
      }
    } catch (\Exception $e) {
      // Log failure
      $duration = microtime(TRUE) - $start_time;
      $this->log('completions', $model, $params, NULL, FALSE, $duration, $e->getMessage());
      watchdog('openai', 'There was an issue obtaining a response from OpenAI completions. The error was @error.', array('@error' => $e->getMessage()), WATCHDOG_ERROR);
      return '';
    }
  }

  /**
   * Return a ready to use answer from the chat endpoint.
   *
   * @param string $model
   *   The model to use.
   * @param array $messages
   *   The array of messages to send. Refer to the docs for the format of this array.
   * @param $temperature
   *   The temperature setting.
   * @param $max_tokens
   *   The max tokens for the input and response.
   * @param bool $stream_response
   *   If the response should be streamed. Useful for dynamic typed output over JavaScript.
   *
   * @return string
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    $start_time = microtime(TRUE);
    $params = [
      'model' => $model,
      'messages' => $messages,
      'temperature' => floatval($temperature),
      'max_tokens' => (int) $max_tokens,
    ];

    // Delegate to provider if not OpenAI
    if ($this->provider !== 'openai' && method_exists($this->client, 'chat')) {
      try {
        $result = $this->client->chat($model, $messages, $temperature, $max_tokens, $stream_response);
        $duration = microtime(TRUE) - $start_time;
        // The adapter might return a string or an object. log() handles both.
        $this->log('chat', $model, $params, $result, TRUE, $duration);
        return $result;
      }
      catch (\Exception $e) {
        $duration = microtime(TRUE) - $start_time;
        $this->log('chat', $model, $params, NULL, FALSE, $duration, $e->getMessage());
        throw $e;
      }
    }

    // Original OpenAI implementation
    try {
      if ($stream_response) {
        $stream = $this->client->chat()->createStreamed($params);

        // Collect streamed chat deltas into a string and return it.
        $out = '';
        foreach ($stream as $data) {
          $chunk = '';
          if (is_object($data) && isset($data->choices[0]->delta->content)) {
            $chunk = $data->choices[0]->delta->content;
          }
          elseif (is_array($data) && isset($data['choices'][0]['delta']['content'])) {
            $chunk = $data['choices'][0]['delta']['content'];
          }
          $out .= $chunk;
        }
        $duration = microtime(TRUE) - $start_time;
        $this->log('chat', $model, $params, $out, TRUE, $duration);
        return $out;
      }
      else {
        $response = $this->client->chat()->create($params);

        $result = $response->toArray();
        $content = trim($result['choices'][0]['message']['content']);
        $duration = microtime(TRUE) - $start_time;
        $this->log('chat', $model, $params, $result, TRUE, $duration);
        return $content;
      }
    } catch (\Exception $e) {
      $duration = microtime(TRUE) - $start_time;
      $this->log('chat', $model, $params, NULL, FALSE, $duration, $e->getMessage());
      watchdog('openai', 'There was an issue obtaining a response from OpenAI chat. The error was @error.', array('@error' => $e->getMessage()), WATCHDOG_ERROR);
      return '';
    }
  }

  /**
   * Generate an image.
   *
   * Simplified to work with all providers - just prompt and model.
   *
   * @param string $model
   *   The model to use.
   * @param string $prompt
   *   The prompt to use.
   * @param string $size
   *   The size image to generate (ignored for most providers).
   * @param string $response_format
   *   The response format (url or b64_json) - only used for OpenAI.
   * @param string $quality
   *   The quality of the image (standard or hd) - only used for DALL-E 3.
   * @param string $style
   *   The style of the image (natural or vivid) - only used for DALL-E 3.
   * @param string|null $output_format
   *   Optional output format for certain models (png, jpeg, webp).
   *
   * @return array
   *   Array with 'data' key containing array of image objects with 'url'.
   */
  public function images(string $model, string $prompt, string $size = '1024x1024', string $response_format = 'url', string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    // If provider adapter exists and is not the OpenAI provider, ask it to
    // generate images. For gpt-image* models, call with minimal args.
    if ($this->provider !== 'openai' && method_exists($this->client, 'images')) {
      $start_time = microtime(TRUE);
      try {
        if (preg_match('/^gpt-image/i', $model)) {
          $res = $this->client->images($model, $prompt);
        }
        else {
          // Pass full parameters for providers that expect them.
          $res = $this->client->images($model, $prompt, $size, $response_format, $quality, $style, $output_format);
        }

        $duration = microtime(TRUE) - $start_time;
        // Log adapter image call
        $this->log('images', $model, ['prompt' => $prompt, 'size' => $size, 'response_format' => $response_format], $res, TRUE, $duration);
      } catch (\Exception $e) {
        $duration = microtime(TRUE) - $start_time;
        watchdog('openai', 'Provider adapter images() threw an exception: @error', ['@error' => $e->getMessage()], WATCHDOG_WARNING);
        // Log the failure too
        $this->log('images', $model, ['prompt' => $prompt, 'size' => $size, 'response_format' => $response_format], NULL, FALSE, $duration, $e->getMessage());
        $res = ['data' => []];
      }

      // If adapter returned a usable image payload with URL, return it.
      if (is_array($res) && !empty($res['data']) && isset($res['data'][0]['url'])) {
        return $res;
      }

      // If adapter didn't return a URL, attempt a chat fallback only for
      // providers that expose chat() to try to surface an embedded URL.
      try {
        if (method_exists($this->client, 'chat')) {
          $messages = [[ 'role' => 'user', 'content' => $prompt ]];
          $chat_resp = $this->client->chat($model, $messages, 0.0, 0, FALSE);

          $hay = '';
          if (is_string($chat_resp)) {
            $hay = $chat_resp;
          } elseif (is_array($chat_resp)) {
            $hay = print_r($chat_resp, TRUE);
          } else {
            $hay = (string) $chat_resp;
          }

          if (preg_match('/https?:\/\/[^\s)"\]]+\.(png|jpg|jpeg|webp|gif)/i', $hay, $m)) {
            $out = ['data' => [['url' => $m[0]]]];
            $duration = microtime(TRUE) - $start_time;
            $this->log('images', $model, ['prompt' => $prompt], $out, TRUE, $duration);
            return $out;
          }
        }
      } catch (\Exception $e) {
        watchdog('openai', 'Provider chat fallback failed: @error', ['@error' => $e->getMessage()], WATCHDOG_WARNING);
      }

      return ['data' => []];
    }

    // OpenAI native implementation: build parameters conditionally.
    try {
      $parameters = [
        'prompt' => $prompt,
        'model' => $model,
        'n' => 1,
      ];

      // For gpt-image* models, do not include any additional parameters; send
      // only prompt and model (OpenAI may still accept size/response_format, but
      // some image endpoints behave differently). This reduces 'unknown
      // parameter' errors with proxies/adapters.
      if (!preg_match('/^gpt-image/i', $model)) {
        // DALL-E 3: include quality/style and response_format/size when provided.
        if (preg_match('/^dall-e-3/i', $model)) {
          if (!empty($size)) {
            $parameters['size'] = $size;
          }
          if (!empty($response_format)) {
            $parameters['response_format'] = $response_format;
          }
          if (!empty($quality)) {
            $parameters['quality'] = $quality;
          }
          if (!empty($style)) {
            $parameters['style'] = $style;
          }
        }
        elseif (preg_match('/^dall-e-2/i', $model)) {
          if (!empty($size)) {
            $parameters['size'] = $size;
          }
          if (!empty($response_format)) {
            $parameters['response_format'] = $response_format;
          }
        }
        else {
          // For other OpenAI image-capable models, include size/response_format if present.
          if (!empty($size)) {
            $parameters['size'] = $size;
          }
          if (!empty($response_format)) {
            $parameters['response_format'] = $response_format;
          }
        }
      } else {
        // For gpt-image models, include response_format only when explicitly
        // requested as 'b64_json' so callers can request base64 images.
        if (!empty($response_format) && $response_format === 'b64_json') {
          $parameters['response_format'] = $response_format;
        }
      }

      $start_time = microtime(TRUE);
      $response = $this->client->images()->create($parameters);
      $result = $response->toArray();

      $duration = microtime(TRUE) - $start_time;
      // Log OpenAI native images call
      $this->log('images', $model, $parameters, $result, TRUE, $duration);

      if (isset($result['data'])) {
        return $result;
      }

      return ['data' => []];
    } catch (\Exception $e) {
      // Some OpenAI image models (or SDK versions) will reject the
      // 'response_format' parameter (e.g. gpt-image variants). If that
      // happens, retry the request without the parameter so we can still
      // obtain a URL-based image response.
      $msg = $e->getMessage();
      if (!empty($parameters['response_format']) && stripos($msg, "Unknown parameter") !== FALSE) {
        watchdog('openai', 'OpenAI images endpoint rejected response_format; retrying without it. Original error: @error', ['@error' => $msg], WATCHDOG_WARNING);
        unset($parameters['response_format']);
        try {
          $start_time = microtime(TRUE);
          $response = $this->client->images()->create($parameters);
          $result = $response->toArray();
          $duration = microtime(TRUE) - $start_time;
          // Log the retry attempt as well
          $this->log('images', $model, $parameters, $result, TRUE, $duration);
          if (isset($result['data'])) {
            return $result;
          }
          return ['data' => []];
        } catch (\Exception $e2) {
          watchdog('openai', 'Retry without response_format also failed: @error', ['@error' => $e2->getMessage()], WATCHDOG_ERROR);
          return ['data' => []];
        }
      }

      // Log the original error for visibility.
      $this->log('images', $model, isset($parameters) ? $parameters : ['prompt' => $prompt], NULL, FALSE, 0.0, $msg);
      watchdog('openai', 'There was an issue obtaining a response from OpenAI Images. The error was @error.', ['@error' => $msg], WATCHDOG_ERROR);
      return ['data' => []];
    }
  }

  /**
   * Return a ready to use answer from the speech endpoint.
   *
   * @param string $model
   *   The model to use.
   * @param string $input
   *   The text input to convert.
   * @param string $voice
   *   The "voice" to use for the audio.
   * @param string $response_format
   *   The audio format to return.
   *
   * @return string
   *   The response from OpenAI.
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    $start_time = microtime(TRUE);
    try {
      $resp = $this->client->audio()->speech([
        'model' => $model,
        'voice' => $voice,
        'input' => $input,
        'response_format' => $response_format,
      ]);
      $duration = microtime(TRUE) - $start_time;
      $this->log('textToSpeech', $model, ['input' => $input, 'voice' => $voice, 'response_format' => $response_format], $resp, TRUE, $duration);
      return $resp;
    } catch (\Exception $e) {
      $duration = microtime(TRUE) - $start_time;
      $this->log('textToSpeech', $model, ['input' => $input, 'voice' => $voice], NULL, FALSE, $duration, $e->getMessage());
      watchdog('openai', 'There was an issue obtaining a response from OpenAI textToSpeech. The error was @error.', array('@error' => $e->getMessage()), WATCHDOG_ERROR);
      return '';
    }
  }

  /**
   * Return a ready to use transcription/translation from the speech endpoint.
   *
   * @param string $model
   *   The model to use.
   * @param string $file
   *   The absolute path to the audio file to convert.
   * @param string $task
   *   The type of conversion to perform, either transcript or translate.
   * @param string $response_format
   *   The format of the transcript output, in one of these options: json, text, srt, verbose_json, or vtt.
   *
   * @return string
   *   The response from OpenAI.
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    if (!in_array($task, ['transcribe', 'translate'])) {
      throw new \InvalidArgumentException('The $task parameter must be one of transcribe or translate.');
    }

    $start_time = microtime(TRUE);
    try {
      $response = $this->client->audio()->$task([
        'model' => $model,
        'file' => fopen($file, 'r'),
        'temperature' => (int) $temperature,
        'response_format' => $response_format,
      ]);

      $result = $response->toArray();
      $duration = microtime(TRUE) - $start_time;
      $this->log('speechToText', $model, ['file' => $file, 'task' => $task], $result, TRUE, $duration);
      return $result['text'];
    } catch (\Exception $e) {
      $duration = microtime(TRUE) - $start_time;
      $this->log('speechToText', $model, ['file' => $file, 'task' => $task], NULL, FALSE, $duration, $e->getMessage());
      watchdog('openai', 'There was an issue obtaining a response from OpenAI speechToText. The error was @error.', array('@error' => $e->getMessage()), WATCHDOG_ERROR);
      return '';
    }
  }

  /**
   * Determine if a piece of text violates any OpenAI usage policies.
   *
   * @param string $input
   *   The input to check.
   *
   * @return array
   *   The response from OpenAI moderation endpoint.
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    $start_time = microtime(TRUE);
    $params = [
      'input' => substr($input, 0, 200),
      'model' => $model,
    ];

    // Delegate to provider if not OpenAI
    if ($this->provider !== 'openai' && method_exists($this->client, 'moderation')) {
      try {
        $result = $this->client->moderation($input, $model);
        $duration = microtime(TRUE) - $start_time;
        $this->log('moderation', $model, $params, $result, TRUE, $duration);
        return $result;
      }
      catch (\Exception $e) {
        $duration = microtime(TRUE) - $start_time;
        $this->log('moderation', $model, $params, NULL, FALSE, $duration, $e->getMessage());
        throw $e;
      }
    }

    try {
      $response = $this->client->moderations()->create([
        'model' => $model,
        'input' => trim($input),
      ]);
      $result = $response->toArray();
      $duration = microtime(TRUE) - $start_time;
      $this->log('moderation', $model, $params, $result, TRUE, $duration);
      return $result;
    } catch (\Exception $e) {
      $duration = microtime(TRUE) - $start_time;
      $this->log('moderation', $model, $params, NULL, FALSE, $duration, $e->getMessage());
      watchdog('openai', 'There was an issue obtaining a response from OpenAI moderation. The error was @error.', array('@error' => $e->getMessage()), WATCHDOG_ERROR);
      return [];
    }
  }

  /**
   * Generate a text embedding from an input.
   *
   * @param string $input
   *   The input text to embed.
   * @param string $model
   *   The model to use for embedding.
   *
   * @return array
   *   The text embedding vector value from OpenAI.
   *
   * @throws \InvalidArgumentException
   *   Thrown if no model is provided.
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    if (empty($model)) {
      throw new \InvalidArgumentException('A model must be provided for generating embeddings.');
    }

    $start_time = microtime(TRUE);
    $params = ['input' => $input, 'model' => $model];

    // Delegate to provider if not OpenAI
    if ($this->provider !== 'openai' && method_exists($this->client, 'embedding')) {
      try {
        $res = $this->client->embedding($input, $model);
        $duration = microtime(TRUE) - $start_time;
        if ($log) {
          $this->log('embedding', $model, $params, $res, TRUE, $duration);
        }
        return $res;
      } catch (\Exception $e) {
        $duration = microtime(TRUE) - $start_time;
        if ($log) {
          $this->log('embedding', $model, $params, NULL, FALSE, $duration, $e->getMessage());
        }
        throw $e;
      }
    }

    // Original OpenAI implementation
    try {
      $response = $this->client->embeddings()->create([
        'model' => $model,  // Model is now strictly passed
        'input' => $input,
      ]);

      $result = $response->toArray();
      // Log embedding
      if ($log) {
        $duration = microtime(TRUE) - $start_time;
        $this->log('embedding', $model, $params, $result, TRUE, $duration);
      }
      return $result['data'][0]['embedding'];
    } catch (\Exception $e) {
      if ($log) {
        $duration = microtime(TRUE) - $start_time;
        $this->log('embedding', $model, $params, NULL, FALSE, $duration, $e->getMessage());
      }
      watchdog('openai', 'There was an issue obtaining a response from OpenAI embedding. The error was @error.', [
        '@error' => $e->getMessage(),
      ], WATCHDOG_ERROR);
      return [];
    }
  }

  /**
   * Describe an image using the OpenAI API.
   *
   * @param string $imageUrl
   *   The URL of the image to describe.
   * @param bool $sendImageData
   *   Whether to send the image data as base64.
   *
   * @return string
   *   The AI-generated alt text or an empty string on failure.
   */
  public function describeImage(string $imageUrl, bool $sendImageData = TRUE): string {
    // Load the prompt from the configuration.
    $config = config('openai_alt.settings');
    $describePrompt = $config->get('prompt');
    $model = $config->get('model');

    if (empty($describePrompt)) {
      watchdog('openai_alt', 'The prompt configuration is missing or empty.', [], WATCHDOG_ERROR);
      return '';
    }

    // If the configured model includes a provider prefix that matches the
    // current API instance's provider (e.g. model = "openrouter/anthropic/..."
    // and $this->provider === 'openrouter'), strip the leading provider so
    // adapters receive the short model identifier they typically expect
    // (e.g. "anthropic/..."). If the model includes a prefix for *another*
    // provider, leave it alone — the adapter may understand cross-provider
    // identifiers or the probe logic should have selected the correct API instance.
    if (!empty($model) && strpos($model, '/') !== FALSE) {
      $parts = explode('/', $model, 2);
      if ($parts[0] === $this->provider) {
        $model = $parts[1];
      }
    }

    if ($sendImageData) {
      $imageData = base64_encode(file_get_contents($imageUrl));
      $imageUrl = "data:image/jpeg;base64,{$imageData}";
    }

    // Log which provider and model we are using for image description to
    // help debugging provider mismatches and invalid model id errors. Log
    // the final model string that will be sent to the provider.
    $final_model_log = !empty($model) ? $model : '(none)';
    watchdog('openai_alt', 'Describing image with final model @model via provider @provider', [
      '@model' => $final_model_log,
      '@provider' => !empty($this->provider) ? $this->provider : 'openai',
    ], WATCHDOG_INFO);

    try {
      $messages = [
        [
          'role'    => 'user',
          'content' => [
            ['type' => 'text', 'text' => $describePrompt],
            ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']],
          ],
        ],
      ];

      $start_time = microtime(TRUE);
      $params = ['imageUrl' => $imageUrl, 'model' => $model];

      // Delegate to provider adapter if not OpenAI
      if ($this->provider !== 'openai' && method_exists($this->client, 'chat')) {
        $result = $this->client->chat($model, $messages, 0.4, 300);
        // Log adapter describeImage
        $duration = microtime(TRUE) - $start_time;
        $this->log('describeImage', $model, $params, $result, TRUE, $duration);
        return trim($result);
      }

      // Use OpenAI client directly for OpenAI provider
      $response = $this->client->chat()->create([
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 300,
      ]);

      $result = $response->toArray();

      // Log native describeImage
      $duration = microtime(TRUE) - $start_time;
      $this->log('describeImage', $model, $params, $result, TRUE, $duration);

      return isset($result["choices"][0]["message"]["content"])
        ? trim($result["choices"][0]["message"]["content"])
        : '';
    } catch (\Exception $e) {
      $duration = microtime(TRUE) - $start_time;
      $this->log('describeImage', $model, $params ?? ['imageUrl' => $imageUrl], NULL, FALSE, $duration, $e->getMessage());
      watchdog('openai', 'Error communicating with OpenAI: @error', ['@error' => $e->getMessage()], WATCHDOG_ERROR);
      return '';
    }
   }

  /**
   * Public helper for adapters to request logging via the wrapper.
   *
   * This delegates to the protected log() method and acts as a safe public
   * API so adapters can log request/response pairs when they are invoked
   * through other entry points.
   */
  public function recordLog($operation, $model, $request_data, $response_data, $status, $duration, $error_message = NULL) {
    // Delegate to protected logger.
    $this->log($operation, $model, $request_data, $response_data, $status, $duration, $error_message);
  }
}
