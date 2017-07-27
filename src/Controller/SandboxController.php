<?php

namespace Drupal\default_content_deploy\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Class SandboxController
 *
 * Only for testing purposes during development phase. Will be removed.
 *
 * @package Drupal\default_content_deploy\Controller
 */
class SandboxController extends ControllerBase {

  /**
   * Sandbox controller for test.
   *
   * Only for testing purposes during development phase. Will be removed.
   *
   * @return array
   *   Content to render.
   */
  public function sandbox() {
    // \Drupal::service('default_content_deploy.importer')->deployContent(TRUE);
    // $exporter = \Drupal::service('default_content_deploy.exporter');
    return [];
  }

}
