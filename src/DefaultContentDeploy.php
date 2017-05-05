<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityInterface;
use Drupal\default_content\Event\DefaultContentEvents;
use Drupal\default_content\Event\ImportEvent;

/**
 * A service for handling import of default content.
 */
class DefaultContentDeploy {

  private $database;

  private $importer;

  private $exporter;

  private $settings;

  private $entityTypeManager;

  public function __construct() {
    $this->database = \Drupal::database();
    $this->importer = \Drupal::service('default_content.importer');
    $this->exporter = \Drupal::service('default_content.exporter');
    $this->settings = \Drupal::service('settings');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
  }

  public function sandbox() {
    dpm('Debug start.');
  }

  public function importFiles($path_to_content_json) {
    list($entity_type_id, $filename) = explode('/', $path_to_content_json);
    $p = drupal_get_path('profile', 'guts');
    $encoded_content = file_get_contents($p . '/content/' . $path_to_content_json);
    $serializer = \Drupal::service('serializer');
    $content = $serializer->decode($encoded_content, 'hal_json');
    global $base_url;
    $url = $base_url . base_path();
    $content['_links']['type']['href'] = str_replace('http://drupal.org/', $url, $content['_links']['type']['href']);
    $contents = $serializer->encode($content, 'hal_json');
    $class = 'Drupal\\' . $entity_type_id . '\Entity\\' . str_replace(' ', '', ucwords(str_replace('_', ' ', $entity_type_id)));
    $entity = $serializer->deserialize($contents, $class, 'hal_json', ['request_method' => 'POST']);
    $entity->enforceIsNew(TRUE);
    $entity->save();
  }

  /**
   * @param $module
   *
   * @return array
   * @throws \Exception
   *
   * @var $entity EntityInterface
   */
  public function deployContent($module = 'default_content_deploy') {
    $created = [];
    $result_info = [
      'processed' => 0,
      'created'   => 0,
      'updated'   => 0,
      'skipped'   => 0,
    ];
    $folder = drupal_get_path('module', $module) . "/content";

    if (file_exists($folder)) {
      $file_map = [];
      foreach ($this->entityManager->getDefinitions() as $entity_type_id => $entity_type) {
        $reflection = new \ReflectionClass($entity_type->getClass());
        // We are only interested in importing content entities.
        if ($reflection->implementsInterface('\Drupal\Core\Config\Entity\ConfigEntityInterface')) {
          continue;
        }
        if (!file_exists($folder . '/' . $entity_type_id)) {
          continue;
        }
        $files = $this->scanner()->scan($folder . '/' . $entity_type_id);
        // Default content uses drupal.org as domain.
        // @todo Make this use a uri like default-content:.
        $this->linkManager->setLinkDomain(static::LINK_DOMAIN);
        // Parse all of the files and sort them in order of dependency.
        foreach ($files as $file) {
          dpr('Processing file: ' . $file->name);
          $contents = $this->parseFile($file);
          // Decode the file contents.
          $decoded = $this->serializer->decode($contents, 'hal_json');
          // Get the link to this entity.
          $item_uuid = $decoded['uuid'][0]['value'];

          // Throw an exception when this UUID already exists.
          if (isset($file_map[$item_uuid])) {
            $args = [
              '@uuid'   => $item_uuid,
              '@first'  => $file_map[$item_uuid]->uri,
              '@second' => $file->uri,
            ];
            // There is more files for unique content. Ignore all except first.
            \Drupal::logger('default_content_deploy')
              ->warning('Default content with uuid @uuid exists twice: @first and @second', $args);
            dpm('Duplicity found for uuid = ' . $item_uuid . ' (in ' . $args['@first'] . '). '
              . 'File ' . $args['@second'] . ' ignored');
            // Reset link domain.
            $this->linkManager->setLinkDomain(FALSE);
            continue;
          }

          // Store the entity type with the file.
          $file->entity_type_id = $entity_type_id;
          // Store the file in the file map.
          $file_map[$item_uuid] = $file;
          // Create a vertex for the graph.
          $vertex = $this->getVertex($item_uuid);
          $this->graph[$vertex->id]['edges'] = [];
          if (empty($decoded['_embedded'])) {
            // No dependencies to resolve.
            continue;
          }
          // Here we need to resolve our dependencies:
          foreach ($decoded['_embedded'] as $embedded) {
            foreach ($embedded as $item) {
              $uuid = $item['uuid'][0]['value'];
              $edge = $this->getVertex($uuid);
              $this->graph[$vertex->id]['edges'][$edge->id] = TRUE;
            }
          }
        }
      }

      // @todo what if no dependencies?
      $sorted = $this->sortTree($this->graph);
      foreach ($sorted as $link => $details) {
        if (!empty($file_map[$link])) {

          $result_info['processed']++;

          $file = $file_map[$link];
          dpr('After sort parsing file: ' . $file->name);
          $entity_type_id = $file->entity_type_id;
          $resource = $this->resourcePluginManager->getInstance(['id' => 'entity:' . $entity_type_id]);
          $definition = $resource->getPluginDefinition();
          $contents = $this->parseFile($file);
          $class = $definition['serialization_class'];
          $entity = $this->serializer->deserialize($contents, $class, 'hal_json', ['request_method' => 'POST']);

          // Test if entity already exists.
          if ($old_entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $entity->uuid())) {
            // Yes, entity exists. Get last update timestamps if available.
            if (method_exists($old_entity, 'getChangedTime')) {
              $old_entity_changed_time = $old_entity->getChangedTime();
              $entity_changed_time = $entity->getChangedTime();
            }
            else {
              // This entity doesn't support changed time property.
              // We are not able to get changed time, so we will force entity update.
              $old_entity_changed_time = 0;
              $entity_changed_time = 1;
              dpr('Entity update forced.');
            }

            // Check if destination entity is older than existing content.
            if ($old_entity_changed_time < $entity_changed_time) {
              // Update entity.
              //dpr('Existing Utime = ' . date('Y-m-d H:i:s', $old_entity_changed_time));
              //dpr('Imported Utime = ' . date('Y-m-d H:i:s', $entity_changed_time));
              $entity->{$entity->getEntityType()
                ->getKey('id')} = $old_entity->id();
              $entity->setOriginalId($old_entity->id());
              $entity->enforceIsNew(FALSE);
              try {
                $entity->setNewRevision(FALSE);
              }
              catch (\LogicException $e) {
              }
            }
            else {
              // Skip entity. No update.

              //dpr('Skipped. Newer or the same content already exists.');
              $result_info['skipped']++;
              continue;
            }
          }
          else {
            // Entity is not exists - let's create new one.
            $entity->enforceIsNew(TRUE);
          }

          // Store saving method for logger.
          $saving_method = $entity->IsNew() ? 'created' : 'updated';
          // Save entity.
          $entity->save();
          $created[$entity->uuid()] = $entity;
          $result_info[$saving_method]++;

          $saved_entity_log_info = [
            '@type'   => $entity->getEntityTypeId(),
            '@bundle' => $entity->bundle(),
            '@id'     => $entity->id(),
            '@method' => $saving_method,
            '@file'   => $file->name,
          ];
          \Drupal::logger('default_content_deploy')
            ->info('Entity (type: @type/@bundle, ID: @id) @method successfully from @file',
              $saved_entity_log_info);
          //kpr($saved_entity_log_info);
        }
      }
      $this->eventDispatcher->dispatch(DefaultContentEvents::IMPORT, new ImportEvent($created, $module));
    }
    // Reset the tree.
    $this->resetTree();
    // Reset link domain.
    $this->linkManager->setLinkDomain(FALSE);

    //kpr($result_info);
    return $result_info;
  }

  public function get_serializer() {
    return $this->serializer;
  }

  private function getContentFolder() {
    global $config_directories;
    if (isset($config_directories) && isset($config_directories['content'])) {
      return $config_directories['content'];
    }
    else {
      $hash_salt = $this->settings->getHashSalt();
      return 'public://content_' . $hash_salt;
    }
  }

  public function export($entity_type_id, $entity_id, $bundle, $skip_entities = NULL) {
    $folder = $this->getContentFolder();
    $exportedEntities = array();
    $exportedEntitieIds = array();
    if (!empty($skip_entities)) {
      $skip_entities = explode(',', $skip_entities);
    }

    // Export by entity_id.
    if (!is_null($entity_id)) {
      $entity_ids = explode(',', $entity_id);
      foreach ($entity_ids as $entity_id) {
        if (is_numeric($entity_id)) {
          //$path = $folder . '/' . $entity_type_id;
          $exportedEntitieIds[] = $entity_id;
        }
      }
    }
    // Export by bundle.
    else {
      $query = \Drupal::entityQuery($entity_type_id);
      if (!is_null($bundle)) {
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

      foreach ($entity_ids as $entity_id) {
        if (!in_array($entity_id, $skip_entities)) {
          $exportedEntitieIds[] = $entity_id;
        }
      }
    }
    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $entities = $storage->loadMultiple($exportedEntitieIds);

    foreach ($entities as $entity) {
      $exportedEntities[$entity_type_id][$entity->uuid()] = $this->exporter->exportContent($entity_type_id, $entity->id());
    }

    $this->exporter->writeDefaultContent($exportedEntities, $folder);
    return count($exportedEntities);
  }

  public function exportWithReferences($entity_type_id, $entity_id, $bundle, $skip_entities = array(), $skip_core_users = TRUE) {
    $folder = drupal_get_path('module', 'default_content_deploy') . '/content';
    $count = 0;

    if (!empty($skip_entities)) {
      $skip_entities = explode(',', $skip_entities);
    }

    // Export by entity_id.
    if (!is_null($entity_id)) {
      $entity_ids = explode(',', $entity_id);
      foreach ($entity_ids as $entity_id) {
        if (is_numeric($entity_id)) {
          $serialized_by_type = $this->exportContentWithReferences($entity_type_id, $entity_id, $skip_core_users);
          $this->writeDefaultContent($serialized_by_type, $folder);
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
          $serialized_by_type = $this->exportContentWithReferences($entity_type_id, $entity_id, $skip_core_users);
          $this->writeDefaultContent($serialized_by_type, $folder);
          $count++;
        }
      }
    }

    return $count;
  }

  public function exportSite($add_entity_type = array(), $skip_entity_type = array()) {
    $folder = drupal_get_path('module', 'default_content_deploy') . '/content';
    $count = array();

    if (!empty($add_entity_type)) {
      $add_entity_type = explode(',', $add_entity_type);
    }

    if (!empty($skip_entity_type)) {
      $skip_entity_type = explode(',', $skip_entity_type);
    }

    $defualt_entity_types = array(
      'block_content',
      'comment',
      'file',
      'node',
      'menu_link_content',
      'taxonomy_term',
      'user',
      'media',
      'paragraph',
    );

    $defualt_entity_types += array_unique(array_merge($defualt_entity_types, $add_entity_type));
    $available_entity_types = array_keys(\Drupal::entityTypeManager()->getDefinitions());

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
    $this->exportAliases();

    return $count;
  }

  public function exportAliases() {
    $folder = drupal_get_path('module', 'default_content_deploy') . '/alias';
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
    $json = JSON::encode($aliases);
    $save = file_put_contents($folder . '/url_aliases.json', $json);
    if ($save != FALSE) {
      return count($aliases);
    }
    else {
      return FALSE;
    }
  }

  public function importAliases() {
    $path_alias_storage = \Drupal::service('path.alias_storage');
    $count = 0;
    $skipped = 0;
    $file = drupal_get_path('module', 'default_content_deploy') . '/alias/url_aliases.json';
    $aliases = file_get_contents($file, TRUE);
    $path_aliases = JSON::decode($aliases);

    foreach ($path_aliases as $url => $alias) {
      if (!$path_alias_storage->aliasExists($alias['alias'], $alias['langcode'])) {
        $path_alias_storage->save($alias['source'], $alias['alias'], $alias['langcode']);
        $count++;
      }
      else {
        $skipped++;
      }
    }

    return array('imported' => $count, 'skipped' => $skipped);
  }

  private function saveSingleFile($entity_type_id, $entity_id, $path) {
    /** @var \Drupal\default_content_deploy\DefaultContentDeploy $manager */
    $serializer = $this->get_serializer();
    $export = $this->exportContent($entity_type_id, $entity_id);
    $decoded = $serializer->decode($export, 'hal_json');
    $item_uuid = $decoded['uuid'][0]['value'];
    file_prepare_directory($path, FILE_CREATE_DIRECTORY);
    $file = $path . '/' . $item_uuid . '.json';
    $save = file_put_contents($file, $export);
    return $save;
  }


  /**
   * Set System site, Admin and Anonymous UUIDs and Admin's name
   * and display current values.
   */
  public function uuidSync($uuid_site, $uuid_anonymous, $uuid_admin, $admin_name) {

    // Site UUID.
    $config = \Drupal::config('system.site');
    $current_site_uuid = $config->get('uuid');
    //Tady tomu vubec nerozumim :)
    if (!empty($uuid_site)) {
      if ($current_site_uuid == $uuid_site) {
        /*drush_log(t('No change: system.site UUID is already @uuid',
          ['@uuid' => $uuid_site]), 'warning');*/
      }
      else {
        // Update Site UUID.
        /*drush_print(t('The System.site UUID is going to be changed to @uuid',
          ['@uuid' => $uuid_site]));
        drush_config_set('system.site', 'uuid', $uuid_site);*/
        $config = \Drupal::config('system.site');
        $current_site_uuid = $config->get('uuid');
        //$current_site_uuid = drush_config_get_value('system.site', 'uuid')['system.site:uuid'];
      }
    }

    // Anonymous UUID.
    if (!empty($uuid_anonymous)) {
      $current_uuid_anonymous = $this->updateUserUuid(0, $uuid_anonymous, 'Anonymous');
    }
    else {
      $current_uuid_anonymous = $this->getUuidByUid(0);
    }

    // Admin UUID.
    if (!empty($uuid_admin)) {
      $current_uuid_admin = $this->updateUserUuid(1, $uuid_admin, 'Admin');
    }
    else {
      $current_uuid_admin = $this->getUuidByUid(1);
    }

    // Admin Name.
    $current_name = $this->getUid1Name();
    if (!empty($admin_name)) {
      if ($current_name == $admin_name) {
        // No change.
        /*drush_log(t('No change: Admin\'s name is already @username.',
          ['@username' => $current_name]), 'warning');*/
      }
      else {
        // Update name.
        $current_name = $this->updateAdminName($admin_name);
      }
    }

    return array(
      'current_site_uuid' => $current_site_uuid,
      'current_uuid_anonymous' => $current_uuid_anonymous,
      'current_uuid_admin' => $current_uuid_admin,
      'current_name' => $current_name,
    );
  }

  /**
   * Update UUID of user
   * @param int $uid
   * @param string $uuid
   * @param string $username
   * @return string new UUID
   */
  private function updateUserUuid($uid, $uuid, $username):string {
    $current_uuid = $this->getUuidByUid($uid);
    if ($current_uuid == $uuid) {
      return FALSE;
    }
    else {
      // Perform update UUID for UID.
      $this->updateUuidByUid($uid, $uuid);
      // Get new UUID value.
      $current_uuid = $this->getUuidByUid($uid);
      // Verify value.
      if ($current_uuid == $uuid) {
        return TRUE;
      }
      else {
        return FALSE;
      }
    }
  }

  /**
   * Update user's UUID by UID.
   * @param int $uid
   * @param string $uuid
   */
  private function updateUuidByUid($uid, $uuid) {
    $this->database->update('users')
      ->fields([
        'uuid' => $uuid
      ])
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Get UUId for user by UID.
   * @param $uid
   * @return string
   */
  private function getUuidByUid($uid):string {
    /** @var \Drupal\Core\Database\Driver\mysql\Select $query */
    $query = $this->database->select('users')
      ->fields('users', ['uuid'])
      ->condition('uid', $uid);
    $result = $query->execute()->fetchCol('uuid');
    $current_uuid_anonymous = reset($result);
    return $current_uuid_anonymous;
  }

  /**
   * Get name of Admin user UID=1.
   * @return string Name
   */
  private function getUid1Name() {
    /** @var \Drupal\Core\Database\Driver\mysql\Select $query */
    $query = $this->database->select('users_field_data')
      ->fields('users_field_data', ['name'])
      ->condition('uid', 1);
    $result = $query->execute()->fetchCol('name');
    return reset($result);
  }

  /**
   * Update Admin's name.
   * @param string $admin_name
   * @return string Current Admin name.
   */
  private function updateAdminName($admin_name) {
    $this->database->update('users_field_data')
      ->fields([
        'name' => $admin_name
      ])
      ->condition('uid', 1)
      ->execute();
    $current_name = $this->getUid1Name();
    // Validation
    return $current_name;
  }
}
