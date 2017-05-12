<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Serialization\Json;
use Drupal\default_content\Event\DefaultContentEvents;
use Drupal\default_content\Event\ImportEvent;

/**
 * A service for handling import of default content.
 */
class Importer extends DefaultContentDeployBase {

  /**
   * @var \Drupal\Core\Path\AliasStorage
   */
  protected $pathAliasStorage;

  public function __construct() {
    parent::__construct();
    $this->pathAliasStorage = \Drupal::service('path.alias_storage');
  }

  /**
   * @return array
   */
  public function import() {
    $created = [];
    $result_info = [
      'processed' => 0,
      'created'   => 0,
      'updated'   => 0,
      'skipped'   => 0,
    ];
    $folder = $this->getContentFolder();

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
            dpm(
              'Duplicity found for uuid = ' . $item_uuid . ' (in ' . $args['@first'] . '). '
              . 'File ' . $args['@second'] . ' ignored'
            );
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
            ->info(
              'Entity (type: @type/@bundle, ID: @id) @method successfully from @file',
              $saved_entity_log_info
            );
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
   * Import url aliases.
   *
   * @return array
   *   Return number of imported or skipped aliases.
   */
  public function importUrlAliases() {
    $path_alias_storage = $this->pathAliasStorage;
    $count = 0;
    $skipped = 0;
    $file = $this->getContentFolder() . '/' . parent::ALIASNAME . '/' . parent::ALIASNAME . '.json';
    if (!file_destination($file, FILE_EXISTS_ERROR)) {
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
    }

    return ['imported' => $count, 'skipped' => $skipped];
  }

}
