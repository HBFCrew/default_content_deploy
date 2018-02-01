<?php

/**
 * @file
 * Drush integration for the default_content_deploy module.
 */

/**
 * Implements hook_drush_command().
 */
function default_content_deploy_drush_command() {
  $base = [
    'core' => ['8+'],
    'drupal dependencies' => ['default_content'],
  ];

  $items['default-content-deploy-export'] = [
    'aliases' => ['dcde'],
    'description' => dt('Exports a single entity or group of entities.'),
    'arguments' => [
      'entity_type' => dt('The entity type to export. If a wrong content entity type is entered, module displays a list of all content entity types.'),
    ],
    'options' => [
      'entity_id' => dt('The ID of the entity to export.'),
      'bundle' => dt('Write out the exported bundle of entity'),
      'skip_entities' => dt('The ID of the entity to skip.'),
    ],
    'required-arguments' => 1,
    'examples' => [
      'drush dcde node' => 'Export all nodes',
      'drush dcde node --bundle=page' => 'Export all nodes with bundle page',
      'drush dcde node --bundle=page,article --entity_id=2,3,4' => 'Export all nodes with bundle page or article plus nodes with entitiy id 2, 3 and 4.',
      'drush dcde node --bundle=page,article --skip_entities=5,7' => 'Export all nodes with bundle page or article and skip nodes with entity id 5 and 7.',
      'drush dcde node --skip_entities=5,7' => 'Export all nodes and skip nodes with entity id 5 and 7.',
    ],
  ] + $base;

  $items['default-content-deploy-export-with-references'] = [
    'aliases' => ['dcder'],
    'description' => dt('Exports a single entity with references.'),
    'arguments' => [
      'entity_type' => dt('The entity type to export. If a wrong content entity type is entered, module displays a list of all content entity types.'),
    ],
    'options' => [
      'entity_id' => dt('The ID of the entity to export.'),
      'bundle' => dt('Write out the exported bundle of entity'),
      'skip_entities' => dt('The ID of the entity to skip.'),
    ],
    'required-arguments' => 1,
    'examples' => [
      'drush dcde node' => 'Export all nodes with references',
      'drush dcde node --bundle=page' => 'Export all nodes with references with bundle page',
      'drush dcde node --bundle=page,article --entity_id=2,3,4' => 'Export all nodes with references with bundle page or article plus nodes with entitiy id 2, 3 and 4.',
      'drush dcde node --bundle=page,article --skip_entities=5,7' => 'Export all nodes with references with bundle page or article and skip nodes with entity id 5 and 7.',
      'drush dcde node --skip_entities=5,7' => 'Export all nodes and skip nodes with references with entity id 5 and 7.',
    ],
  ] + $base;

  $items['default-content-deploy-export-site'] = [
    'aliases' => ['dcdes'],
    'description' => dt('Exports a site.'),
    'options' => [
      'skip_entity_type' => dt('The entity types to skip.'),
    ],
    'examples' => [
      'drush dcdes' => 'Export complete website.',
      'drush dcdes --skip_entity_type=node,user' => 'Export complete website but skip nodes and users.',
    ],
  ] + $base;

  $items['default-content-deploy-export-aliases'] = [
    'aliases' => ['dcdea'],
    'description' => dt('Exports site url aliases.'),
    'examples' => [
      'drush dcdea' => 'Export url aliases.',
    ],
  ] + $base;

  $items['default-content-deploy-import'] = [
    'aliases' => ['dcdi'],
    'description' => dt('Import all the content defined in a content directory.'),
    'options' => [
      'force-update' => dt('Content with different UUID but same ID will be updated (UUID will be replaced).'),
    ],
    'examples' => [
      'drush dcdi' => 'Import content. Existing older content with matching UUID will be updated. Newer content and existing content with different UUID will be ignored.',
      'drush dcdi --force-update' => 'Import content but existing content with different UUID will be replaced (recommended for better content synchronization).',
      'drush dcdi --verbose' => 'Print detailed information about importing entities.',
    ],
  ] + $base;

  $items['default-content-deploy-import-aliases'] = [
    'aliases' => ['dcdia'],
    'description' => dt('Import site url aliases.'),
    'examples' => [
      'drush dcdia' => 'Import url aliases.',
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

  drush_log(dt('Exported @count entities.', ['@count' => $count]), 'success');
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
  drush_log(dt('Exported @count entities with references.', ['@count' => $count]), 'success');
}

/**
 * Exports whole site.
 *
 * Add entitytype and Skip entity type can be delimited by comma.
 *
 * @example drush dcdes node
 * @example drush dcdes node --skip_entity_type=node
 */
function drush_default_content_deploy_export_site() {
  $skip_entity_type = drush_get_option('skip_entity_type', '');

  /** @var \Drupal\default_content_deploy\Exporter $exporter */
  $exporter = \Drupal::service('default_content_deploy.exporter');
  $count = $exporter->exportSite($skip_entity_type);

  foreach ($count as $entity => $value) {
    drush_log(dt('Exported @count entities of type @entity.', [
      '@count' => $value,
      '@entity' => $entity,
    ]), 'success');
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

  drush_log(dt('Exported @count aliases.', ['@count' => $aliases]), 'success');
}

/**
 * Import all of the content from content folder.
 *
 * @example drush dcdi
 * @example drush dcdi -y
 * @example drush dcdi -verbose
 * @example drush dcdi -verbose -- force-update
 */
function drush_default_content_deploy_import() {
  $force_update = drush_get_option('force-update');
  /** @var \Drupal\default_content_deploy\Importer $importer */
  $importer = \Drupal::service('default_content_deploy.importer');

  // Perform read only update.
  $result_info = $importer->deployContent($force_update, FALSE);
  drush_print(dt('@count entities will be processed.', ['@count' => $result_info['processed']]));
  drush_print(dt('- created: @count', ['@count' => $result_info['created']]));
  drush_print(dt('- updated: @count', ['@count' => $result_info['updated']]));
  drush_print(dt('- skipped: @count', ['@count' => $result_info['skipped']]));
  drush_print(dt('Missing files to create: @count', ['@count' => $result_info['file_created']]));
  $entities_todo = $result_info['created'] + $result_info['updated'] + $result_info['file_created'];
  if ($entities_todo == 0) {
    drush_print(dt('Nothing to do.'));
    return FALSE;
  }
  if ($entities_todo == 0 || !drush_confirm(dt('Do you really want to continue?'))) {
    return drush_user_abort();
  }
  // Perform update.
  $result_info = $importer->deployContent($force_update, TRUE);
  $import_status = $importer->importUrlAliases();

  // Display results.
  drush_log(dt('@count entities have been processed.', ['@count' => $result_info['processed']]), 'success');
  drush_print(dt('- created: @count', ['@count' => $result_info['created']]));
  drush_print(dt('- updated: @count', ['@count' => $result_info['updated']]));
  drush_print(dt('- skipped: @count', ['@count' => $result_info['skipped']]));
  drush_print(dt('Missing files created: @count', ['@count' => $result_info['file_created']]));

  drush_log(dt('Imported @count aliases.', ['@count' => $import_status['imported']]), 'success');
  drush_log(dt('Skipped @skipped aliases.', ['@skipped' => $import_status['skipped']]), 'success');

  return FALSE;
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

  drush_log(dt('Imported @count aliases.', ['@count' => $import_status['imported']]), 'success');
  drush_log(dt('Skipped @skipped aliases.', ['@skipped' => $import_status['skipped']]), 'success');
}

/**
 * Get UUID info.
 *
 * Get System site, Admin and Anonymous UUIDs and Admin's name
 * and display current values.
 */
function drush_default_content_deploy_uuid_info() {
  $dcd = \Drupal::service('default_content_deploy.base');
  $import_status = $dcd->uuidInfo();

  // Display current values.
  drush_print(dt('System.site UUID = @uuid', ['@uuid' => $import_status['current_site_uuid']]));
  drush_print(dt('Anonymous user UUID = @uuid', ['@uuid' => $import_status['current_uuid_anonymous']]));
  drush_print(dt('Admin UUID = @uuid', ['@uuid' => $import_status['current_uuid_admin']]));
  drush_print(dt('Admin\'s name = @name', ['@name' => $import_status['current_admin_name']]));
}
