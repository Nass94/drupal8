<?php

namespace Drupal\Tests\field_encrypt\Functional;

/**
 * Tests field encryption settings with the Commerce module.
 *
 * @group field_encrypt
 */
class CommerceTest extends FieldEncryptTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce',
  ];

  /**
   * Tests that the admin forms work when commerce is installed.
   */
  public function testSettingsPage() {
    $this->drupalLogin($this->createUser(['administer field encryption']));
    $this->drupalGet('admin/config/system/field-encrypt');
    $this->assertSession()->statusCodeEquals(200);
    // Ensure that special handling for preconfigured definitions works.
    $this->assertSession()->elementTextContains('css', '#edit-default-properties-reference-field-uientity-referencenode--wrapper', 'Content properties');
    $this->drupalGet('admin/config/system/field-encrypt/field-overview');
    $this->assertSession()->statusCodeEquals(200);
  }

}
