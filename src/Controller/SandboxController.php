<?php

namespace Drupal\default_content_deploy\Controller;

use Drupal\Core\Controller\ControllerBase;

Class SandboxController extends  \Drupal\Core\Controller\ControllerBase {
  public function sandbox() {

    dpr('Deployment test start...');

    \Drupal::service('default_content_deploy.importer')->deployContent(TRUE);

    dpr('Deployment test end.');

    return array();

  }
}
