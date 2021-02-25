<?php

namespace Drupal\Tests\field_encrypt\Functional;

use Drupal\field_encrypt\ProcessEntities;

/**
 * Tests encrypting fields on entities that already exist.
 *
 * @group field_encrypt
 */
class EncryptingExistingDataTest extends FieldEncryptTestBase {

  /**
   * Test nodes.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected $testNodes = [];

  /**
   * Tests that existing entities can be encrypted.
   */
  public function testEncryptingExistingData() {
    $this->config('field_encrypt.settings')
      ->set('batch_size', 40)
      ->save();
    for ($i = 0; $i < 20; $i++) {
      $this->createTestNode();
    }
    $this->setFieldStorageSettings(TRUE);
    $this->clickLink('run this process manually');
    $this->assertSession()->pageTextContains('There are 80 entities queued for updating to use the latest field encryption settings.');
    $this->getSession()->getPage()->pressButton('Process updates');
    $this->checkForMetaRefresh();

    foreach ($this->testNodes as $node) {
      $this->assertConfigurableFieldEncrypted($node->id());
    }

    for ($i = 0; $i < 10; $i++) {
      $this->createTestNode();
    }

    $this->setBaseFieldStorageSettings();
    $this->clickLink('run this process manually');
    $this->assertSession()->pageTextContains('There are 60 entities queued for updating to use the latest field encryption settings.');
    $this->getSession()->getPage()->pressButton('Process updates');
    $this->checkForMetaRefresh();

    foreach ($this->testNodes as $node) {
      $this->assertConfigurableFieldEncrypted($node->id());
      $this->assertBaseFieldEncrypted($node->id());
    }

    foreach ($this->testNodes as $idx => $node) {
      $this->drupalGet($node->toUrl());
      $this->assertSession()->pageTextContains('Test node revision' . ($idx + 1));
      $this->assertSession()->pageTextContains('Lorem ipsum dolor sit amet.');
      $this->assertSession()->pageTextContains('one lorem');
      $this->assertSession()->pageTextContains('two lorem');
      $this->assertSession()->pageTextContains('three lorem');
    }

    // Decrypt one of the configurable fields and the base field using the UI.
    $this->drupalGet('admin/config/system/field-encrypt/field-overview');
    $this->xpath('//main//table/tbody/tr[1]')[0]->clickLink('Decrypt');
    $this->assertSession()->pageTextContains('Are you sure you want to remove encryption for field field_test_multi on node?');
    $this->getSession()->getPage()->pressButton('Remove field encryption');
    $this->xpath('//main//table/tbody/tr[2]')[0]->clickLink('Decrypt');
    $this->assertSession()->pageTextContains('Are you sure you want to remove encryption for field title on node?');
    $this->getSession()->getPage()->pressButton('Remove field encryption');

    // Edit a node before processing the queues. Do this via the UI to avoid
    // issues with static caches in the runner.
    $this->drupalGet($this->testNodes[0]->toUrl('edit-form'));
    $this->assertSession()->fieldExists('title[0][value]')->setValue('Test node revision1 updated');
    $this->assertSession()->buttonExists('Save')->press();
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Test node revision1 updated');
    $this->assertSession()->pageTextContains('Lorem ipsum dolor sit amet.');
    $this->assertSession()->pageTextContains('one lorem');
    $this->assertSession()->pageTextContains('two lorem');
    $this->assertSession()->pageTextContains('three lorem');

    $this->drupalGet('admin/config/system/field-encrypt/process-queues');
    $this->assertSession()->pageTextContains('There are 120 entities queued for updating to use the latest field encryption settings.');
    $this->getSession()->getPage()->pressButton('Process updates');
    $this->checkForMetaRefresh();

    foreach ($this->testNodes as $idx => $node) {
      $this->drupalGet($node->toUrl());
      $this->assertSession()->pageTextContains('Test node revision' . ($idx + 1) . $idx === 0 ? ' updated' : '');
      $this->assertSession()->pageTextContains('Lorem ipsum dolor sit amet.');
      $this->assertSession()->pageTextContains('one lorem');
      $this->assertSession()->pageTextContains('two lorem');
      $this->assertSession()->pageTextContains('three lorem');
    }

  }

  /**
   * Creates a test node.
   */
  protected function createTestNode() {
    $counter = count($this->testNodes) + 1;
    $node = $this->createNode([
      'title' => 'Test node ' . $counter,
      'field_test_single' => [
        [
          'value' => "Lorem ipsum dolor sit amet.",
          'summary' => "Lorem ipsum",
          'format' => filter_default_format(),
        ],
      ],
      'field_test_multi' => [
        ['value' => "one lorem"],
        ['value' => "two lorem"],
        ['value' => "three lorem"],
      ],
    ]);
    $node->setNewRevision(TRUE);
    $node->title = 'Test node revision' . $counter;
    $node->setRevisionLogMessage('Test log message');
    $node->save();
    $this->testNodes[] = $node;
  }

  /**
   * Asserts that configurable fields are encrypted as expected.
   *
   * @param int $node_id
   *   The node ID to check.
   */
  protected function assertConfigurableFieldEncrypted($node_id) {
    $result = \Drupal::database()->query("SELECT field_test_single_value FROM {node__field_test_single} WHERE entity_id = :entity_id", [':entity_id' => $node_id])->fetchField();
    $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $result);

    $result = \Drupal::database()->query("SELECT field_test_multi_value FROM {node__field_test_multi} WHERE entity_id = :entity_id", [':entity_id' => $node_id])->fetchAll();
    foreach ($result as $record) {
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->field_test_multi_value);
    }

    // Revisions.
    $result = \Drupal::database()->query("SELECT field_test_single_value FROM {node_revision__field_test_single} WHERE entity_id = :entity_id", [':entity_id' => $node_id])->fetchAll();
    foreach ($result as $record) {
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->field_test_single_value);
    }
    $result = \Drupal::database()->query("SELECT field_test_multi_value FROM {node_revision__field_test_multi} WHERE entity_id = :entity_id", [':entity_id' => $node_id])->fetchAll();
    foreach ($result as $record) {
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->field_test_multi_value);
    }
  }

  /**
   * Asserts that base fields are encrypted as expected.
   *
   * @param int $node_id
   *   The node ID to check.
   */
  protected function assertBaseFieldEncrypted($node_id) {
    $result = \Drupal::database()->query("SELECT title FROM {node_field_data} WHERE nid = :entity_id", [':entity_id' => $node_id])->fetchField();
    $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $result);

    // Revisions.
    $result = \Drupal::database()->query("SELECT title FROM {node_field_revision} WHERE nid = :entity_id", [':entity_id' => $node_id])->fetchAll();
    foreach ($result as $record) {
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->title);
    }
  }

  /**
   * Set up base fields for test.
   *
   * @param bool $encryption
   *   Whether or not the fields should be encrypted. Defaults to TRUE.
   */
  protected function setBaseFieldStorageSettings($encryption = TRUE) {
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

}
