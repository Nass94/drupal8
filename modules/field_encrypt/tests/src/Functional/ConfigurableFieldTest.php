<?php

namespace Drupal\Tests\field_encrypt\Functional;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\field_encrypt\ProcessEntities;
use Drupal\Tests\Traits\Core\CronRunTrait;

/**
 * Tests field encryption.
 *
 * @group field_encrypt
 */
class ConfigurableFieldTest extends FieldEncryptTestBase {

  use CronRunTrait;

  /**
   * Test encrypting fields.
   *
   * This test also covers changing field encryption settings when existing
   * data already exists, as well as making fields unencrypted again with
   * data decryption support.
   */
  public function testEncryptFieldNormal() {
    $this->setFieldStorageSettings(TRUE);

    // Save test entity.
    $this->createTestNode();

    $fields = $this->testNode->getFields();
    // Check field_test_single settings.
    $single_field = $fields['field_test_single'];
    $definition = $single_field->getFieldDefinition();
    $this->assertTrue($definition instanceof FieldDefinitionInterface);
    /** @var \Drupal\Core\Field\FieldConfigInterface $storage */
    $storage = $definition->getFieldStorageDefinition();
    $this->assertEquals(TRUE, $storage->getThirdPartySetting('field_encrypt', 'encrypt', FALSE));
    $this->assertEquals(
      ['value' => 'value', 'summary' => 'summary'],
      array_filter($storage->getThirdPartySetting('field_encrypt', 'properties', []))
    );

    // Check field_test_multi settings.
    $single_field = $fields['field_test_multi'];
    $definition = $single_field->getFieldDefinition();
    $this->assertTrue($definition instanceof FieldDefinitionInterface);
    /** @var \Drupal\Core\Field\FieldConfigInterface $storage */
    $storage = $definition->getFieldStorageDefinition();
    $this->assertEquals(TRUE, $storage->getThirdPartySetting('field_encrypt', 'encrypt', FALSE));
    $this->assertEquals(['value' => 'value'], array_filter($storage->getThirdPartySetting('field_encrypt', 'properties', [])));

    // Check if text is displayed unencrypted.
    $this->drupalGet('node/' . $this->testNode->id());
    $this->assertSession()->pageTextContains("Lorem ipsum dolor sit amet.");
    $this->assertSession()->pageTextContains("one");
    $this->assertSession()->pageTextContains("two");
    $this->assertSession()->pageTextContains("three");

    $result = \Drupal::database()->query("SELECT field_test_single_value FROM {node__field_test_single} WHERE entity_id = :entity_id", [':entity_id' => $this->testNode->id()])->fetchField();
    $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $result);

    $result = \Drupal::database()->query("SELECT field_test_multi_value FROM {node__field_test_multi} WHERE entity_id = :entity_id", [':entity_id' => $this->testNode->id()])->fetchAll();
    foreach ($result as $record) {
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->field_test_multi_value);
    }

    // Change default encryption profile and ensure the entity can still be
    // decrypted.
    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'encryption_profile_2')
      ->save();
    $this->resetAll();
    // Check if text is displayed unencrypted.
    $this->drupalGet('node/' . $this->testNode->id());
    $this->assertSession()->pageTextContains("Lorem ipsum dolor sit amet.");
    $this->assertSession()->pageTextContains("one");
    $this->assertSession()->pageTextContains("two");
    $this->assertSession()->pageTextContains("three");

    // Test updating entities to remove field encryption.
    $this->setFieldStorageSettings(FALSE);
    // Update existing data with new field encryption settings.
    $this->assertSession()->linkByHrefExists('admin/config/system/field-encrypt/process-queues');
    $this->drupalGet('admin/config/system/field-encrypt/process-queues');
    $this->assertSession()->pageTextContains('There are 2 entities queued for updating to use the latest field encryption settings.');
    $this->assertTrue(\Drupal::database()->schema()->fieldExists('node_field_data', ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME . '__value'));
    $this->getSession()->getPage()->pressButton('Process updates');
    $this->checkForMetaRefresh();
    $this->assertSession()->pageTextContains('There are 0 entities queued for updating to use the latest field encryption settings.');

    // Check if text is displayed unencrypted.
    $this->drupalGet('node/' . $this->testNode->id());
    $this->assertSession()->pageTextContains("Lorem ipsum dolor sit amet.");
    $this->assertSession()->pageTextContains("one");
    $this->assertSession()->pageTextContains("two");
    $this->assertSession()->pageTextContains("three");

    $result = \Drupal::database()->query("SELECT field_test_single_value FROM {node__field_test_single} WHERE entity_id = :entity_id", [':entity_id' => $this->testNode->id()])->fetchField();
    $this->assertEquals("Lorem ipsum dolor sit amet.", $result);

    $result = \Drupal::database()->query("SELECT field_test_multi_value FROM {node__field_test_multi} WHERE entity_id = :entity_id", [':entity_id' => $this->testNode->id()])->fetchAll();
    $valid_values = ["one", "two", "three"];
    foreach ($result as $record) {
      $this->assertTrue(in_array($record->field_test_multi_value, $valid_values));
    }
    $this->assertFalse(\Drupal::database()->schema()->fieldExists('node_field_data', ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME . '__value'));

    // Ensure state is cleaned up on uninstall.
    $this->assertSame([], \Drupal::state()->get('field_encrypt.entity_types'));
    \Drupal::service('module_installer')->uninstall(['field_encrypt']);
    $this->assertSame(NULL, \Drupal::state()->get('field_encrypt.entity_types'));
  }

  /**
   * Test encrypting fields with revisions.
   *
   * This test also covers deletion of an encrypted field with existing data.
   */
  public function testEncryptFieldRevision() {
    $this->setFieldStorageSettings(TRUE);

    // Save test entity.
    $this->createTestNode();

    // Create a new revision for the entity.
    $old_revision_id = $this->testNode->getRevisionId();
    $this->testNode->setNewRevision(TRUE);
    $this->testNode->field_test_single->value = "Lorem ipsum dolor sit amet revision.";
    $this->testNode->field_test_single->summary = "Lorem ipsum revision.";
    $multi_field = $this->testNode->get('field_test_multi');
    $multi_field_value = $multi_field->getValue();
    $multi_field_value[0]['value'] = "four";
    $multi_field_value[1]['value'] = "five";
    $multi_field_value[2]['value'] = "six";
    $multi_field->setValue($multi_field_value);
    $this->testNode->save();

    // Ensure that the node revision has been created.
    $this->entityTypeManager->getStorage('node')->resetCache([$this->testNode->id()]);
    $this->assertNotSame($this->testNode->getRevisionId(), $old_revision_id, 'A new revision has been created.');

    // Check if revision text is displayed unencrypted.
    $this->drupalGet('node/' . $this->testNode->id());
    $this->assertSession()->pageTextContains("Lorem ipsum dolor sit amet revision.");
    $this->assertSession()->pageTextContains("four");
    $this->assertSession()->pageTextContains("five");
    $this->assertSession()->pageTextContains("six");

    // Check if original text is displayed unencrypted.
    $this->drupalGet('node/' . $this->testNode->id() . '/revisions/' . $old_revision_id . '/view');
    $this->assertSession()->pageTextContains("Lorem ipsum dolor sit amet.");
    $this->assertSession()->pageTextContains("one");
    $this->assertSession()->pageTextContains("two");
    $this->assertSession()->pageTextContains("three");

    // Check values saved in the database.
    $result = \Drupal::database()->query("SELECT field_test_single_value FROM {node_revision__field_test_single} WHERE entity_id = :entity_id", [':entity_id' => $this->testNode->id()])->fetchField();
    $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $result);

    $result = \Drupal::database()->query("SELECT field_test_multi_value FROM {node_revision__field_test_multi} WHERE entity_id = :entity_id", [':entity_id' => $this->testNode->id()])->fetchAll();
    foreach ($result as $record) {
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->field_test_multi_value);
    }

    $this->drupalGet('admin/structure/types/manage/page/fields/node.page.field_test_multi/delete');
    $this->submitForm([], 'Delete');
  }

  /**
   * Test encrypting fields with translations.
   */
  public function testEncryptFieldTranslation() {
    $this->setTranslationSettings();
    $this->setFieldStorageSettings(TRUE);

    // Save test entity.
    $this->createTestNode();

    // Reload node after saving.
    $controller = $this->entityTypeManager->getStorage($this->testNode->getEntityTypeId());
    $controller->resetCache([$this->testNode->id()]);
    $this->testNode = $controller->load($this->testNode->id());

    // Add translated values.
    $translated_values = [
      'title' => $this->randomMachineName(8),
      'field_test_single' => [
        [
          'value' => "Ceci est un text francais.",
          'summary' => "Text francais",
          'format' => filter_default_format(),
        ],
      ],
      'field_test_multi' => [
        ['value' => "un"],
        ['value' => "deux"],
        ['value' => "trois"],
      ],
    ];
    $this->testNode->addTranslation('fr', $translated_values);
    $this->testNode->save();

    // Check if English text is displayed unencrypted.
    $this->drupalGet('node/' . $this->testNode->id());
    $this->assertSession()->pageTextContains("Lorem ipsum dolor sit amet.");
    $this->assertSession()->pageTextContains("one");
    $this->assertSession()->pageTextContains("two");
    $this->assertSession()->pageTextContains("three");

    // Check if French text is displayed unencrypted.
    $this->drupalGet('fr/node/' . $this->testNode->id());
    $this->assertSession()->pageTextContains("Ceci est un text francais.");
    $this->assertSession()->pageTextContains("un");
    $this->assertSession()->pageTextContains("deux");
    $this->assertSession()->pageTextContains("trois");

    // Check values saved in the database.
    $result = \Drupal::database()->query("SELECT field_test_single_value FROM {node__field_test_single} WHERE entity_id = :entity_id", [':entity_id' => $this->testNode->id()])->fetchAll();
    $this->assertCount(2, $result);
    foreach ($result as $record) {
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->field_test_single_value);
    }

    $result = \Drupal::database()->query("SELECT field_test_multi_value FROM {node__field_test_multi} WHERE entity_id = :entity_id", [':entity_id' => $this->testNode->id()])->fetchAll();
    $this->assertCount(6, $result);
    foreach ($result as $record) {
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->field_test_multi_value);
    }
  }

}
