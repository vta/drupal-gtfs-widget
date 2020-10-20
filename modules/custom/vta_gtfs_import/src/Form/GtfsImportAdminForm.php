<?php

namespace Drupal\vta_gtfs_import\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\vta_gtfs_import\Services\VtaGtfsImportService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contains GTFS Import Admin form.
 */
class GtfsImportAdminForm extends ConfigFormBase {

  /**
   * Stores runtime messages sent out to individual users on the page.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * Provides a manager service for GTFS Import.
   *
   * @var \Drupal\vta_gtfs_import\Services\VtaGtfsImportService
   */
  protected $importManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('vta_gtfs_import.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(Messenger $messenger, VtaGtfsImportService $import_manager) {
    $this->messenger = $messenger;
    $this->importManager = $import_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['vta_gtfs_import.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'vta_gtfs_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('vta_gtfs_import.settings');

    /******************************
     * Automated GTFS Import settings
     * - Help
     * - GTFS File URL
     * - Notification List
     *****************************
     * - Actions
     *****************************/
    $form['automated_gtfs_import_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Automated GTFS Import settings'),
    ];

    /******************************
     * Help
     *****************************/
    $form['automated_gtfs_import_settings']['help'] = [
      '#markup' => $this->t('<p>Configure settings relating to the Automated GTFS Import. The specified GTFS File URL will be used in all future GTFS Imports and the GTFS Notification List will notify specified emails in case of an error with the GTFS Import.</p>'),
    ];

    /******************************
     * GTFS File URL
     *****************************/
    $form['automated_gtfs_import_settings']['gtfs_file_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GTFS File URL'),
      '#description' => $this->t('Specify the GTFS File URL that will be used to grab the most up-to-date GTFS zip file.'),
      '#default_value' => ($config->get('gtfs_file_url')) ? $config->get('gtfs_file_url') : '',
      '#size' => 120,
      '#maxlength' => 512,
      '#required' => TRUE,
    ];

    /******************************
     * Notification List
     *****************************/
    $form['automated_gtfs_import_settings']['gtfs_notification_list'] = [
      '#type' => 'textarea',
      '#title' => $this->t('GTFS Notification List'),
      '#description' => $this->t('Please include a list of emails, one per line, that will receive an email notification if there is an error with the automated GTFS Import.'),
      '#default_value' => ($config->get('gtfs_notification_list')) ? $config->get('gtfs_notification_list') : '',
      '#required' => FALSE,
    ];

    /******************************
     * Actions
     * - Save configuration
     *****************************/
    $form['automated_gtfs_import_settings']['actions']['#type'] = 'actions';
    $form['automated_gtfs_import_settings']['actions']['save'] = [
      '#type' => 'submit',
      '#name' => 'automated_save',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
      '#weight' => 1,
    ];

    /******************************
     * Manual GTFS Import
     * - Help
     * - GTFS File
     * - GTFS Helper Files
     * - Version
     *****************************
     * - Actions
     *****************************/
    $form['manual_gtfs_import'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Manual GTFS Import'),
    ];

    /******************************
     * Help
     *****************************/
    $form['manual_gtfs_import']['help'] = [
      '#markup' => $this->t('<p>Upload new GTFS files / GTFS Helper files to be used in a manual GTFS Import. The uploaded files will overwrite any existing files.</p>'),
    ];

    /******************************
     * GTFS File
     *****************************/
    $form['manual_gtfs_import']['gtfs_files'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('GTFS Files'),
      '#description' => $this->t('Please upload a <em>zip</em> file containing the GTFS files.'),
      '#size' => 20,
      '#upload_location' => 'private://vta_gtfs_import_files/upload',
      '#upload_validators' => [
        'file_validate_extensions' => ['zip'],
      ],
    ];

    /******************************
     * Helper Files
     *****************************/
    $form['manual_gtfs_import']['gtfs_helper_files'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('GTFS Helper Files'),
      '#description' => $this->t('Please upload a <em>zip</em> file containing the GTFS helper files which include: <strong>master_stop_list.csv</strong> and <strong>route_mapping.csv</strong>'),
      '#size' => 20,
      '#upload_location' => 'private://vta_gtfs_import_files/upload/helper',
      '#upload_validators' => [
        'file_validate_extensions' => ['zip'],
      ],
    ];

    /******************************
     * Version
     *****************************/
    $form['manual_gtfs_import']['gtfs_version'] = [
      '#type' => 'radios',
      '#title' => $this->t('GTFS Version'),
      '#description' => $this->t('Please select which GTFS version you are uploading. <strong>This will overwrite all files for the selected version.</strong>'),
      '#options' => [
        'current' => $this->t('Current'),
        'upcoming' => $this->t('Upcoming'),
      ],
    ];

    /******************************
     * Actions
     * - Upload files
     * - Instructions
     * -- Run GTFS Import
     * -- Generate route data
     *****************************/
    $form['manual_gtfs_import']['actions']['#type'] = 'actions';
    $form['manual_gtfs_import']['actions']['upload'] = [
      '#type' => 'submit',
      '#name' => 'manual_upload',
      '#value' => $this->t('Upload files'),
      '#button_type' => 'primary',
      '#weight' => 1,
    ];
    $form['manual_gtfs_import']['actions']['instructions'] = [
      '#type' => 'container',
      '#prefix' => $this->t('<p><strong>In order to manually run the GTFS Import, you must first click <em>Run GTFS Import</em>. Once that has completed, click <em>Generate route data</em>.</strong></p>'),
      '#weight' => 2,
    ];
    $form['manual_gtfs_import']['actions']['instructions']['execute'] = [
      '#type' => 'submit',
      '#name' => 'manual_execute',
      '#value' => $this->t('Run GTFS Import'),
      '#button_type' => 'primary',
      '#weight' => 3,
    ];
    $form['manual_gtfs_import']['actions']['instructions']['generate'] = [
      '#type' => 'submit',
      '#name' => 'manual_generate',
      '#value' => $this->t('Generate route data'),
      '#button_type' => 'primary',
      '#weight' => 3,
    ];

    $messages = $this->messenger->all();
    if (isset($messages['error'])) {
      unset($messages['status']);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $operation = $form_state->getTriggeringElement();

    switch ($operation['#name']) {
      /******************************
       * Automated Save
       *****************************/
      case 'automated_save':
        break;

      /******************************
       * Manual Upload
       * - GTFS Files / GTFS Helper Files
       * - GTFS Version
       *****************************/
      case 'manual_upload':
        // GTFS Files.
        $gtfs_files_fid = reset($form_state->getValue('gtfs_files', 0));
        // GTFS Helper Files.
        $gtfs_helper_files_fid = reset($form_state->getValue('gtfs_helper_files', 0));
        if (empty($gtfs_files_fid) && empty($gtfs_helper_files_fid)) {
          $form_state->setErrorByName('gtfs_files', $this->t('Please specify either GTFS Files or GTFS Helper Files to upload.'));
          $form_state->setErrorByName('gtfs_helper_files', $this->t('Please specify either GTFS Files or GTFS Helper Files to upload.'));
        }

        // GTFS Version.
        $gtfs_version = $form_state->getValue('gtfs_version');
        if (empty($gtfs_version)) {
          $form_state->setErrorByName('gtfs_version', $this->t('Please specify a GTFS Version to upload to.'));
        }

        break;

      /******************************
       * Manual Execute
       *****************************/
      case 'manual_execute':

        break;

      /******************************
       * Manual Generate
       *****************************/
      case 'manual_generate':

        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $operation = $form_state->getTriggeringElement();

    switch ($operation['#name']) {
      /******************************
       * Automated Save
       * - Automated GTFS Import settings
       *****************************/
      case 'automated_save':
        $this->config('vta_gtfs_import.settings')
          ->set('gtfs_file_url', $form_state->getValue('gtfs_file_url'))
          ->set('gtfs_notification_list', $form_state->getValue('gtfs_notification_list'))
          ->save();
        break;

      /******************************
       * Manual Upload
       * - GTFS Files
       * - GTFS Helper Files
       *****************************/
      case 'manual_upload':
        $gtfs_version = $form_state->getValue('gtfs_version');
        if (!empty($gtfs_version)) {
          // GTFS Files.
          $gtfs_files_fid = reset($form_state->getValue('gtfs_files', 0));
          if (!empty($gtfs_files_fid)) {
            try {
              $file = $form['manual_gtfs_import']['gtfs_files']['#files'][$gtfs_files_fid];
              $this->importManager->retrieveFiles($file, 'gtfs_files', $gtfs_version, TRUE);
              // Display status message.
              $this->messenger->addStatus('Manual GTFS Import - GTFS files have been uploaded successfully.');
            }
            catch (\Exception $e) {
              $this->messenger->addError($e->getMessage());
            }
          }

          // GTFS Helper Files.
          $gtfs_helper_files_fid = reset($form_state->getValue('gtfs_helper_files', 0));
          if (!empty($gtfs_helper_files_fid)) {
            try {
              $file = $form['manual_gtfs_import']['gtfs_helper_files']['#files'][$gtfs_helper_files_fid];
              $this->importManager->retrieveFiles($file, 'gtfs_helper_files', $gtfs_version, TRUE);
              // Display status message.
              $this->messenger->addStatus('Manual GTFS Import - GTFS Helper files have been uploaded successfully.');
            }
            catch (\Exception $e) {
              $this->messenger->addError($e->getMessage());
            }
          }
        }

        break;

      /******************************
       * Manual Execute
       * - Run GTFS Import
       *****************************/
      case 'manual_execute':
        try {
          $this->importManager->clean(FALSE);
          $this->importManager->populate(FALSE);
          $this->importManager->process('vta_gtfs_import_get_manual', FALSE);
          $this->importManager->process('vta_gtfs_import_save_manual', FALSE);
          // Display status message.
          $this->messenger->addStatus('Manual GTFS Import - GTFS files have been imported. Now click Generate route data below.');
        }
        catch (\Exception $e) {
          $this->messenger->addError($e->getMessage());
        }

        break;

      /******************************
       * Manual Generate
       * - Generate route data
       *****************************/
      case 'manual_generate':
        try {
          $this->importManager->generate(FALSE);
          // Display status message.
          $this->messenger->addStatus('Manual GTFS Import - Route data has been generated.');
        }
        catch (\Exception $e) {
          $this->messenger->addError($e->getMessage());
        }

        break;
    }
  }

}
