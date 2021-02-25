<?php

namespace Drupal\Tests\field_encrypt\Functional;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\field_encrypt\ProcessEntities;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\encrypt\Functional\EncryptTestBase;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\system\Functional\Entity\Traits\EntityDefinitionTestTrait;
use Drupal\Tests\Traits\Core\CronRunTrait;

/**
 * Tests base field encryption.
 *
 * @group field_encrypt
 */
class BaseFieldTest extends EncryptTestBase {
  use EntityDefinitionTestTrait;
  use CronRunTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
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
    'field_encrypt_test',
  ];

  /**
   * {@inheritdoc}
   *
   * @TODO: Simplify setUp() by extending EncryptTestBase when https://www.drupal.org/node/2692387 lands.
   */
  protected function setUp() {
    parent::setUp();

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
   * Test encrypting base fields.
   *
   * This test also covers changing field encryption settings when existing
   * data already exists, as well as making fields unencrypted again with
   * data decryption support.
   */
  public function testEncryptFieldNormal() {
    $this->setFieldStorageSettings(TRUE);

    // Save test entity.
    $test_node = $this->createNode([
      'title' => 'Test title',
    ]);

    $fields = $test_node->getFields();
    // Check title base field settings.
    $definition = $fields['title']->getFieldDefinition();
    $this->assertTrue($definition instanceof FieldDefinitionInterface);
    /** @var \Drupal\Core\Field\FieldConfigInterface $storage */
    $storage = $definition->getFieldStorageDefinition();
    $this->assertEquals(TRUE, $storage->getSetting('field_encrypt.encrypt'));
    $this->assertEquals(['value'], array_filter($storage->getSetting('field_encrypt.properties')));

    // Check if text is displayed unencrypted.
    $this->drupalGet('node/' . $test_node->id());
    $this->assertSession()->pageTextContains("Test title");

    $result = \Drupal::database()->query("SELECT title FROM {node_field_data} WHERE nid = :entity_id", [':entity_id' => $test_node->id()])->fetchField();
    $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $result);

    // Change default encryption profile and ensure the entity can still be
    // decrypted.
    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'encryption_profile_2')
      ->save();
    $this->resetAll();
    // Check if text is displayed unencrypted.
    $this->drupalGet('node/' . $test_node->id());
    $this->assertSession()->pageTextContains("Test title");

    // Ensure that base fields used in post save messages are decrypted on
    // insert, update and delete.
    $this->drupalGet('node/add/page');
    $this->assertSession()->fieldExists('title[0][value]')->setValue('Test title encrypted');
    $this->assertSession()->buttonExists('Save')->press();
    $this->assertSession()->pageTextContains('Test title encrypted has been created.');
    $this->assertSession()->pageTextContains('Field encrypt test hook_ENTITY_TYPE_insert: Test title encrypted');
    $this->assertSession()->pageTextContains('Field encrypt test hook_entity_insert: Test title encrypted');
    $this->drupalGet('node/2/edit');
    $this->assertSession()->fieldExists('title[0][value]')->setValue('Test new title encrypted');
    $this->assertSession()->buttonExists('Save')->press();
    $this->assertSession()->pageTextContains('Test new title encrypted has been updated.');
    $this->assertSession()->pageTextContains('Field encrypt test hook_ENTITY_TYPE_update: Test new title encrypted');
    $this->assertSession()->pageTextContains('Field encrypt test hook_entity_update: Test new title encrypted');
    $this->drupalGet('node/2/delete');
    $this->assertSession()->buttonExists('Delete')->press();
    $this->assertSession()->pageTextContains('Test new title encrypted has been deleted.');
    $this->assertSession()->pageTextContains('Field encrypt test hook_ENTITY_TYPE_delete: Test new title encrypted');
    $this->assertSession()->pageTextContains('Field encrypt test hook_entity_delete: Test new title encrypted');

    // Test updating entities to remove field encryption.
    $this->setFieldStorageSettings(FALSE);

    // Update existing data with new field encryption settings.
    $this->assertSession()->linkByHrefExists('admin/config/system/field-encrypt/process-queues');
    $this->drupalGet('admin/config/system/field-encrypt/process-queues');
    $this->assertSession()->pageTextContains('There is one entity queued for updating to use the latest field encryption settings.');
    $this->assertTrue(\Drupal::database()->schema()->fieldExists('node_field_data', ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME . '__value'));
    $this->cronRun();
    $this->drupalGet('admin/config/system/field-encrypt/process-queues');
    $this->assertSession()->pageTextContains('There are 0 entities queued for updating to use the latest field encryption settings.');

    // Check if text is displayed unencrypted.
    $this->drupalGet('node/' . $test_node->id());
    $this->assertSession()->pageTextContains("Test title");

    $result = \Drupal::database()->query("SELECT title FROM {node_field_data} WHERE nid = :entity_id", [':entity_id' => $test_node->id()])->fetchField();
    $this->assertEquals('Test title', $result);

    $this->assertTrue(\Drupal::database()->schema()->fieldExists('node_field_data', ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME . '__value'));
    // This will call field_encrypt_cron() which will now remove the storage
    // field. Cron hooks are always processed before queue workers so this takes
    // an additional call.
    $this->cronRun();
    $this->assertFalse(\Drupal::database()->schema()->fieldExists('node_field_data', ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME . '__value'));

    // Create a node once the encryption has been remvoed.
    $this->drupalGet('node/add/page');
    $this->assertSession()->fieldExists('title[0][value]')->setValue('Test title no encryption');
    $this->assertSession()->buttonExists('Save')->press();
    $this->assertSession()->pageTextContains('Test title no encryption has been created.');
  }

  /**
   * Test encrypting fields with revisions.
   *
   * This test also covers deletion of an encrypted field with existing data.
   */
  public function testEncryptFieldRevision() {
    $this->setFieldStorageSettings(TRUE);

    // Save test entity.
    $test_node = $this->createNode([
      'title' => 'Test title rev 1',
    ]);

    // Create a new revision for the entity.
    $old_revision_id = $test_node->getRevisionId();
    $test_node->setNewRevision(TRUE);
    $test_node->title->value = 'Test title rev 2';
    $test_node->save();

    // Ensure that the node revision has been created.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$test_node->id()]);
    $this->assertNotSame($test_node->getRevisionId(), $old_revision_id, 'A new revision has been created.');

    // Check if revision text is displayed unencrypted.
    $this->drupalGet('node/' . $test_node->id());
    $this->assertSession()->pageTextContains('Test title rev 2');

    // Check if original text is displayed unencrypted.
    $this->drupalGet('node/' . $test_node->id() . '/revisions/' . $old_revision_id . '/view');
    $this->assertSession()->pageTextContains("Test title rev 1");

    // Check value saved in the database.
    $result = \Drupal::database()->query("SELECT title FROM {node_field_revision} WHERE nid = :entity_id", [':entity_id' => $test_node->id()])->fetchField();
    $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $result);
  }

  /**
   * Test encrypting fields with translations.
   */
  public function testEncryptFieldTranslation() {
    // Set up extra language.
    ConfigurableLanguage::createFromLangcode('fr')->save();
    // Enable translation for the current entity type and ensure the change is
    // picked up.
    \Drupal::service('content_translation.manager')->setEnabled('node', 'page', TRUE);
    drupal_static_reset();
    \Drupal::entityTypeManager()->clearCachedDefinitions();
    \Drupal::service('router.builder')->rebuild();
    $this->rebuildContainer();
    $this->setFieldStorageSettings(TRUE);

    // Save test entity.
    $test_node = $this->createNode([
      'title' => 'Test title en',
    ]);

    // Reload node after saving.
    $controller = \Drupal::entityTypeManager()->getStorage($test_node->getEntityTypeId());
    $controller->resetCache([$test_node->id()]);
    $test_node = $controller->load($test_node->id());

    // Add translated values.
    $test_node->addTranslation('fr', [
      'title' => "test title fr",
    ]);
    $test_node->save();

    // Check if English text is displayed unencrypted.
    $this->drupalGet('node/' . $test_node->id());
    $this->assertSession()->pageTextContains("Test title en");

    // Check if French text is displayed unencrypted.
    $this->drupalGet('fr/node/' . $test_node->id());
    $this->assertSession()->pageTextContains("Test title fr");

    // Check values saved in the database.
    $result = \Drupal::database()->query("SELECT title FROM {node_field_data} WHERE nid = :entity_id", [':entity_id' => $test_node->id()])->fetchAll();
    $this->assertCount(2, $result);
    foreach ($result as $record) {
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->title);
    }
  }

  /**
   * Tests that uninstalling a module providing an entity type works.
   */
  public function testEntityTypeDependencies() {
    $this->setFieldStorageSettings(TRUE);
    /** @var \Drupal\field_encrypt\Entity\FieldEncryptEntityType $config_entity */
    $config_entity = \Drupal::entityTypeManager()->getStorage('field_encrypt_entity_type')->load('node');
    $this->assertNotNull($config_entity);
    $old_config = $config_entity->getBaseFields();
    // Simulate config data to import.
    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($active, $sync);
    \Drupal::service('module_installer')->uninstall(['node']);
    $config_entity = \Drupal::entityTypeManager()->getStorage('field_encrypt_entity_type')->load('node');
    $this->assertNull($config_entity);

    \Drupal::service('module_installer')->uninstall([
      'field_encrypt',
      'field_encrypt_test',
    ]);
    $this->configImporter()->import();
    $config_entity = \Drupal::entityTypeManager()->getStorage('field_encrypt_entity_type')->load('node');
    $this->assertSame($old_config, $config_entity->getBaseFields());
    $this->assertFalse($this->configImporter()->reset()->hasUnprocessedConfigurationChanges());

    // Save test entity.
    $this->createNode(['title' => 'Test title en']);

    // Add a base field to the node entity.
    // @see field_encrypt_test_entity_base_field_info()
    $this->assertSame([], \Drupal::entityDefinitionUpdateManager()->getChangeList());
    $this->assertFalse(\Drupal::entityDefinitionUpdateManager()->needsUpdates());
    \Drupal::state()->set('field_encrypt.create_base_field', TRUE);
    $this->assertTrue(\Drupal::entityDefinitionUpdateManager()->needsUpdates());
    $this->applyEntityUpdates();
    // Remove title encryption and set up encryption on the test base field.
    $config_entity = \Drupal::entityTypeManager()->getStorage('field_encrypt_entity_type')->load('node');
    $config_entity->setBaseFields(['field_encrypt_test_base_field' => ['value']])->save();
    /** @var \Drupal\Core\Queue\QueueInterface $queue */
    $queue = \Drupal::service('queue')->get('field_encrypt_update_entity_encryption');
    $this->assertSame(1, $queue->numberOfItems());
    $this->cronRun();

    // Cause the base field to be removed.
    \Drupal::state()->set('field_encrypt.create_base_field', FALSE);
    $this->applyEntityUpdates();
    $config_entity = \Drupal::entityTypeManager()->getStorage('field_encrypt_entity_type')->load('node');
    $this->assertNull($config_entity);
    $queue = \Drupal::service('queue')->get('field_encrypt_update_entity_encryption');
    $this->assertSame(1, $queue->numberOfItems());
  }

}
