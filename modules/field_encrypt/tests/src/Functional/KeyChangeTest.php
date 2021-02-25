<?php

namespace Drupal\Tests\field_encrypt\Functional;

use Drupal\field_encrypt\ProcessEntities;
use Drupal\Tests\encrypt\Functional\EncryptTestBase;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\Traits\Core\CronRunTrait;

/**
 * Tests changing encryption profiles.
 *
 * @group field_encrypt
 */
class KeyChangeTest extends EncryptTestBase {

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
   * Tests...
   */
  public function testEncryptionProfileChange() {
    $this->setFieldStorageSettings(TRUE);

    // Save test entity.
    $test_node1 = $this->createNode([
      'title' => 'Test title 1',
    ]);

    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'encryption_profile_2')
      ->save();

    // Save test entity.
    $test_node2 = $this->createNode([
      'title' => 'Test title 2',
    ]);

    // Save test entity.
    $test_node3 = $this->createNode([
      'title' => 'Test title 3',
    ]);
    // Create a revision.
    $test_node3_vid = $test_node3->getRevisionId();
    $test_node3->setNewRevision(TRUE);
    $test_node3->title = 'Test title 3 revisioned';
    $test_node3->save();

    // Check if text is displayed unencrypted.
    $this->drupalGet('node/' . $test_node1->id());
    $this->assertSession()->pageTextContains("Test title 1");
    $this->drupalGet('node/' . $test_node2->id());
    $this->assertSession()->pageTextContains("Test title 2");

    $this->assertSame('encryption_profile_1', $test_node1->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $this->assertSame('encryption_profile_2', $test_node2->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);

    $this->drupalGet('admin/config/system/field-encrypt/entity-types/node');
    $this->assertCount(2, $this->xpath('//table[contains(@class, "encryption-profiles")]/tbody/tr'));
    $this->assertSession()->elementTextContains('xpath', '//table[contains(@class, "encryption-profiles")]/tbody/tr[1]/td[1]', 'Encryption profile 1');
    $this->assertSession()->elementTextContains('xpath', '//table[contains(@class, "encryption-profiles")]/tbody/tr[2]/td[1]', 'Encryption profile 2');
    $this->assertSession()->elementTextContains('xpath', '//table[contains(@class, "encryption-profiles")]/tbody/tr[1]/td[2]', '1');
    $this->assertSession()->elementTextContains('xpath', '//table[contains(@class, "encryption-profiles")]/tbody/tr[2]/td[2]', '3');
    $this->assertSession()->elementTextContains('xpath', '//table[contains(@class, "encryption-profiles")]/tbody/tr[1]/td[3]', 'Update encryption profile');
    $this->assertSession()->elementTextNotContains('xpath', '//table[contains(@class, "encryption-profiles")]/tbody/tr[2]/td[3]', 'Update encryption profile');

    // Saving the first node will change it to use the new encryption profile.
    $test_node1->save();
    $this->drupalGet('node/' . $test_node1->id());
    $this->assertSession()->pageTextContains("Test title 1");
    $this->assertSame('encryption_profile_2', $test_node1->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);

    // Change the default encryption profile so we can test bulk updates.
    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'encryption_profile_1')
      ->save();

    $this->drupalGet('admin/config/system/field-encrypt/entity-types/node');
    $this->assertCount(1, $this->xpath('//table[contains(@class, "encryption-profiles")]/tbody/tr'));
    $this->clickLink('Update encryption profile');
    $this->assertSession()->pageTextContains('Are you sure you want to update the encryption profile from Encryption profile 2 to Encryption profile 1 for content items?');
    $this->getSession()->getPage()->pressButton('Update encryption profile');
    $this->assertSession()->pageTextContains('Queued 4 content item updates. You should immediately run this process manually. Alternatively, the updates will be performed automatically by cron.');
    // Test the nodes have the old encryption profile.
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $test_node1 = $node_storage->loadUnchanged($test_node1->id());
    $test_node2 = $node_storage->loadUnchanged($test_node2->id());
    $test_node3 = $node_storage->loadUnchanged($test_node3->id());
    $this->assertSame('encryption_profile_2', $test_node1->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $this->assertSame('encryption_profile_2', $test_node2->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $this->assertSame('encryption_profile_2', $test_node3->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $test_node3_revision = $node_storage->loadRevision($test_node3_vid);
    $this->assertSame('encryption_profile_2', $test_node3_revision->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);

    // Try deleting the encryption profile that will no longer be in use once
    // cron has run.
    $profile_storage = \Drupal::entityTypeManager()->getStorage('encryption_profile');
    try {
      $profile_storage->load('encryption_profile_2')->delete();
      $this->fail('Deleting encryption_profile_2 should fail');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Cannot delete encryption_profile_2 encryption profile because it is in-use by node entities', $e->getMessage());
    }

    // Run cron and the encryption profiles should be updated.
    $this->cronRun();
    $test_node1 = $node_storage->loadUnchanged($test_node1->id());
    $test_node2 = $node_storage->loadUnchanged($test_node2->id());
    $test_node3 = $node_storage->loadUnchanged($test_node3->id());
    $this->assertSame('encryption_profile_1', $test_node1->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $this->assertSame('encryption_profile_1', $test_node2->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $this->assertSame('encryption_profile_1', $test_node3->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $test_node3_revision = $node_storage->loadRevision($test_node3_vid);
    $this->assertSame('encryption_profile_1', $test_node3_revision->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);

    // Try deleting the default field encryption profile.
    try {
      $profile_storage->load('encryption_profile_1')->delete();
      $this->fail('Deleting encryption_profile_1 should fail');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Cannot delete encryption_profile_1 encryption profile because it is the default for the field_encrypt module', $e->getMessage());
    }

    // Change the default encryption profile so we can test bulk updates via the
    // user interface.
    $this->config('field_encrypt.settings')
      ->set('encryption_profile', 'encryption_profile_2')
      ->save();
    $this->drupalGet('admin/config/system/field-encrypt/entity-types/node');
    $this->assertCount(1, $this->xpath('//table[contains(@class, "encryption-profiles")]/tbody/tr'));
    $this->clickLink('Update encryption profile');
    $this->assertSession()->pageTextContains('Are you sure you want to update the encryption profile from Encryption profile 1 to Encryption profile 2 for content items?');
    $this->getSession()->getPage()->pressButton('Update encryption profile');
    $this->assertSession()->pageTextContains('Queued 4 content item updates. You should immediately run this process manually. Alternatively, the updates will be performed automatically by cron.');
    $this->clickLink('run this process manually');
    $this->assertSession()->pageTextContains('There are 4 entities queued for updating to use the latest field encryption settings.');
    $this->getSession()->getPage()->pressButton('Process updates');
    $this->checkForMetaRefresh();
    $this->assertSession()->pageTextContains('There are 0 entities queued for updating to use the latest field encryption settings.');
    $this->assertSession()->pageTextContains('4 entities updated.');
    $test_node1 = $node_storage->loadUnchanged($test_node1->id());
    $test_node2 = $node_storage->loadUnchanged($test_node2->id());
    $test_node3 = $node_storage->loadUnchanged($test_node3->id());
    $this->assertSame('encryption_profile_2', $test_node1->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $this->assertSame('encryption_profile_2', $test_node2->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $this->assertSame('encryption_profile_2', $test_node3->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);
    $test_node3_revision = $node_storage->loadRevision($test_node3_vid);
    $this->assertSame('encryption_profile_2', $test_node3_revision->{ProcessEntities::ENCRYPTED_FIELD_STORAGE_NAME}->encryption_profile);

    $this->assertNull($profile_storage->load('encryption_profile_1')->delete(), 'encryption_profile_1 successfully deleted');
  }

}
