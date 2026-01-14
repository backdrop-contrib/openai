<?php

/**
 * @file
 * Admin pages for OpenAI logs.
 */

/**
 * Page callback: AI Logs list.
 */
function openai_log_list_page() {
  $header = array(
    array('data' => t('ID'), 'field' => 'id', 'sort' => 'desc'),
    array('data' => t('Timestamp'), 'field' => 'timestamp'),
    array('data' => t('Module'), 'field' => 'module'),
    array('data' => t('Operation'), 'field' => 'operation'),
    array('data' => t('Model'), 'field' => 'model'),
    array('data' => t('Provider'), 'field' => 'provider'),
    array('data' => t('Status'), 'field' => 'status'),
    array('data' => t('Duration'), 'field' => 'duration'),
    array('data' => t('Operations')),
  );

  $query = db_select('openai_log', 'l')
    ->extend('PagerDefault')
    ->extend('TableSort');
  $query->fields('l');
  $query->limit(50);
  $query->orderByHeader($header);

  $result = $query->execute();

  $rows = array();
  foreach ($result as $log) {
    $rows[] = array(
      $log->id,
      format_date($log->timestamp, 'short'),
      check_plain($log->module),
      check_plain($log->operation),
      check_plain($log->model),
      check_plain($log->provider),
      $log->status ? t('Success') : t('Failure'),
      number_format($log->duration, 2) . 's',
      l(t('View'), 'admin/reports/openai-logs/' . $log->id),
    );
  }

  $build['table'] = array(
    '#theme' => 'table',
    '#header' => $header,
    '#rows' => $rows,
    '#empty' => t('No AI logs found.'),
  );
  $build['pager'] = array(
    '#theme' => 'pager',
  );

  return $build;
}

/**
 * Page callback: View AI Log entry.
 */
function openai_log_view_page($log) {
  backdrop_set_title(t('AI Log entry #@id', array('@id' => $log->id)));

  $rows = array(
    array(array('data' => t('ID'), 'header' => TRUE), $log->id),
    array(array('data' => t('Timestamp'), 'header' => TRUE), format_date($log->timestamp, 'long')),
    array(array('data' => t('User'), 'header' => TRUE), theme('username', array('account' => user_load($log->uid)))),
    array(array('data' => t('Module'), 'header' => TRUE), check_plain($log->module)),
    array(array('data' => t('Operation'), 'header' => TRUE), check_plain($log->operation)),
    array(array('data' => t('Model'), 'header' => TRUE), check_plain($log->model)),
    array(array('data' => t('Provider'), 'header' => TRUE), check_plain($log->provider)),
    array(array('data' => t('Status'), 'header' => TRUE), $log->status ? t('Success') : t('Failure')),
    array(array('data' => t('Duration'), 'header' => TRUE), number_format($log->duration, 4) . 's'),
  );

  if (!empty($log->error_message)) {
    $rows[] = array(array('data' => t('Error Message'), 'header' => TRUE), check_plain($log->error_message));
  }

  $build['details'] = array(
    '#theme' => 'table',
    '#rows' => $rows,
  );

  $build['request'] = array(
    '#type' => 'fieldset',
    '#title' => t('Request Data'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );
  $request_data = $log->request_data;
  if ($request_json = json_decode($request_data)) {
    $request_data = json_encode($request_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  $build['request']['data'] = array(
    '#markup' => '<pre>' . check_plain($request_data) . '</pre>',
  );

  $build['response'] = array(
    '#type' => 'fieldset',
    '#title' => t('Response Data'),
    '#collapsible' => TRUE,
    '#collapsed' => FALSE,
  );

  $response_data = $log->response_data;
  if ($response_json = json_decode($response_data)) {
    $response_data = json_encode($response_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  $build['response']['data'] = array(
    '#markup' => '<pre>' . check_plain($response_data) . '</pre>',
  );

  return $build;
}
