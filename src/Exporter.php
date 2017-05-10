<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;

/**
 * A service for handling export of default content.
 */
class Exporter extends DefaultContentDeployBase {

  // Variables delimiter.
  const DELIMITER = ',';

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
    $exportedEntities = [];
    // Get entities for export.
    $exportedEntityIds = $this->getEntityIdsForExport($entityType, $entityBundle, $entityIds, $skipEntities);
    // Serialize entities and get uuids for entities.
    foreach ($exportedEntityIds as $entityId) {
      $exportedEntity = $this->exporter->exportContentWithReferences($entityType, $entityId);
      $deseralizedEntity = $this->serializer->decode($exportedEntity, 'hal_json');
      $uuid = $deseralizedEntity['uuid'][0]['value'];
      $exportedEntities[$entityType][$uuid] = $exportedEntity;
    }
    // Export all entities to folder.
    $this->exporter->writeDefaultContent($exportedEntities, $this->getContentFolder());

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

    $addEntityType = explode(self::DELIMITER, $addEntityType);
    $skipEntityType = explode(self::DELIMITER, $skipEntityType);

    $defualtEntityTypes = [
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

    $defualtEntityTypes += array_unique(array_merge($defualtEntityTypes, $addEntityType));
    $available_entity_types = array_keys($this->entityTypeManager->getDefinitions());

    foreach ($defualtEntityTypes as $entityType) {
      if (!in_array($entityType, $skipEntityType) && in_array($entityType, $available_entity_types)) {
        $exportedEntities = $this->export($entityType);
        $count[$entityType] = $exportedEntities;
      }
    }
    $this->exportUrlAliases();

    return $count;
  }

  /**
   * Export url aliases in single json file under alias folder.
   *
   * @return int|bool
   *   Return number of exported aliases or FALSE.
   */
  public function exportUrlAliases() {
    $folder = $this->getContentFolder() . '/alias';
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
    $json = JSON::encode($aliases);
    $save = file_put_contents($folder . '/url_aliases.json', $json);
    if ($save != FALSE) {
      return count($aliases);
    }
    else {
      return FALSE;
    }
  }

  protected function getEntityIdsForExport($entityType, $entityBundle, $entityIds, $skipEntities) {
    $exportedEntities = [];
    $exportedEntityIds = [];

    // Export by bundle.
    if (!empty($entityBundle)) {
      $query = \Drupal::entityQuery($entityType);
      $bundles = explode(self::DELIMITER, $entityBundle);
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

    // Export by entity id.
    if (!empty($entityId)) {
      $entityIds = explode(self::DELIMITER, $entityIds);
      $exportedEntityIds += $entityIds;
    }

    // Export by entity type if bundles and ids are empty.
    if (empty($exportedEntityIds)) {
      $query = \Drupal::entityQuery($entityType);
      $entityIds = $query->execute();
      $exportedEntityIds += $entityIds;
    }

    // Explode skip entities.
    $skipEntities = explode(self::DELIMITER, $skipEntities);

    // Diff entityIds against skipEntities.
    $exportedEntityIds = array_diff($exportedEntityIds, $skipEntities);

    return $exportedEntityIds;
  }

}
