<?php

namespace Drupal\Tests\field_encrypt\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests dynamic entity hook creation.
 *
 * @group field_encrypt
 */
class DynamicEntityHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field_encrypt'];

  /**
   * Tests _field_encrypt_entity_hooks().
   */
  public function testUnexpectedEntityTypeId() {
    $this->container->get('state')->set('field_encrypt.entity_types', ['; do_something_bad();']);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('"; do_something_bad();" entity type contains unexpected characters');
    _field_encrypt_entity_hooks();
  }

  /**
   * Tests _field_encrypt_define_entity_hooks().
   */
  public function testDynamicFunctionRegistration() {
    if (!_field_encrypt_can_eval()) {
      $this->markTestSkipped('eval() not available');
    }

    $this->assertFalse(function_exists('field_encrypt_test1_insert'));
    $this->assertFalse(function_exists('field_encrypt_test1_update'));
    $this->assertFalse(function_exists('field_encrypt_test2_insert'));
    $this->assertFalse(function_exists('field_encrypt_test2_update'));
    $this->container
      ->get('state')
      ->set('field_encrypt.entity_types', ['test1', 'test2']);

    _field_encrypt_define_entity_hooks();

    $this->assertTrue(function_exists('field_encrypt_test1_insert'));
    $this->assertTrue(function_exists('field_encrypt_test1_update'));
    $this->assertTrue(function_exists('field_encrypt_test2_insert'));
    $this->assertTrue(function_exists('field_encrypt_test2_update'));
  }

}
