<?php

namespace Drupal\default_content_deploy\Commands;

use Drupal\default_content_deploy\DefaultContentDeployBase;
use Drupal\default_content_deploy\Exporter;
use Drupal\default_content_deploy\Importer;
use Drush\Commands\DrushCommands;

/**
 * Class DefaultContentDeployCommands.
 *
 * @package Drupal\default_content_deploy\Commands
 */
class DefaultContentDeployCommands extends DrushCommands {

  /**
   * DCD Exporter.
   *
   * @var \Drupal\default_content_deploy\Exporter
   */
  private $exporter;

  /**
   * DCD Importer.
   *
   * @var \Drupal\default_content_deploy\Importer
   */
  private $importer;

  /**
   * DCD Base.
   *
   * @var \Drupal\default_content_deploy\DefaultContentDeployBase
   */
  private $base;

  /**
   * DefaultContentDeployCommands constructor.
   *
   * @param \Drupal\default_content_deploy\Exporter $exporter
   *   DCD Exporter.
   * @param \Drupal\default_content_deploy\Importer $importer
   *   DCD Importer.
   * @param \Drupal\default_content_deploy\DefaultContentDeployBase $base
   *   DCD Base.
   */
  public function __construct(Exporter $exporter,
                              Importer $importer,
                              DefaultContentDeployBase $base) {
    $this->exporter = $exporter;
    $this->importer = $importer;
    $this->base = $base;
  }

  /**
   * Exports a single entity or group of entities.
   *
   * @param string $entityType
   *   The entity type to export. If a wrong content entity type is entered,
   *   module displays a list of all content entity types.
   * @param array $options
   *   An associative array of options whose values come
   *   from cli, aliases, config, etc.
   *
   * @command default-content-deploy:export
   *
   * @option entity_id The ID of the entity to export.
   * @option bundle Write out the exported bundle of entity
   * @option skip_entities The ID of the entity to skip.
   * @usage drush dcde node
   *   Export all nodes
   * @usage drush dcde node --bundle=page
   *   Export all nodes with bundle page
   * @usage drush dcde node --bundle=page,article --entity_id=2,3,4
   *   Export all nodes with bundle page or article plus nodes with entities id
   *   2, 3 and 4.
   * @usage drush dcde node --bundle=page,article --skip_entities=5,7
   *   Export all nodes with bundle page or article and skip nodes with entity
   *   id 5 and 7.
   * @usage drush dcde node --skip_entities=5,7
   *   Export all nodes and skip nodes with entity id 5 and 7.
   * @validate-module-enabled default_content
   * @aliases dcde,default-content-deploy-export
   */
  public function contentDeployExport($entityType,
                                      array $options = [
                                        'entity_id' => NULL,
                                        'bundle' => NULL,
                                        'skip_entities' => NULL,
                                      ]) {
    $entity_ids = $options['entity_id'];
    $entity_bundles = $options['bundle'];
    $skip_entities = $options['skip_entities'];

    $count = $this->exporter->export($entityType, $entity_bundles, $entity_ids, $skip_entities);

    $this->logger->notice(dt('Exported @count entities.', ['@count' => $count]));
  }

  /**
   * Exports a single entity with references.
   *
   * @param string $entityType
   *   The entity type to export. If a wrong content entity
   *   type is entered, module displays a list of all content entity types.
   * @param array $options
   *   An associative array of options whose values come
   *   from cli, aliases, config, etc.
   *
   * @command default-content-deploy:export-with-references
   *
   * @option entity_id The ID of the entity to export.
   * @option bundle Write out the exported bundle of entity
   * @option skip_entities The ID of the entity to skip.
   * @usage drush dcde node
   *   Export all nodes with references
   * @usage drush dcde node --bundle=page
   *   Export all nodes with references with bundle page
   * @usage drush dcde node --bundle=page,article --entity_id=2,3,4
   *   Export all nodes with references with bundle page or article plus nodes
   *   with entitiy id 2, 3 and 4.
   * @usage drush dcde node --bundle=page,article --skip_entities=5,7
   *   Export all nodes with references with bundle page or article and skip
   *   nodes with entity id 5 and 7.
   * @usage drush dcde node --skip_entities=5,7
   *   Export all nodes and skip nodes with references with entity id 5 and 7.
   * @validate-module-enabled default_content
   * @aliases dcder,default-content-deploy-export-with-references
   */
  public function contentDeployExportWithReferences($entityType,
                                                    array $options = [
                                                      'entity_id' => NULL,
                                                      'bundle' => NULL,
                                                      'skip_entities' => NULL,
                                                    ]) {
    $entity_ids = $options['entity_id'];
    $entity_bundles = $options['bundle'];
    $skip_entities = $options['skip_entities'];

    $count = $this->exporter->exportWithReferences($entityType, $entity_bundles, $entity_ids, $skip_entities);
    $this->logger->notice(dt('Exported @count entities with references.', ['@count' => $count]));
  }

  /**
   * Exports a whole site content.
   *
   * Config directory will be emptied
   * and all content of all entities will be exported.
   *
   * Use 'drush dcd-entity-list' for list of all content entities
   * on this system. You can exclude any entity type from export.
   *
   * The content directory can be set in setting.php
   * as $config['content_directory'] or will be created in public:// directory.
   *
   * @param array $options
   *   An associative array of options.
   *
   * @command default-content-deploy:export-site
   *
   * @option add_entity_type DEPRECATED. Will be removed in beta. The dcdes
   *   command exports all entity types.
   * @option skip_entity_type The entity types to skip.
   *   Use 'drush dcd-entity-list' for list of all content entities.
   * @usage drush dcdes
   *   Export complete website.
   * @usage drush dcdes --skip_entity_type=node,user
   *   Export complete website but skip nodes and users.
   * @validate-module-enabled default_content
   * @aliases dcdes,default-content-deploy-export-site
   */
  public function contentDeployExportSite(array $options = [
    'add_entity_type' => NULL,
    'skip_entity_type' => NULL,
  ]) {
    // @todo Remove in beta version.
    if ($options['add_entity_type']) {
      $this->logger->notice(dt('Option add_entity_type is deprecated and will be disabled in a future. The drush dcdes command exports all content entity by default.'));
    }
    $skip_entity_type = $options['skip_entity_type'];

    $count = $this->exporter->exportSite($skip_entity_type);

    foreach ($count as $entity => $value) {
      $this->logger->notice(dt('Exported @count entities of type @entity.', [
        '@count' => $value,
        '@entity' => $entity,
      ]));
    }

    // Also export path aliases.
    $this->contentDeployExportAliases();
  }

  /**
   * Exports site url aliases.
   *
   * @command default-content-deploy:export-aliases
   *
   * @usage drush dcdea
   *   Export url aliases.
   * @validate-module-enabled default_content
   * @aliases dcdea,default-content-deploy-export-aliases
   */
  public function contentDeployExportAliases() {
    $aliases = $this->exporter->exportUrlAliases();
    $this->logger->notice(dt('Exported @count aliases.', ['@count' => $aliases]));
  }

  /**
   * Import all the content defined in a content directory.
   *
   * @param array $options
   *   An associative array of options whose values come
   *   from cli, aliases, config, etc.
   *
   * @command default-content-deploy:import
   *
   * @option force-update
   *   Content with different UUID but same ID will be
   *   updated (UUID will be replaced).
   * @usage drush dcdi
   *   Import content. Existing older content with matching UUID will be
   *   updated. Newer content and existing content with different UUID will be
   *   ignored.
   * @usage drush dcdi --force-update
   *   Import content but existing content with different UUID will be replaced
   *   (recommended for better content synchronization).
   * @usage drush dcdi --verbose
   *   Print detailed information about importing entities.
   * @validate-module-enabled default_content
   * @aliases dcdi,default-content-deploy-import
   */
  public function contentDeployImport(array $options = ['force-update' => NULL]) {
    $force_update = $options['force-update'];

    // Perform read only update.
    $result_info = $this->importer->deployContent($force_update, FALSE);
    $this->output()
      ->writeln(dt('@count entities will be processed.', ['@count' => $result_info['processed']]));
    $this->displayImportResult($result_info);
    $entities_todo = $result_info['created'] + $result_info['updated'] + $result_info['file_created'];
    if ($entities_todo == 0) {
      $this->output()->writeln(dt('Nothing to do.'));
      return;
    }
    if ($this->io()->confirm(dt('Do you really want to continue?'))) {
      // Perform update.
      $result_info = $this->importer->deployContent($force_update, TRUE);
      $import_status = $this->importer->importUrlAliases();

      // Display results.
      $this->logger()
        ->notice(dt('@count entities have been processed.', ['@count' => $result_info['processed']]));
      $this->displayImportResult($result_info);

      $this->logger()
        ->notice(dt('Imported @count aliases.', ['@count' => $import_status['imported']]));
      $this->logger()
        ->notice(dt('Skipped @skipped aliases.', ['@skipped' => $import_status['skipped']]));
    }
  }

  /**
   * Import site url aliases.
   *
   * @command default-content-deploy:import-aliases
   *
   * @usage drush dcdia
   *   Import url aliases.
   * @validate-module-enabled default_content
   * @aliases dcdia,default-content-deploy-import-aliases
   */
  public function contentDeployImportAliases() {
    $import_status = $this->importer->importUrlAliases();
    $this->logger()
      ->notice(dt('Imported @count aliases.', ['@count' => $import_status['imported']]));
    $this->logger()
      ->notice(dt('Skipped @skipped aliases.', ['@skipped' => $import_status['skipped']]));
  }

  /**
   * Get current System Site, Admin and Anonymous UUIDs, Admin name.
   *
   * @command default-content-deploy:uuid-info
   * @usage drush dcd-uuid-info
   *   Displays the current UUID values.
   * @validate-module-enabled default_content
   * @aliases dcd-uuid-info,default-content-deploy-uuid-info
   */
  public function contentDeployUuidInfo() {
    $import_status = $this->base->uuidInfo();
    $this->output()
      ->writeln(dt('System.site UUID = @uuid', ['@uuid' => $import_status['current_site_uuid']]));
    $this->output()
      ->writeln(dt('Anonymous user UUID = @uuid', ['@uuid' => $import_status['current_uuid_anonymous']]));
    $this->output()
      ->writeln(dt('Admin UUID = @uuid', ['@uuid' => $import_status['current_uuid_admin']]));
    $this->output()
      ->writeln(dt('Admin\'s name = @name', ['@name' => $import_status['current_admin_name']]));
  }

  /**
   * List current content entity types.
   *
   * @command default-content-deploy:entity-list
   * @usage drush dcd-entity-list
   *   Displays all current content entity types.
   * @aliases dcd-entity-list,default-content-deploy-entity-list
   */
  public function contentEntityList() {
    $contentEntityList = $this->exporter->getContentEntityTypes();

    $this->output()
      ->writeln(dt('This Drupal system contains following content entities:'));
    $this->output()
      ->writeln(implode(",", array_keys($contentEntityList)));
    $this->output()
      ->writeln('');
  }

  /**
   * Display info before/after import.
   *
   * @param array $result_info
   *   Importer info.
   */
  private function displayImportResult(array $result_info) {
    $this->output()
      ->writeln(dt('- created: @count', ['@count' => $result_info['created']]));
    $this->output()
      ->writeln(dt('- updated: @count', ['@count' => $result_info['updated']]));
    $this->output()
      ->writeln(dt('- skipped: @count', ['@count' => $result_info['skipped']]));
    $this->output()
      ->writeln(dt('Missing files created: @count', ['@count' => $result_info['file_created']]));
  }

}
