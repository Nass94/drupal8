<?php

namespace Drupal\Tests\field_encrypt\FunctionalJavascript;

use Drupal\encrypt\Entity\EncryptionProfile;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\key\Entity\Key;

/**
 * Tests for the machine name field.
 *
 * @group field
 */
class BaseFieldSettingsFormTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'field_ui',
    'text',
    'locale',
    'content_translation',
    'key',
    'encrypt',
    'encrypt_test',
    'field_encrypt',
  ];

  /**
   * An administrator user.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $adminUser;

  /**
   * A list of test keys.
   *
   * @var \Drupal\key\Entity\Key[]
   */
  protected $testKeys;

  /**
   * A list of test encryption profiles.
   *
   * @var \Drupal\encrypt\Entity\EncryptionProfile[]
   */
  protected $encryptionProfiles;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([
      'access administration pages',
      'administer encrypt',
      'administer keys',
      'administer field encryption',
    ], NULL, TRUE);
    $this->drupalLogin($this->adminUser);
    $this->drupalLogin($this->adminUser);
    $this->createTestKeys();
    $this->createTestEncryptionProfiles();
  }

  /**
   * Tests the base field encryption settings form.
   */
  public function testBaseFieldForm() {
    $assert = $this->assertSession();

    $this->drupalGet('admin/config/system/field-encrypt/entity-types');
    $assert->pageTextNotContains('Base fields to encrypt');
    $assert->fieldExists('entity_type')->selectOption('Content');
    $assert->waitForText('Base fields to encrypt');
    $assert->pageTextNotContains('Title properties');
    $assert->fieldExists('base_fields[title]')->check();
    $assert->waitForText('Title properties');
    $assert->pageTextContains('Title properties');
    $assert->buttonExists('Save configuration')->press();
    $assert->pageTextContains('Updated encryption settings for Content base fields.');
  }

  /**
   * Creates test keys for usage in tests.
   */
  protected function createTestKeys() {
    // Create a 128bit test key.
    $key_128 = Key::create([
      'id' => 'testing_key_128',
      'label' => 'Testing Key 128 bit',
      'key_type' => "encryption",
      'key_type_settings' => ['key_size' => '128'],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'mustbesixteenbit'],
    ]);
    $key_128->save();
    $this->testKeys['testing_key_128'] = $key_128;

    // Create a 256bit test key.
    $key_256 = Key::create([
      'id' => 'testing_key_256',
      'label' => 'Testing Key 256 bit',
      'key_type' => "encryption",
      'key_type_settings' => ['key_size' => '256'],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => 'mustbesixteenbitmustbesixteenbit'],
    ]);
    $key_256->save();
    $this->testKeys['testing_key_256'] = $key_256;
  }

  /**
   * Creates test encryption profiles for usage in tests.
   */
  protected function createTestEncryptionProfiles() {
    // Create test encryption profiles.
    $encryption_profile_1 = EncryptionProfile::create([
      'id' => 'encryption_profile_1',
      'label' => 'Encryption profile 1',
      'encryption_method' => 'test_encryption_method',
      'encryption_key' => $this->testKeys['testing_key_128']->id(),
    ]);
    $encryption_profile_1->save();
    $this->encryptionProfiles['encryption_profile_1'] = $encryption_profile_1;

    $encryption_profile_2 = EncryptionProfile::create([
      'id' => 'encryption_profile_2',
      'label' => 'Encryption profile 2',
      'encryption_method' => 'config_test_encryption_method',
      'encryption_method_configuration' => ['mode' => 'CFB'],
      'encryption_key' => $this->testKeys['testing_key_256']->id(),
    ]);
    $encryption_profile_2->save();
    $this->encryptionProfiles['encryption_profile_2'] = $encryption_profile_2;
  }

}
