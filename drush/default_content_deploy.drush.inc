<?php

/**
 * @file
 * Drush integration for the default_content module.
 *
 * @todo Remove site UUID sync? This value must be set before install of this module.
 */
use Drupal\Core\Database\Database;
use Drupal\Component\Serialization\Json;

/**
 * Implements hook_drush_command().
 */
function default_content_deploy_drush_command() {
  $base = array(
    'core' => array('8+'),
    'drupal dependencies' => array('default_content'),
  );
  $items['default-content-deploy-import'] = [
      'description' => dt('Import all the content defined in a module.'),
      'options' => [
        'module' => dt('The name of the module.'),
      ],
      'aliases' => ['dcdi'],
    ] + $base;

  $items['default-content-deploy-export'] = [
      'description' => dt('Exports a single entity or group of entities to default content deploy module'),
      'arguments' => [
        'entity_type' => dt('The entity type to export.'),
      ],
      'options' => [
        'entity_id' => dt('The ID of the entity to export.'),
        'bundle' => dt('Write out the exported bundle of entity'),
        'skip_entities' => dt('The ID of the entity to skip.'),
      ],
      'aliases' => ['dcde'],
      'required-arguments' => 1,
    ] + $base;

  $items['default-content-deploy-export-with-references'] = [
      'description' => dt('Exports a single entity with referencesto default content deploy module'),
      'arguments' => [
        'entity_type' => dt('The entity type to export.'),
      ],
      'options' => [
        'entity_id' => dt('The ID of the entity to export.'),
        'bundle' => dt('Write out the exported bundle of entity'),
        'skip_entities' => dt('The ID of the entity to skip.'),
        'skip_core_users' => dt('Do not export user entities created by core'),
      ],
      'aliases' => ['dcder'],
      'required-arguments' => 1,
    ] + $base;

  $items['default-content-deploy-export-site'] = [
      'description' => dt('Exports a site to default content deploy module'),
      'options' => [
        'add_entity_type' => dt('The entity types to add.'),
        'skip_entity_type' => dt('The entity types to skip.'),
      ],
      'aliases' => ['dcdes'],
    ] + $base;

  $items['default-content-deploy-export-aliases'] = [
    'description' => dt('Exports site url aliases.'),
    'aliases' => ['dcdea'],
  ];

  $items['default-content-deploy-import-aliases'] = [
    'description' => dt('Import site url aliases.'),
    'aliases' => ['dcdia'],
    ] + $base;

  $items['default-content-deploy-uuid-sync'] = [
    'description' => dt('Set System Site, Admin and Anonymous UUIDs, Admin name.'),
    'options' => [
      'site' => dt('The system.site UUID.'),
      'uuid0' => dt('The UUID for Anonymous user.'),
      'uuid1' => dt('The UUID for Admin.'),
      'name' => dt('The login name for Admin.'),
    ],
    'examples' => array(
      'drush dcd-sync' => 'Displays the current UUID values.',
      'drush dcd-sync --site=value' => 'Set system.site UUID to value.',
      'drush dcd-sync --uuid0=value1 --uuid1=value2' => 'Set UUID for Anonymous user and Admin user.',
      'drush dcd-sync --name=admin' => 'Set name for Admin user.',
    ),
    'aliases' => ['dcd-sync'],
    ] + $base;

  return $items;
}

/**
 * Import all of the content from a given module.
 * Optional module name to import.
 */
function drush_default_content_deploy_import() {
  /** @var \Drupal\default_content_deploy\DefaultContentDeploy $manager */
  $manager = \Drupal::service('default_content_deploy.deploy');
  $module_name = drush_get_option('module') ? drush_get_option('module') : 'default_content_deploy';
  $result_info = $manager->deployContent($module_name);

  // Display results.
  drush_log(t('Deployed @count entities.', ['@count' => $result_info['processed']]), 'success');
  drush_print(t('- created: @count', ['@count' => $result_info['created']]));
  drush_print(t('- updated: @count', ['@count' => $result_info['updated']]));
  drush_print(t('- skipped: @count', ['@count' => $result_info['skipped']]));

  $manager->importAliases();
}


/**
 * Exports a piece of content into the file.
 *
 * @param string $entity_type_id
 *   The entity type ID.
 *
 * @todo Comment code.
 */
function drush_default_content_deploy_export($entity_type_id) {
  $entity_id = drush_get_option('entity_id');
  $bundle = drush_get_option('bundle');
  $skip_entities = drush_get_option('skip_entities', array());

  if (!is_null($entity_id) && !is_null($bundle)) {
    drush_log(t('You cannot use entity_id together with bundle'), 'error');
    exit;
  }

  $manager = \Drupal::service('default_content_deploy.deploy');
  $count = $manager->export($entity_type_id, $entity_id, $bundle, $skip_entities = array());

  drush_log(t('Exported @count entities.', ['@count' => $count]), 'status');
}

function drush_default_content_deploy_export_with_references($entity_type_id) {
  $entity_id = drush_get_option('entity_id');
  $bundle = drush_get_option('bundle');
  $skip_entities = drush_get_option('skip_entities', '');
  $skip_core_users = drush_get_option('skip_core_users', TRUE);

  if (!is_null($entity_id) && !is_null($bundle)) {
    drush_log(t('You cannot use entity_id together with bundle'), 'error');
    exit;
  }

  $manager = \Drupal::service('default_content_deploy.deploy');
  $count = $manager->exportWithReferences($entity_type_id, $entity_id, $bundle, $skip_entities, $skip_core_users);
  drush_log(t('Exported @count entities with references.', ['@count' => $count]), 'success');
}

/**
 * Export defined site content.
 *
 * @todo Comment code.
 */
function drush_default_content_deploy_export_site() {
  $add_entity_type = drush_get_option('add_entity_type', array());
  $skip_entity_type = drush_get_option('skip_entity_type', array());

  $manager = \Drupal::service('default_content_deploy.deploy');
  $count = $manager->exportSite($add_entity_type, $skip_entity_type);

  if (is_array($count)) {
    foreach ($count as $entity => $value) {
      drush_log(t('Exported @count entities @entity.', ['@count' => $value, '@entity' => $entity]), 'success');
    }
  }
  else {
    drush_log(t('Directory @path for export not created.', ['@path' => $count]), 'error');
  }
}

/**
 * @todo Comment code.
 */
function drush_default_content_deploy_export_aliases() {
  $manager = \Drupal::service('default_content_deploy.deploy');
  $save = $manager->exportAliases();

  if ($save != FALSE) {
    drush_log(t('Exported @count aliases.', ['@count' => count($save)]), 'success');
  }
  else {
    drush_log(t('Unable to export aliases.'), 'error');
    drush_log(t('Check if default_content_module/alias directory exists.'), 'error');
  }
}

/**
 * Import path aliases.
 * Data file is expected in default_content_deploy/alias/url_aliases.json.
 *
 * @todo The drush dcdi can import also from other modules, so path can not be hardcoded.
 * @todo Comment code.
 */
function drush_default_content_deploy_import_aliases() {
  $manager = \Drupal::service('default_content_deploy.deploy');
  $import_status = $manager->importAliases();

  drush_log(t('Imported @count aliases.', ['@count' => $import_status['imported']]), 'success');
  drush_log(t('Skipped @skipped aliases.', ['@skipped' => $import_status['skipped']]), 'success');
}


/**
 * Set System site, Admin and Anonymous UUIDs and Admin's name
 * and display current values.
 */
function drush_default_content_deploy_uuid_sync() {
  $uuid_site = drush_get_option('site');
  $uuid_anonymous = drush_get_option('uuid0');
  $uuid_admin = drush_get_option('uuid1');
  $admin_name = drush_get_option('name');

  $manager = \Drupal::service('default_content_deploy.deploy');
  $import_status = $manager->uuidSync($uuid_site, $uuid_anonymous, $uuid_admin, $admin_name);

  // Display current values.
  drush_print();
  drush_print(t('Current values') . ':');
  drush_print('----------------');
  drush_print(t('System.site UUID = @uuid', ['@uuid' => $import_status['current_site_uuid']]));
  drush_print(t('Anonymous user UUID = @uuid', ['@uuid' => $import_status['current_uuid_anonymous']]));
  drush_print(t('Admin UUID = @uuid', ['@uuid' => $$import_status['current_uuid_admin']]));
  drush_print(t('Admin\'s name = @name', ['@name' => $import_status['current_name']]));
}
