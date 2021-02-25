<?php

namespace Drupal\Tests\field_encrypt\Functional;

// phpcs:disable DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
use Drupal\field_encrypt\ProcessEntities;
use Drupal\Tests\encrypt\Functional\EncryptTestBase;

/**
 * Tests the performance script provided by the module.
 *
 * @group field_encrypt
 */
class PerformanceScriptTest extends EncryptTestBase {

  /**
   * The test script configures the fields for both 8.x-2.x and 3.0.x.
   *
   * There is plenty of tet coverage of the schema elsewhere.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'field',
    'field_ui',
    'text',
    'key',
    'encrypt',
    'encrypt_test',
    'field_encrypt',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    // This test relies on
    // \Drupal\Tests\encrypt\Functional\EncryptTestBase::setUp()
    // creating an encryption profile called 'encryption_profile_1' to avoid
    // requiring the sodium module.
    parent::setUp();
    // Create content type to test.
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
  }

  /**
   * Runs the performance test script to check it works.
   */
  public function testScript() {
    $this->assertCount(0, \Drupal::entityTypeManager()->getStorage('node')->loadMultiple());
    // Create 15 nodes in the script.
    putenv('FIELD_ENCRYPT_QUANTITY=15');
    ob_start();
    include __DIR__ . '/../../scripts/performance_test.php';
    $output = ob_get_clean();
    $this->assertStringContainsString('Created 15 encrypted nodes in', $output);
    $this->assertStringContainsString('Decrypted 15 encrypted nodes in', $output);
    $this->assertCount(15, \Drupal::entityTypeManager()->getStorage('node')->loadMultiple());
    // Ensure the fields are actually encrypted.
    for ($nid = 1; $nid < 16; $nid++) {
      $result = \Drupal::database()
        ->query("SELECT field_test_single_value FROM {node__field_test_single} WHERE entity_id = :entity_id", [':entity_id' => $nid])
        ->fetchField();
      $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $result);

      $result = \Drupal::database()
        ->query("SELECT field_test_multi_value FROM {node__field_test_multi} WHERE entity_id = :entity_id", [':entity_id' => $nid])
        ->fetchAll();
      foreach ($result as $record) {
        $this->assertEquals(ProcessEntities::ENCRYPTED_VALUE, $record->field_test_multi_value);
      }
    }
  }

}
