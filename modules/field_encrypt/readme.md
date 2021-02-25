CONTENTS OF THIS FILE
---------------------
* Introduction
* Requirements
* Recommended modules
* Installation
* Configuration
* Maintainers


INTRODUCTION
------------
The Field Encrypt module allows fields to be stored in an encrypted state
rather than in plain text and allows these fields to be decrypted when loaded
for viewing.

* For a full description of the module, visit the project page:
  https://www.drupal.org/project/field_encrypt

* To submit bug reports and feature suggestions, or to track changes:
  https://www.drupal.org/project/issues/field_encrypt


REQUIREMENTS
------------
This module requires the following core and modules:

* Drupal core '^8.7.7 || ^9'
* Key (https://www.drupal.org/project/key)
* Encrypt (https://www.drupal.org/project/encrypt)


RECOMMENDED MODULES
-------------------
In order to encrypt configurable fields via the UI, Drupal core's Field UI
module must enabled.

This module requires at least one module that provides an encryption method. For
example:

* Sodium (https://www.drupal.org/project/sodium)


INSTALLATION
------------
Install as you would normally install a contributed Drupal module. For example:
```shell script
composer require drupal/field_encrypt
```
See: https://www.drupal.org/node/1897420 for further information.


CONFIGURATION
-------------
## Configuring a key and an encryption profile
Make sure you have set up a secure key and encryption profile. See README files
of the Encrypt and Key modules for more information on how to configure them
securely.

## Configuring Field Encrypt
Select an encryption profile to use for field encryption on the "Field Encrypt
settings" page (admin/config/system/field-encrypt).

## Encrypting configurable fields
For each configurable field you wish to encrypt, go to the "Storage settings"
form in the field UI. For example, the node article body field storage form
is accessed via
admin/structure/types/manage/article/fields/node.article.body/storage.

1. Check the "Encrypted" checkbox to have this field automatically encrypted and
   decrypted.
2. Select which field properties to encrypt. Reasonable defaults are provided
   for core field types.
3. Save the field storage settings.

## Encrypting base fields
Base fields have no storage settings provided by the Field UI module. The Field
Encrypt module provides a UI at admin/config/system/field-encrypt/entity-types

1. Select the entity type for which you wish to configure encrypted base fields.
2. Select the base field(s) you wish to encrypt.
3. Select which properties for each field should be encrypted.

## Updating field encryption settings
This module allows you to change the encryption settings of fields, even when
they already contain field data. However, you should use this feature with great
care. It is very important to make sure you have a decent backup strategy in
place and perform the updates in a controlled environment, to avoid data loss or
data corruption.

You can change the field settings from unencrypted to encrypted or change from
encrypted to unencrypted. Make sure you properly tested your encryption methods
before starting a field update!

After changing the settings through the UI or by importing configuration through
CMI, you can check the field update status at
/admin/config/system/field-encrypt/process-queues. When clicking the "Process
updates" button, a batch process will be started to update the existing
entities, to make them compatible with the new setting. It is recommended to run
this process immediately after updating your configuration, to avoid problems
with your data. If you don't perform this action manually, the fields will be
updated automatically via cron.

## Advanced configuration
You can change the default properties that will be selected per field type, on a
per-site basis:

- Go to Administration > Configuration > System > Field Encrypt settings
  (/admin/config/system/field-encrypt).
- Choose which properties should be selected by default for encryption, when
  setting up field encryption for the available field types.

Note: changes to the settings form do not persist to the field config or field
value encryption - these are merely default settings that are set ONLY when
a field is set up for encryption the first time.

## Architecture Documentation

There is an architecture overview available in /documentation. This is available
as an image file as well as a draw.io xml file. This should give a general idea
of how each of the portions of the module function and how the module hooks into
Drupal and it's plugin system.

## ProcessEntities Service

The main function of the ProcessEntities Service is to provide a way
to process entities.

In Drupal 8, it is recommended that all processing on fields be done at the
entity level. As such, the service primarily takes entities as inputs and
processes those fields in order to either decrypt encrypted fields or encrypt
fields before storage.

The `encryptEntity()` and `decryptEntity()` methods perform these actions.

Inside the service, we iterate over each field and then each of the fields
values. For example, the `text_with_summary` field type has a `value` and a
`summary` value. The encryption itself is then handled by the encryption service
of the Encrypt module.

## Encrypt Field Storage Third Party Setting

In Drupal 8, the field storage settings are stored in configuration management
using a Configuration Entity. Extending configuration entities is done by
providing `Third Party Settings` and using the associated methods.

We provide this setting to all fields using the
`config/schema/field_encrypt.schema.yml` file.

## Configuring Fields to use Encryption

While this creates the setting, we need to modify the field storage form so that
we can set / change this value. We hook into the form system with
`hook_form_alter()` in our `.module` file.

## Updating Stored Field Values

The field_encrypt module provides an EventSubscriber that reacts to
configuration changes (\Drupal\field_encrypt\EventSubscriber\ConfigSubscriber).
When the field storage config changes, we check if there was a change in the
field_encrypt settings. This way, when the setting is changed, we can queue
stored values to encrypt / decrypt to match the new setting. We use Drupal 8's
Queue API to queue this process. The actual encryption / decryption is handled
by resaving any entities and relying on the 'field_encrypt.process_entities'
service.

## Dynamic entity hooks

In order to make this module as interoperable as possible with other modules it
defines entity hooks using `eval()`. This can be disabled by defining the
setting `field_encrypt.use_eval_for_entity_hooks` in settings.php. For example:
```php
$settings['field_encrypt.use_eval_for_entity_hooks'] = FALSE;
```
If you do this you can visit admin/reports/status where the code to add to a
custom module will be detailed. See `field_encrypt_module_implements_alter()`
for more information about Field Encrypt and entity hooks.


MAINTAINERS
-----------

Current Maintainers:
* Adam Bergstein (nerdstein) (https://www.drupal.org/u/nerdstein)
* Alex Pott (alexpott) (https://www.drupal.org/u/alexpott)
