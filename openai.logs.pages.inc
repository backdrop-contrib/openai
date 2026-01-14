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

  // Add filters
  $build['filter_form'] = backdrop_get_form('openai_log_filter_form');

  $query = db_select('openai_log', 'l')
    ->extend('PagerDefault')
    ->extend('TableSort');
  $query->fields('l');
  $query->limit(50);
  $query->orderByHeader($header);

  // Apply filters
  if (isset($_SESSION['openai_log_filter'])) {
    foreach ($_SESSION['openai_log_filter'] as $field => $value) {
      if ($value !== '') {
        $query->condition($field, $value);
      }
    }
  }

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
 * Form builder: AI Logs filter form.
 */
function openai_log_filter_form($form, &$form_state) {
  $form['filters'] = array(
    '#type' => 'fieldset',
    '#title' => t('Filters'),
    '#collapsible' => TRUE,
    '#collapsed' => empty($_SESSION['openai_log_filter']),
  );

  $options_module = db_query("SELECT DISTINCT module FROM {openai_log} ORDER BY module ASC")->fetchAllKeyed(0, 0);
  $form['filters']['module'] = array(
    '#type' => 'select',
    '#title' => t('Module'),
    '#options' => array('' => t('- Any -')) + $options_module,
    '#default_value' => $_SESSION['openai_log_filter']['module'] ?? '',
  );

  $options_op = db_query("SELECT DISTINCT operation FROM {openai_log} ORDER BY operation ASC")->fetchAllKeyed(0, 0);
  $form['filters']['operation'] = array(
    '#type' => 'select',
    '#title' => t('Operation'),
    '#options' => array('' => t('- Any -')) + $options_op,
    '#default_value' => $_SESSION['openai_log_filter']['operation'] ?? '',
  );

  $options_provider = db_query("SELECT DISTINCT provider FROM {openai_log} ORDER BY provider ASC")->fetchAllKeyed(0, 0);
  $form['filters']['provider'] = array(
    '#type' => 'select',
    '#title' => t('Provider'),
    '#options' => array('' => t('- Any -')) + $options_provider,
    '#default_value' => $_SESSION['openai_log_filter']['provider'] ?? '',
  );

  $form['filters']['status'] = array(
    '#type' => 'select',
    '#title' => t('Status'),
    '#options' => array(
      '' => t('- Any -'),
      1 => t('Success'),
      0 => t('Failure'),
    ),
    '#default_value' => $_SESSION['openai_log_filter']['status'] ?? '',
  );

  $form['filters']['actions'] = array('#type' => 'actions');
  $form['filters']['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Filter'),
  );
  if (!empty($_SESSION['openai_log_filter'])) {
    $form['filters']['actions']['reset'] = array(
      '#type' => 'submit',
      '#value' => t('Reset'),
    );
  }

  return $form;
}

/**
 * Submit handler: AI Logs filter form.
 */
function openai_log_filter_form_submit($form, &$form_state) {
  if ($form_state['values']['op'] == t('Reset')) {
    unset($_SESSION['openai_log_filter']);
  }
  else {
    $_SESSION['openai_log_filter'] = array(
      'module' => $form_state['values']['module'],
      'operation' => $form_state['values']['operation'],
      'provider' => $form_state['values']['provider'],
      'status' => $form_state['values']['status'],
    );
  }
}

/**
 * Page callback: View AI Log entry.
 */
function openai_log_view_page($log) {
  if (!$log) {
    return MENU_NOT_FOUND;
  }
  // If only the ID was passed (fallback for old menu cache)
  if (!is_object($log)) {
    $log = openai_log_load($log);
    if (!$log) {
      return MENU_NOT_FOUND;
    }
  }
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
