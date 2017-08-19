<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\ContentEntityType;

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
  public function export($entityType, $entityBundle = '', $entityIds = '', $skipEntities = '') {
    $contentEntityTypes = $this->getContentEntityTypes();
    if (!$this->validateEntityType($entityType, $contentEntityTypes)) {
      drush_print(t('List of available content entity types:'));
      drush_print_r(array_keys($contentEntityTypes));
      throw new \InvalidArgumentException(sprintf('Entity type "%s" does not exist', $entityType));
    }

    $exportedEntities = [];
    // Get entities for export.
    $exportedEntityIds = $this->getEntityIdsForExport($entityType, $entityBundle, $entityIds, $skipEntities);
    // Serialize entities and get uuids for entities.
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
   * Export entites by entity type, id or bundle with references.
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
  public function exportWithReferences($entityType, $entityBundle = '', $entityIds = '', $skipEntities = '') {
    // Get entities for export.
    $exportedEntityIds = $this->getEntityIdsForExport($entityType, $entityBundle, $entityIds, $skipEntities);
    // Serialize entities and get uuids for entities.
    foreach ($exportedEntityIds as $entityId) {
      $exportedEntityByType = $this->exporter->exportContentWithReferences($entityType, $entityId);
      $this->exporter->writeDefaultContent($exportedEntityByType, $this->getContentFolder());
    }

    return count($exportedEntityIds);
  }

  /**
   * Export complete site.
   *
   * @param string $addEntityType
   *   Add entity types what are you want to export.
   * @param string $skipEntityType
   *   Add entity types what are you want to skip.
   *
   * @return array
   *   Return number of exported entites grouped by entity type.
   */
  public function exportSite($addEntityType = '', $skipEntityType = '') {
    $count = [];

    $addEntityType = explode(parent::DELIMITER, $addEntityType);
    $skipEntityType = explode(parent::DELIMITER, $skipEntityType);

    $defaultEntityTypes = [
      'block_content',
      'comment',
      'file',
      'node',
      'menu_link_content',
      'taxonomy_term',
      'user',
      'media',
      'paragraph',
    ];

    $defaultEntityTypes += array_unique(array_merge($defaultEntityTypes, $addEntityType));
    $contentEntityTypes = $this->getContentEntityTypes();

    foreach ($defaultEntityTypes as $entityType) {
      if (!in_array($entityType, $skipEntityType) && in_array($entityType, array_keys($contentEntityTypes))) {
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
  protected function getEntityIdsForExport($entityType, $entityBundle, $entityIds, $skipEntities) {
    $exportedEntityIds = [];
    $contentEntityTypes = $this->getContentEntityTypes();
    if (!$this->validateEntityType($entityType, $contentEntityTypes)) {
      drush_print(t('List of available content entity types:'));
      drush_print_r(array_keys($contentEntityTypes));
      throw new \InvalidArgumentException(sprintf('Entity type "%s" does not exist', $entityType));
    }

    // At first, export entities by entity id from --entity_id parameter.
    if (!empty($entityIds)) {
      $entityIds = explode(parent::DELIMITER, $entityIds);
      $exportedEntityIds += $entityIds;
    }

    // Add all entities by given bundle.
    if (!empty($entityBundle)) {
      $query = \Drupal::entityQuery($entityType);
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
      $query = \Drupal::entityQuery($entityType);
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
   * @param string $entityType
   *   Validated entity type.
   * @param array $contentEntityTypes
   *   Array of entity definitions keyed by type.
   *
   * @return bool
   *   TRUE if entity type is valid.
   */
  protected function validateEntityType($entityType, array $contentEntityTypes) {
    if (array_key_exists($entityType, $contentEntityTypes)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Get Content Entity Types
   *
   * @return array
   *   Array of available content entity definitions keyed by type ID.
   */
  protected function getContentEntityTypes() {
    $contentEntityTypes = [];
    $entityTypes = $this->entityTypeManager->getDefinitions();
    /* @var $definition \Drupal\Core\Entity\EntityTypeInterface */
    foreach ($entityTypes as $type => $definition) {
      if ($definition instanceof ContentEntityType) {
        $contentEntityTypes[$type] = $definition;
      }
    }
    return $contentEntityTypes;
  }

}
