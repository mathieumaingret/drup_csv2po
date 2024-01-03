<?php

namespace Drupal\drup_csv2po\Commands;

use Drupal\drup_csv2po\Controller\DrupCsv2PoConverter;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class DrupCsv2poCommands extends DrushCommands {

  /**
   * Convert existing CSV translation file to PO files
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases,
   *   config, etc.
   *
   * @option extension_type
   *   theme or module
   * @option extension_name
   *   theme or module machine name
   * @option extension_translations_directory
   *   translation directory name
   * @option csv_remote_url
   *   download csv content from remote url before converting
   * @option csv_output_filename
   *   save remove csv content to local file
   * @option translations_replace_all
   *   erase all existant translation
   * @option translations_allow_update
   *   allow to update existing translation if they exist, or force to create
   *   new ones
   * @option plural_value_separator
   *   separator for multiple cell values
   *
   * @usage drup_csv2po-convertCsv2Po csv2po
   *   Usage description
   *
   * @command drup_csv2po:csv2po
   * @aliases csv2po
   */
  public function convertCsv2Po(array $options = [
    'extension_type' => NULL,
    'extension_name' => NULL,
    'extension_translations_directory' => NULL,
    'csv_remote_url' => NULL,
    'csv_output_filename' => NULL,
    'translations_replace_all' => NULL,
    'translations_allow_update' => NULL,
    'plural_value_separator' => NULL,
  ]
  ) {
    /** @var DrupCsv2PoConverter $drupCsv2PoConverter */
    $drupCsv2PoConverter = \Drupal::service('drup_csv2po.converter');
    $drupCsv2PoConverter->setOptions($options)->run();
  }

}
