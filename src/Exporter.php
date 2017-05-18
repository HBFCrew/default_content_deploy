<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;

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
    if (!$this->validateEntityType($entityType)) {
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

    return $count;
  }

  /**
   * Export url aliases in single json file under alias folder.
   */
  public function exportUrlAliases() {
    $query = $this->database->select('url_alias', 'aliases')->fields('aliases', []);
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
   * Get entity ids.
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
   *   Return array of entitiy ids.
   */
  protected function getEntityIdsForExport($entityType, $entityBundle, $entityIds, $skipEntities) {
    $exportedEntityIds = [];
    if (!$this->validateEntityType($entityType)) {
      throw new \InvalidArgumentException(sprintf('Entity type "%s" does not exist', $entityType));
    }

    // Export by bundle.
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

    // Export by entity id.
    if (!empty($entityIds)) {
      $entityIds = explode(parent::DELIMITER, $entityIds);
      $exportedEntityIds += $entityIds;
    }

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

  protected function validateEntityType($entityType) {
    $validEntityTypes = $this->entityTypeManager->getDefinitions();
    if (array_key_exists($entityType, $validEntityTypes)) {
      return TRUE;
    }

    return FALSE;
  }

}
