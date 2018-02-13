<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\ContentEntityType;
use Drush\Drush;

/**
 * A service for handling export of default content.
 */
class Exporter extends DefaultContentDeployBase {

  /**
   * Export entites by entity type, id or bundle.
   *
   * @param string $entityType
   *   Entity Type.
   * @param string $entityBundle
   *   Entity ID.
   * @param string $entityIds
   *   Entity Bundle.
   * @param string $skipEntities
   *   Entities to skip.
   *
   * @return int
   *   Number of exported entities.
   */
  public function export($entityType,
                         $entityBundle = '',
                         $entityIds = '',
                         $skipEntities = '') {
    $exportedEntities = [];
    // Get entities for export.
    $exportedEntityIds = $this->getEntityIdsForExport($entityType, $entityBundle, $entityIds, $skipEntities);
    // Serialize entities and get their UUIDs.
    foreach ($exportedEntityIds as $entityId) {
      $exportedEntity = $this->exporter->exportContent($entityType, $entityId);
      $deseralizedEntity = $this->serializer->decode($exportedEntity, 'hal_json');
      $uuid = $deseralizedEntity['uuid'][0]['value'];
      $exportedEntities[$entityType][$uuid] = $exportedEntity;
    }
    // Export all entities to folder.
    $this->exporter->writeDefaultContent($exportedEntities, $this->getContentFolder());

    return count($exportedEntityIds);
  }

  /**
   * Export entities by entity type, id or bundle with references.
   *
   * @param string $entityType
   *   Entity Type.
   * @param string $entityBundle
   *   Entity ID.
   * @param string $entityIds
   *   Entity Bundle.
   * @param string $skipEntities
   *   Entities to skip.
   *
   * @return int
   *   Number of exported entities.
   */
  public function exportWithReferences($entityType,
                                       $entityBundle = '',
                                       $entityIds = '',
                                       $skipEntities = '') {
    // Get entities for export.
    $exportedEntityIds = $this->getEntityIdsForExport($entityType, $entityBundle, $entityIds, $skipEntities);
    foreach ($exportedEntityIds as $entityId) {
      $exportedEntityByType = $this->exporter->exportContentWithReferences($entityType, $entityId);
      $this->exporter->writeDefaultContent($exportedEntityByType, $this->getContentFolder());
    }

    return count($exportedEntityIds);
  }

  /**
   * Export complete site.
   *
   * @param string $skipEntityType
   *   Add entity types what are you want to skip.
   *
   * @return array
   *   Return number of exported entities grouped by entity type.
   */
  public function exportSite($skipEntityType = '') {
    $count = [];

    $skipEntityType = explode(parent::DELIMITER, $skipEntityType);

    $contentEntityTypes = $this->getContentEntityTypes();

    // Delete files in the content directory before export.
    $this->deleteDirectoryContentRecursively($this->getContentFolder(), FALSE);

    foreach ($contentEntityTypes as $entityType => $entityDefinition) {
      // Skip specified entities in --skip_entity_type option.
      if (!in_array($entityType, $skipEntityType)) {
        $exportedEntities = $this->export($entityType);
        $count[$entityType] = $exportedEntities;
      }
    }

    return $count;
  }

  /**
   * Export url aliases in single json file under alias folder.
   */
  public function exportUrlAliases() {
    $query = $this->database->select('url_alias', 'aliases')
      ->fields('aliases', []);
    $data = $query->execute();
    $results = $data->fetchAll(\PDO::FETCH_OBJ);
    $aliases = [];
    foreach ($results as $row) {
      $aliases[$row->pid] = [
        'source' => $row->source,
        'alias' => $row->alias,
        'langcode' => $row->langcode,
      ];
    }
    $serializedAliases = JSON::encode($aliases);

    $this->exporter->writeDefaultContent([parent::ALIAS_NAME => [parent::ALIAS_NAME => $serializedAliases]], $this->getContentFolder());

    return count($aliases);
  }

  /**
   * Get all entity IDs for export.
   *
   * @param string $entityType
   *   Entity Type.
   * @param string $entityBundle
   *   Entity ID.
   * @param string $entityIds
   *   Entity Bundle.
   * @param string $skipEntities
   *   Entities to skip.
   *
   * @return array
   *   Return array of entity ids.
   */
  protected function getEntityIdsForExport($entityType,
                                           $entityBundle,
                                           $entityIds,
                                           $skipEntities) {
    $exportedEntityIds = [];
    $contentEntityTypes = $this->getContentEntityTypes();
    if (!$this->validateEntityType($entityType, $contentEntityTypes)) {
      // @todo Is any better method how to call writeln()?
      Drush::output()->writeln(dt('List of available content entity types:'));
      Drush::output()->writeln(implode(', ', array_keys($contentEntityTypes)));
      throw new \InvalidArgumentException(sprintf('Entity type "%s" does not exist', $entityType));
    }

    // At first, export entities by entity id from --entity_id parameter.
    if (!empty($entityIds)) {
      $entityIds = explode(parent::DELIMITER, $entityIds);
      $exportedEntityIds += $entityIds;
    }

    // Add all entities by given bundle.
    if (!empty($entityBundle)) {
      $query = $this->entityTypeManager->getStorage($entityType)->getQuery();
      $bundles = explode(parent::DELIMITER, $entityBundle);
      $bundleType = 'type';
      if ($entityType == 'taxonomy_term') {
        $bundleType = 'vid';
      }
      elseif ($entityType == 'menu_link_content') {
        $bundleType = 'menu_name';
      }
      $query->condition($bundleType, $bundles, 'IN');
      $entityIds = $query->execute();
      $exportedEntityIds += $entityIds;
    }

    // If still no entities to export, export all entities of given type.
    if (empty($exportedEntityIds)) {
      $query = $this->entityTypeManager->getStorage($entityType)->getQuery();
      $entityIds = $query->execute();
      $exportedEntityIds += $entityIds;
    }

    // Explode skip entities.
    $skipEntities = explode(parent::DELIMITER, $skipEntities);

    // Diff entityIds against skipEntities.
    $exportedEntityIds = array_diff($exportedEntityIds, $skipEntities);

    return $exportedEntityIds;
  }

  /**
   * Validate the entity type.
   *
   * @param string $entityType
   *   Validated entity type.
   * @param array $contentEntityTypes
   *   Array of entity definitions keyed by type.
   *
   * @return bool
   *   TRUE if entity type is valid.
   */
  protected function validateEntityType($entityType,
                                        array $contentEntityTypes) {
    if (array_key_exists($entityType, $contentEntityTypes)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Get all Content Entity Types.
   *
   * @return array
   *   Array of available content entity definitions keyed by type ID.
   *   [entity_type => \Drupal\Core\Entity\EntityTypeInterface]
   */
  public function getContentEntityTypes() {
    $contentEntityTypes = [];
    $entityTypes = $this->entityTypeManager->getDefinitions();
    /* @var $definition \Drupal\Core\Entity\EntityTypeInterface */
    foreach ($entityTypes as $type => $definition) {
      if ($definition instanceof ContentEntityType) {
        $contentEntityTypes[$type] = $definition;
      }
    }
    ksort($contentEntityTypes);
    return $contentEntityTypes;
  }

}
