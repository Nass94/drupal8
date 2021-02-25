<?php

namespace Drupal\Tests\field_encrypt\Functional;

use Drupal\Core\Url;
use Drupal\Tests\RequirementsPageTrait;

/**
 * Tests that updating from older versions of field encrypt is not supported.
 *
 * @group field_encrypt
 * @group legacy
 */
class FieldEncryptUpdatePathTest extends FieldEncryptTestBase {
  use RequirementsPageTrait;

  /**
   * Tests field_encrypt_requirements().
   */
  public function testUpdate() {
    $this->drupalLogin($this->rootUser);
    $update_url = Url::fromRoute('system.db_update');
    $this->drupalGet($update_url);
    $this->updateRequirementsProblem();
    $this->clickLink('Continue');
    $this->assertSession()->pageTextContains('No pending updates.');

    // Simulate having an old version of field_encrypt.
    drupal_set_installed_schema_version('field_encrypt', 8000);
    $this->drupalGet($update_url);
    $this->assertSession()->pageTextContains('Update to field_encrypt version 3 is not supported.');

    $this->drupalGet('admin/reports/status');
    $this->assertSession()->pageTextContains('Update to field_encrypt version 3 is not supported.');
  }

}
