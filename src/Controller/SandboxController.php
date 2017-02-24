<?php

namespace Drupal\default_content_deploy\Controller;

use Drupal\Core\Controller\ControllerBase;

Class SandboxController extends  \Drupal\Core\Controller\ControllerBase {
  public function sandbox() {

    dpr('Deployment test start...');

    \Drupal::service('default_content_deploy.deploy')->deployContent();

    dpr('Deployment test end.');

    return array();

  }
}
