<?php

namespace Drupal\default_content_deploy\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\default_content_deploy\Importer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config Form for run DCD deploy in Admin UI.
 */
class ImportForm extends FormBase {

  /**
   * Default Content Deploy Importer object.
   *
   * @var \Drupal\default_content_deploy\Importer
   */
  private $importer;


  /**
   * ImportForm constructor.
   *
   * @param \Drupal\default_content_deploy\Importer $importer
   * @param \Drupal\Core\Messenger\Messenger $messenger
   */
  public function __construct(Importer $importer, Messenger $messenger ) {
    $this->importer = $importer;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('default_content_deploy.importer'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dcd_import_form';
  }

  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames() {
    return ['default_content_deploy.import'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['force-update'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force update'),
      '#description' => $this->t('Existing content with different UUID will be deleted and created again (recommended for better content synchronization).'),
      '#default_value' => TRUE,
    ];
    $form['force-override'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force override'),
      '#description' => $this->t('All existing content will be overridden (locally updated default content will be reverted to the state defined in a content directory).'),
      '#default_value' => FALSE,
    ];

    $form['import'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import content'),
    ];

    return $form;
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @throws \Exception
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $forceUpdate = $form_state->getValue('force-update', FALSE);
    $forceOverride = $form_state->getValue('force-override', FALSE);
    $result_info = $this->importer->deployContent($forceUpdate, $forceOverride, TRUE);

    $message = $this->t('@count entities have been imported.', ['@count' => $result_info['processed']]);
    $message .= " ";
    $message .= $this->t('created: @count', ['@count' => $result_info['created']]);
    $message .= ', ';
    $message .= $this->t('updated: @count', ['@count' => $result_info['updated']]);
    $message .= ', ';
    $message .= $this->t('skipped: @count', ['@count' => $result_info['skipped']]);
    $this->messenger->addMessage($message);
  }

}
