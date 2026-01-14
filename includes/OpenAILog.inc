<?php

/**
 * @file
 * Entity class for OpenAI Log.
 */

class OpenAILog extends Entity {
  public $id;
  public $timestamp;
  public $uid;
  public $module;
  public $operation;
  public $model;
  public $provider;
  public $request_data;
  public $response_data;
  public $status;
  public $duration;
  public $error_message;

  /**
   * Constructor for OpenAILog.
   */
  public function __construct(array $values = array()) {
    parent::__construct($values, 'openai_log');
  }

  /**
   * Returns the label of the log entry.
   */
  public function label() {
    return t('Log @id: @op (@model)', array(
      '@id' => $this->id,
      '@op' => $this->operation,
      '@model' => $this->model,
    ));
  }

  /**
   * Implements EntityInterface::id().
   */
  public function id() {
    return isset($this->id) ? $this->id : NULL;
  }

  /**
   * Implements EntityInterface::entityType().
   */
  public function entityType() {
    return 'openai_log';
  }

  /**
   * Implements EntityInterface::uri().
   */
  public function uri() {
    return array('path' => 'admin/reports/openai-logs/' . $this->id);
  }
}
