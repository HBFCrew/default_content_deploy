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
   * @param int $entityIds
   *   Entity ID.
   * @param string $entityBundle
   *   Entity Bundle.
   * @param null|array $skipEntities
   *   Entities to skip.
   *
   * @return int
   *   Number of exported entities.
   */
  public function export($entityType, $entityBundle = '', $entityIds = '', $skipEntities = '') {
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
   * Export entities with all references to other entities.
   *
   * @param       $entity_type_id
   * @param       $entity_id
   * @param       $bundle
   * @param array $skip_entities
   * @param bool $skip_core_users
   *
   * @return int
   *   Return number of exported entities.
   */
  public function exportWithReferences($entity_type_id, $entity_id, $bundle, $skip_entities = [], $skip_core_users = TRUE) {
    $folder = $this->getContentFolder();
    $count = 0;

    if (!empty($skip_entities)) {
      $skip_entities = explode(',', $skip_entities);
    }

    // Export by entity_id.
    if (!is_null($entity_id)) {
      $entity_ids = explode(',', $entity_id);
      foreach ($entity_ids as $entity_id) {
        if (is_numeric($entity_id)) {
          $serialized_by_type = $this->exporter->exportContentWithReferences($entity_type_id, $entity_id, $skip_core_users);
          $this->exporter->writeDefaultContent($serialized_by_type, $folder);
          $count++;
        }
      }
    }
    // Export by bundle.
    else {
      $query = \Drupal::entityQuery($entity_type_id);
      if ($bundle != NULL) {
        $bundles = explode(',', $bundle);
        $bundle_type = 'type';
        if ($entity_type_id == 'taxonomy_term') {
          $bundle_type = 'vid';
        }
        elseif ($entity_type_id == 'menu_link_content') {
          $bundle_type = 'menu_name';
        }
        $query->condition($bundle_type, $bundles, 'IN');
      }
      $entity_ids = $query->execute();

      $skip_entities = explode(',', $skip_entities);

      foreach ($entity_ids as $entity_id) {
        if (!in_array($entity_id, $skip_entities)) {
          $serialized_by_type = $this->exporter->exportContentWithReferences($entity_type_id, $entity_id, $skip_core_users);
          $this->exporter->writeDefaultContent($serialized_by_type, $folder);
          $count++;
        }
      }
    }

    return $count;
  }

  /**
   * Export complete site.
   *
   * @param array $add_entity_type
   *   Add entity types what are you want to export.
   * @param array $skip_entity_type
   *   Add entity types what are you want to skip.
   *
   * @return array|string
   *   Return number of exported entites grouped by entity type or path.
   */
  public function exportSite($add_entity_type = [], $skip_entity_type = []) {
    $folder = $this->getContentFolder();
    $count = [];

    if (!empty($add_entity_type)) {
      $add_entity_type = explode(',', $add_entity_type);
    }

    if (!empty($skip_entity_type)) {
      $skip_entity_type = explode(',', $skip_entity_type);
    }

    $defualt_entity_types = [
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

    $defualt_entity_types += array_unique(array_merge($defualt_entity_types, $add_entity_type));
    $available_entity_types = array_keys($this->entityTypeManager->getDefinitions());

    foreach ($defualt_entity_types as $entity_type_id) {
      $count[$entity_type_id] = 0;
      if (!in_array($entity_type_id, $skip_entity_type) && in_array($entity_type_id, $available_entity_types)) {
        $path = $folder . '/' . $entity_type_id;
        if (file_prepare_directory($path, FILE_CREATE_DIRECTORY)) {
          $entity_ids = \Drupal::entityQuery($entity_type_id)->execute();
          foreach ($entity_ids as $entity_id) {
            if (is_numeric($entity_id)) {
              $save = $this->saveSingleFile($entity_type_id, $entity_id, $path);
              if ($save != FALSE) {
                $count[$entity_type_id]++;
              }
            }
          }
        }
        else {
          return $path;
        }
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

}
