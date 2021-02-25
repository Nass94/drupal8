<?php

namespace Drupal\Tests\field_encrypt\Functional;

use Drupal\Tests\encrypt\Functional\EncryptTestBase;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\Traits\Core\CronRunTrait;

/**
 * Tests Field encrypt's use_eval_for_entity_hooks setting.
 *
 * @group field_encrypt
 */
class EntityHooksTest extends EncryptTestBase {

  use CronRunTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'field',
    'text',
    'key',
    'encrypt',
    'encrypt_test',
    'field_encrypt',
    'field_encrypt_test',
  ];

  /**
   * {@inheritdoc}
   *
   * @TODO: Simplify setUp() by extending EncryptTestBase when https://www.drupal.org/node/2692387 lands.
   */
  protected function setUp() {
    parent::setUp();

    // Disable eval().
    $settings['settings']['field_encrypt.use_eval_for_entity_hooks'] = (object) [
      'value' => FALSE,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);

    // Create an admin user.
    $this->adminUser = $this->drupalCreateUser([
      'access administration pages',
      'administer encrypt',
      'administer keys',
      'administer field encryption',
    ], NULL, TRUE);
    $this->drupalLogin($this->adminUser);

    // Create content type to test.
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);

    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'encryption_profile_1')
      ->save();
  }

  /**
   * Set up base fields for test.
   *
   * @param bool $encryption
   *   Whether or not the fields should be encrypted. Defaults to TRUE.
   */
  protected function setFieldStorageSettings($encryption = TRUE) {
    // Set up storage settings for first field.
    $this->drupalGet('admin/config/system/field-encrypt/entity-types');
    $this->assertSession()->fieldExists('entity_type')->selectOption('Content');
    $this->submitForm([], 'Save configuration');
    if ($encryption) {
      $this->assertSession()->fieldExists('base_fields[title]')->check();
    }
    else {
      $this->assertSession()->fieldExists('base_fields[title]')->uncheck();
    }
    $this->submitForm([], 'Save configuration');
    $this->assertSession()->pageTextContains('Updated encryption settings for Content base fields.');
    $this->rebuildAll();
  }

  /**
   * Tests field_encrypt prints code on status report when eval() disabled.
   */
  public function testStatusReport() {
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->pageTextNotContains('Field Encrypt entity hooks');
    $this->setFieldStorageSettings(TRUE);
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->pageTextContains('Field Encrypt entity hooks');
    $this->assertSession()->pageTextContains('function field_encrypt_node_insert(');
    $this->assertSession()->pageTextContains('function field_encrypt_node_update(');

    // Enable eval().
    $settings['settings']['field_encrypt.use_eval_for_entity_hooks'] = (object) [
      'value' => TRUE,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
    $this->rebuildAll();
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->pageTextNotContains('Field Encrypt entity hooks');
  }

}
