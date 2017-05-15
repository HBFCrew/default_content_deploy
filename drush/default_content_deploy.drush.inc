<?php

/**
 * @file
 * Drush integration for the default_content module.
 *
 * @todo Remove site UUID sync? This value must be set before install of this module.
 */

/**
 * Implements hook_drush_command().
 */
function default_content_deploy_drush_command() {
  $base = array(
    'core' => array('8+'),
    'drupal dependencies' => array('default_content'),
  );

  $items['default-content-deploy-export'] = [
    'aliases' => ['dcde'],
    'description' => dt('Exports a single entity or group of entities to default content deploy module'),
    'arguments' => [
      'entity_type' => dt('The entity type to export.'),
    ],
    'options' => [
      'entity_id' => dt('The ID of the entity to export.'),
      'bundle' => dt('Write out the exported bundle of entity'),
      'skip_entities' => dt('The ID of the entity to skip.'),
    ],
    'required-arguments' => 1,
    'examples' => [
      'drush dcde' => '',
    ],
  ] + $base;

  $items['default-content-deploy-export-with-references'] = [
    'aliases' => ['dcder'],
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
    'required-arguments' => 1,
    'examples' => [
      'drush dcder' => '',
    ],
  ] + $base;

  $items['default-content-deploy-export-site'] = [
    'aliases' => ['dcdes'],
    'description' => dt('Exports a site to default content deploy module'),
    'options' => [
      'add_entity_type' => dt('The entity types to add.'),
      'skip_entity_type' => dt('The entity types to skip.'),
    ],
    'examples' => [
      'drush dcdes' => '',
      'drush dcdes --add_entity_type=collection,my_custom_entity' => '',
      'drush dcdes --skip_entity_type=node,user' => '',
    ],
  ] + $base;

  $items['default-content-deploy-export-aliases'] = [
    'aliases' => ['dcdea'],
    'description' => dt('Exports site url aliases.'),
    'examples' => [
      'drush dcdea' => '',
    ],
  ] + $base;

  $items['default-content-deploy-import'] = [
    'aliases' => ['dcdi'],
    'description' => dt('Import all the content defined in a module.'),
    'options' => [
      'module' => dt('The name of the module.'),
    ],
    'examples' => [
      'drush dcdi' => '',
    ],
  ] + $base;

  $items['default-content-deploy-import-aliases'] = [
    'aliases' => ['dcdia'],
    'description' => dt('Import site url aliases.'),
    'examples' => [
      'drush dcdia' => '',
    ],
  ] + $base;

  $items['default-content-deploy-uuid-info'] = [
    'aliases' => ['dcd-uuid-info'],
    'description' => dt('Get current System Site, Admin and Anonymous UUIDs, Admin name.'),
    'examples' => [
      'drush dcd-uuid-info' => 'Displays the current UUID values.',
    ],
  ] + $base;

  return $items;
}

/**
 * Exports entities by entity type, bundle and entity id.
 *
 * Entity bundles, ids and skip entities can be delimited by comma.
 *
 * @param string $entity_type_id
 *   The entity type ID.
 *
 * @example drush dcde node
 * @example drush dcde node --bundle=page
 * @example drush dcde node --bundle=page,article --entity_id=2,3,4
 * @example drush dcde node --bundle=page,article --skip_entities=5,7
 * @example drush dcde node --skip_entities=5,7
 */
function drush_default_content_deploy_export($entity_type_id) {
  $entity_ids = drush_get_option('entity_id', '');
  $entity_bundles = drush_get_option('bundle', '');
  $skip_entities = drush_get_option('skip_entities', '');

  /** @var \Drupal\default_content_deploy\Exporter $exporter */
  $exporter = \Drupal::service('default_content_deploy.exporter');
  $count = $exporter->export($entity_type_id, $entity_bundles, $entity_ids, $skip_entities);

  drush_log(t('Exported @count entities.', ['@count' => $count]), 'success');
}

/**
 * Exports entities by entity type, bundle and entity id with all references.
 *
 * Entity bundles, ids and skip entities can be delimited by comma.
 *
 * @param string $entity_type_id
 *   The entity type ID.
 *
 * @example drush dcder node
 * @example drush dcder node --bundle=page
 * @example drush dcder node --bundle=page,article --entity_id=2,3,4
 * @example drush dcder node --bundle=page,article --skip_entities=5,7
 * @example drush dcder node --skip_entities=5,7
 */
function drush_default_content_deploy_export_with_references($entity_type_id) {
  $entity_ids = drush_get_option('entity_id', '');
  $entity_bundles = drush_get_option('bundle', '');
  $skip_entities = drush_get_option('skip_entities', '');

  /** @var \Drupal\default_content_deploy\Exporter $exporter */
  $exporter = \Drupal::service('default_content_deploy.exporter');
  $count = $exporter->exportWithReferences($entity_type_id, $entity_bundles, $entity_ids, $skip_entities);
  drush_log(t('Exported @count entities with references.', ['@count' => $count]), 'success');
}

/**
 * Exports whole site.
 *
 * Add entitytype and Skip entity type can be delimited by comma.
 *
 * @example drush dcdes node
 * @example drush dcdes node --add_entity_type=collection,feedsource
 * @example drush dcdes node --skip_entity_type=node
 */
function drush_default_content_deploy_export_site() {
  $add_entity_type = drush_get_option('add_entity_type', '');
  $skip_entity_type = drush_get_option('skip_entity_type', '');

  /** @var \Drupal\default_content_deploy\Exporter $exporter */
  $exporter = \Drupal::service('default_content_deploy.exporter');
  $count = $exporter->exportSite($add_entity_type, $skip_entity_type);

  foreach ($count as $entity => $value) {
    drush_log(t('Exported @count entities of type @entity.', ['@count' => $value, '@entity' => $entity]), 'success');
  }
}

/**
 * Exports url aliases.
 *
 * @example drush dcdea
 */
function drush_default_content_deploy_export_aliases() {
  /** @var \Drupal\default_content_deploy\Exporter $exporter */
  $exporter = \Drupal::service('default_content_deploy.exporter');
  $aliases = $exporter->exportUrlAliases();

  drush_log(t('Exported @count aliases.', ['@count' => $aliases]), 'success');
}

/**
 * Import all of the content from content folder.
 *
 * @example drush dcdi
 */
function drush_default_content_deploy_import() {
  /** @var \Drupal\default_content_deploy\Importer $importer */
  $importer = \Drupal::service('default_content_deploy.importer');
  $result_info = $importer->deployContent();
  $importer->importUrlAliases();

  // Display results.
  drush_log(t('Deployed @count entities.', ['@count' => $result_info['processed']]), 'success');
  drush_print(t('- created: @count', ['@count' => $result_info['created']]));
  drush_print(t('- updated: @count', ['@count' => $result_info['updated']]));
  drush_print(t('- skipped: @count', ['@count' => $result_info['skipped']]));


}

/**
 * Import path aliases.
 *
 * @example drush dcdia
 */
function drush_default_content_deploy_import_aliases() {
  /** @var \Drupal\default_content_deploy\Importer $importer */
  $importer = \Drupal::service('default_content_deploy.importer');
  $import_status = $importer->importUrlAliases();

  drush_log(t('Imported @count aliases.', ['@count' => $import_status['imported']]), 'success');
  drush_log(t('Skipped @skipped aliases.', ['@skipped' => $import_status['skipped']]), 'success');
}

/**
 * Get System site, Admin and Anonymous UUIDs and Admin's name
 * and display current values.
 */
function drush_default_content_deploy_uuid_info() {
  $dcd = \Drupal::service('default_content_deploy.base');
  $import_status = $dcd->uuidInfo();

  // Display current values.
  drush_print(t('System.site UUID = @uuid', ['@uuid' => $import_status['current_site_uuid']]));
  drush_print(t('Anonymous user UUID = @uuid', ['@uuid' => $import_status['current_uuid_anonymous']]));
  drush_print(t('Admin UUID = @uuid', ['@uuid' => $import_status['current_uuid_admin']]));
  drush_print(t('Admin\'s name = @name', ['@name' => $import_status['current_admin_name']]));
}
