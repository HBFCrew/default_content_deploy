<?php

namespace Drupal\default_content_deploy;

use Drupal\Component\Graph\Graph;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Entity;
use Drupal\user\EntityOwnerInterface;


/**
 * A service for handling import of default content.
 *
 * The importContent() method is almost duplicate of
 *   \Drupal\default_content\Importer::importContent with injected code for
 *   content update. We are waiting for better DC code structure in a future.
 *
 * @todo Try to extend DC Importer class when possible.
 */
class Importer extends DefaultContentDeployBase {

  /**
   * @var \Drupal\Core\Path\AliasStorage
   */
  protected $pathAliasStorage;
  protected $accountSwitcher;
  protected $linkManager;
  protected $scanner;
  protected $linkDomain;

  /**
   * A list of vertex objects keyed by their link.
   *
   * Cloned!!!
   *
   * @var array
   */
  protected $vertexes = [];

  /**
   * The graph entries.
   *
   * Cloned!!!
   *
   * @var array
   */
  protected $graph = [];

  public function __construct() {
    parent::__construct();
    $this->pathAliasStorage = \Drupal::service('path.alias_storage');
    $this->accountSwitcher = \Drupal::service('account_switcher');
    $this->linkManager = \Drupal::service('hal.link_manager');
    $this->scanner = \Drupal::service('default_content.scanner');
    $this->linkDomain = 'http://drupal.org';
  }

  public function importContent() {
    $created = [];
    $result_info = [
      'processed' => 0,
      'created' => 0,
      'updated' => 0,
      'skipped' => 0,
    ];
    $folder = $this->getContentFolder();

    if (file_exists($folder)) {
      $root_user = $this->entityTypeManager->getStorage('user')->load(1);
      $this->accountSwitcher->switchTo($root_user);
      $file_map = [];
      /** @var \Drupal\Core\Entity\EntityType $entity_type */
      foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
        $reflection = new \ReflectionClass($entity_type->getClass());
        // We are only interested in importing content entities.
        if ($reflection->implementsInterface(ConfigEntityInterface::class)) {
          continue;
        }
        // Skip entities without folder for import.
        elseif (!file_exists($folder . '/' . $entity_type_id)) {
          continue;
        }
        $files = $this->scanner->scan($folder . '/' . $entity_type_id);
        // Default content uses drupal.org as domain.
        // @todo Make this use a uri like default-content:.
        $this->linkManager->setLinkDomain($this->linkDomain);
        // Parse all of the files and sort them in order of dependency.
        foreach ($files as $file) {
          $contents = $this->parseFile($file);
          // Decode the file contents.
          $decoded = $this->serializer->decode($contents, 'hal_json');
          // Get the link to this entity.
          $item_uuid = $decoded['uuid'][0]['value'];

          // Throw an exception when this UUID already exists.
          if (isset($file_map[$item_uuid])) {
            // Reset link domain.
            $this->linkManager->setLinkDomain(FALSE);
            throw new \Exception(sprintf('Default content with uuid "%s" exists twice: "%s" "%s"', $item_uuid, $file_map[$item_uuid]->uri, $file->uri));
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
          $file = $file_map[$link];
          $entity_type_id = $file->entity_type_id;
          $class = $this->entityTypeManager->getDefinition($entity_type_id)
            ->getClass();
          $contents = $this->parseFile($file);
          /** @var \Drupal\Core\Entity\Entity $entity */
          $entity = $this->serializer->deserialize($contents, $class, 'hal_json', ['request_method' => 'POST']);


          // Here is start of injected code for Entity update.
          // Test if entity (defined by UUID) already exists.
          // @todo Replace deprecated entityManager().
          if ($old_entity = \Drupal::entityManager()
            ->loadEntityByUuid($entity_type_id, $entity->uuid())
          ) {
            // Yes, entity exists.
            // Get the last update timestamps if available.
            if (method_exists($old_entity, 'getChangedTime')) {
              /** @var \Drupal\Core\Entity\EntityChangedTrait $entity */
              $old_entity_changed_time = $old_entity->getChangedTime();
              $entity_changed_time = $entity->getChangedTime();
            }
            elseif (FALSE) {
              // @todo Try another method for update test, f.e compare file time.
            }
            else {
              // We are not able to get updated time of entity, so we will force entity update.
              $old_entity_changed_time = 0;
              $entity_changed_time = 1;
            }

            $this->printEntityTimeInfo($entity, $old_entity_changed_time, $entity_changed_time);

            // Check if destination entity is older than existing content.
            if ($old_entity_changed_time < $entity_changed_time) {
              // Update existing older entity with newer one.
              /** @var \Drupal\Core\Entity\Entity $entity */
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
              // Skip entity. No update. Newer or the same content
              // already exists.
              $result_info['skipped']++;
              continue;
            }
          }
          else {
            // Imported entity is not exists - let's create new one.
            $entity->enforceIsNew(TRUE);
          }

          // Ensure that the entity is not owned by the anonymous user.
          if ($entity instanceof EntityOwnerInterface && empty($entity->getOwnerId())) {
            $entity->setOwner($root_user);
          }
          // Fill the saving method info for logger and report.
          $saving_method = $entity->isNew() ? 'created' : 'updated';

          $entity->save();
          $created[$entity->uuid()] = $entity;
          $result_info[$saving_method]++;

          $saved_entity_log_info = [
            '@type' => $entity->getEntityTypeId(),
            '@bundle' => $entity->bundle(),
            '@id' => $entity->id(),
            '@method' => $saving_method,
            '@file' => $file->name,
          ];
          \Drupal::logger('default_content_deploy')
            ->info(
              'Entity (type: @type/@bundle, ID: @id) @method successfully from @file',
              $saved_entity_log_info
            );

        }
      }
      //$this->eventDispatcher->dispatch(DefaultContentEvents::IMPORT, new ImportEvent($created, $module));
      $this->accountSwitcher->switchBack();
    }
    // Reset the tree.
    $this->resetTree();
    // Reset link domain.
    $this->linkManager->setLinkDomain(FALSE);

    return $result_info;
  }


  /**
   * @param $path_to_content_json
   * @todo Unused? Check usage of this method.
   */
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

  /**
   * @param \Drupal\Core\Entity\Entity $entity
   * @param $old_entity_changed_time
   * @param $entity_changed_time
   */
  protected function printEntityTimeInfo(Entity $entity, $old_entity_changed_time, $entity_changed_time) {
    print("\n");
    print("\n");
    print('Label: ' . $entity->label());
    print("\n");
    print('Entity type/bundle = ' . $entity->getEntityType()
        ->getLabel() . '/' . $entity->bundle());
    print("\n");

    print('Existing Utime = ' . date('Y-m-d H:i:s', $old_entity_changed_time));
    print("\n");
    print('Imported Utime = ' . date('Y-m-d H:i:s', $entity_changed_time));
    print("\n");
  }



  // Imported methods (cloned from \Drupal\default_content\Importer)
  // @todo Find some better solution and avoid code duplication.

  /**
   * Parses content files.
   *
   * @param object $file
   *   The scanned file.
   *
   * @return string
   *   Contents of the file.
   */
  protected function parseFile($file) {
    return file_get_contents($file->uri);
  }

  /**
   * Resets tree properties.
   */
  protected function resetTree() {
    $this->graph = [];
    $this->vertexes = [];
  }

  /**
   * Sorts dependencies tree.
   *
   * @param array $graph
   *   Array of dependencies.
   *
   * @return array
   *   Array of sorted dependencies.
   */
  protected function sortTree(array $graph) {
    $graph_object = new Graph($graph);
    $sorted = $graph_object->searchAndSort();
    uasort($sorted, 'Drupal\Component\Utility\SortArray::sortByWeightElement');
    return array_reverse($sorted);
  }

  /**
   * Returns a vertex object for a given item link.
   *
   * Ensures that the same object is returned for the same item link.
   *
   * @param string $item_link
   *   The item link as a string.
   *
   * @return object
   *   The vertex object.
   */
  protected function getVertex($item_link) {
    if (!isset($this->vertexes[$item_link])) {
      $this->vertexes[$item_link] = (object) ['id' => $item_link];
    }
    return $this->vertexes[$item_link];
  }

}
