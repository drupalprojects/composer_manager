<?php

/**
 * @file
 * Drush hook implementations for the Composer Manager module.
 */

/**
 * Implements hook_drush_command().
 */
function composer_manager_drush_command() {
  $items = array();
  $items['composer-manager-init'] = array(
    'description' => 'Initializes Composer Manager.',
  );

  return $items;
}

/**
 * Initializes Composer Manager.
 */
function drush_composer_manager_init() {
  try {
    composer_manager_initialize();
    drush_log(dt('Composer Manager has been successfuly initialized.'), 'ok');
    return TRUE;
  }
  catch (\Exception $e) {
    return drush_set_error($e->getMessage());
  }
}
