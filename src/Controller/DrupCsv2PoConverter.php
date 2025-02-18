<?php

namespace Drupal\drup_csv2po\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use League\Csv\Reader;

/**
 * Returns responses for Drup Csv2Po routes.
 */
class DrupCsv2PoConverter extends ControllerBase {

  /**
   * @var array
   */
  public static array $defaultOptions = [
    // theme or module
    'extension_type' => 'theme',
    // theme or module machine name
    'extension_name' => NULL,
    // translation directory name
    'extension_translations_directory' => 'translations',
    // download csv content from remote url before converting
    'csv_remote_url' => NULL,
    // save remove csv content to local file
    'csv_output_filename' => 'translations.csv',
    // erase all existant translation
    'translations_replace_all' => TRUE,
    // allow to update existing translation if they exist, or force to create new ones
    'translations_allow_update' => FALSE,
    // separator for multiple cell values
    'plural_value_separator' => PHP_EOL,
    // if true, import only enabled languages
    'check_enabled_languages' => TRUE,
  ];

  /**
   * @var string
   */
  protected string $translationDirectoryPath;

  /**
   * @var array
   */
  protected array $csvLangcodes = [];

  /**
   * @var \League\Csv\Reader
   */
  protected \League\Csv\Reader $csvReader;

  /**
   * @var array
   */
  private array $options = [];

  /**
   * @var \Drupal\Core\Extension\ExtensionList
   */
  private ExtensionList $extension_list;

  public function __construct(
    private readonly FileSystemInterface $file_system,
    private readonly ModuleExtensionList $module_extension_list,
    private readonly ThemeExtensionList $theme_extension_list,
    private readonly ThemeManagerInterface $theme_manager
  ) {
    $this->setDefaultOptions();
  }

  /**
   * @return array
   */
  public function getOptions(): array {
    return $this->options;
  }

  /**
   * @param $options
   *
   * @return $this
   */
  public function setOptions($options): static {
    $this->options = array_merge($this->options, array_filter($options));
    return $this;
  }

  /**
   * @return $this
   */
  public function run(): static {
    // Options management
    if ($this->prepareOptions()) {
      // Download remote CSV file + Read file for parsing
      if ($this->downloadCsv() && $this->readCsv()) {
        // Treat each language po file
        if (!empty($this->csvLangcodes)) {
          foreach ($this->csvLangcodes as $csvLangcode) {
            $this->updatePoFile($csvLangcode);
          }
        }
        $this->cleanup();
      }
    }

    return $this;
  }

  /**
   * @return bool
   */
  protected function prepareOptions(): bool {
    $this->extension_list = $this->getOption('extension_type') === 'theme' ? $this->theme_extension_list : $this->module_extension_list;

    // Error is extension is module but no module name provided
    if ($this->getOption('extension_type') === 'module' && empty($this->getOption('extension_name'))) {
      $this->messenger()
        ->addError($this->t('Option <em>@option</em> is missing or misspelled', ['@option' => 'extension_name']));
      return FALSE;
    }
    // Set default theme if not defined
    if ($this->getOption('extension_type') === 'theme' && empty($this->getOption('extension_name'))) {
      $this->setOption('extension_name', $this->theme_manager->getActiveTheme()
        ->getName());
    }

    // Directories
    $this->translationDirectoryPath = $this->extension_list->getPath($this->getOption('extension_name'));
    $this->translationDirectoryPath = './' . rtrim($this->translationDirectoryPath, '/') . '/' . $this->getOption('extension_translations_directory') . '/';

    // Remote url is mandatory but empty
    if (empty($this->getOption('csv_remote_url'))) {
      $this->messenger()
        ->addError($this->t('Option <em>@option</em> is missing or misspelled', ['@option' => 'csv_remote_url']));
      return FALSE;
    }

    return TRUE;
  }

  /**
   * @param $key
   *
   * @return mixed|null
   */
  public function getOption($key): mixed {
    return $this->options[$key] ?? NULL;
  }

  /**
   * @param $key
   * @param $value
   *
   * @return $this
   */
  public function setOption($key, $value): static {
    $this->options[$key] = $value;
    return $this;
  }

  /**
   * @return bool
   */
  protected function downloadCsv(): bool {
    try {
      $this->messenger()->addMessage($this->t('Downloading file...'));
      $content = file_get_contents($this->getOption('csv_remote_url'));
      file_put_contents($this->translationDirectoryPath . $this->getOption('csv_output_filename'), $content);
      $this->messenger()->addStatus($this->t('File downloaded'));
      return TRUE;
    }
    catch (\Exception $exception) {
      $this->messenger()->addError($this->t('Unable to download file'));
      $this->messenger()->addError($exception->getMessage());
    }

    return FALSE;
  }

  /**
   * @return bool
   */
  protected function readCsv(): bool {
    $this->messenger()->addMessage($this->t('Reading file...'));

    try {
      $this->csvReader = Reader::createFromPath($this->translationDirectoryPath . $this->getOption('csv_output_filename'));
      if ($this->csvReader->count() < 1) {
        $this->messenger()
          ->addMessage($this->t('File is empty, nothing to do.'));
        return FALSE;
      }

      $this->csvReader->setHeaderOffset(0);

      $headers = $this->csvReader->getHeader();

      $langcodes = $this->languageManager()->getStandardLanguageListWithoutConfigured();

      // Récup des langues disponibles dans le fichier csv
      foreach ($headers as $header) {
        $langcode = strtolower($header);

        if ($langcode !== 'en') {
          if ((bool) $this->getOption('check_enabled_languages') === FALSE && isset($langcodes[$langcode])) {
            $this->csvLangcodes[$langcode] = $langcode;
          }
          elseif ($this->languageManager()->getLanguage($langcode) instanceof \Drupal\Core\Language\LanguageInterface) {
            $this->csvLangcodes[$langcode] = $langcode;
          }
        }
      }
      $this->messenger()->addMessage($this->t('File read'));
    }
    catch (\League\Csv\Exception $exception) {
      $this->messenger()->addError($exception->getMessage());
    }

    return TRUE;
  }

  /**
   *
   * @param string $langcode
   */
  protected function updatePoFile(string $langcode): void {
    $filename = $this->getOption('extension_name') . '.' . $langcode . '.po';
    $filepath = $this->translationDirectoryPath . $filename;

    $this->messenger()
      ->addMessage($this->t('Treating @file...', ['@file' => $filepath]));

    $loader = new PoLoader();
    $generator = new PoGenerator();

    $date = new DrupalDateTime();

    //
    if (!@file_exists($filepath)) {
      $this->file_system->createFilename($filename, $this->translationDirectoryPath);
    }

    // Remplacement total : création fichier
    if ($this->getOption('translations_replace_all') === TRUE) {
      $translations = Translations::create(NULL, $langcode);
      $translations->getHeaders()
        ->set('Content-Transfer-Encoding', '8bit')
        ->set('Content-Type', 'text/plain; charset=UTF-8')
        ->set('MIME-Version', '1.0')
        ->set('Plural-Forms', 'nplurals=2; plural=(n>1);')
        ->set('POT-Creation-Date', $date->format('Y-m-d H:i+0000'));
    }
    // Traite fichier existant
    else {
      $translations = $loader->loadFile($filepath);
    }

    // Update date
    $translations->getHeaders()
      ->set('PO-Revision-Date', $date->format('Y-m-d H:i+0000'));

    // Traitement traductions
    $previousTranslationComment = '';
    foreach ($this->csvReader->getRecords() as $record) {
      if (!empty($record['EN']) && !empty($record[strtoupper($langcode)])) {
        $record['__SOURCE'] = trim($record['EN']);
        $record['__VALUE'] = trim($record[strtoupper($langcode)]);

        // Nouvelle traduction, car nouveau fichier
        if ($this->getOption('translations_replace_all') === TRUE) {
          $translation = $this->addTranslation($record);
        }
        else {
          // Mise à jour de la traduction si existante
          if ($this->getOption('translations_allow_update') === TRUE) {
            $translation = $translations->find($record['CONTEXT'], $record['__SOURCE']);
            if (!$translation) {
              $translation = $this->addTranslation($record);
            }
          }
          // Ou force nouvelle traduction à la suite dans le fichier
          else {
            $translation = $this->addTranslation($record);
          }
        }

        // Commentaire
        if (!empty($record['PAGE'])) {
          if (strtolower($previousTranslationComment) !== strtolower($record['PAGE'])) {
            $translation->getComments()
              ->add('---------------------------------------------------------------------');
            $translation->getComments()
              ->add('------ ' . strtoupper($record['PAGE']));
            $translation->getComments()->add('------');
            $previousTranslationComment = $record['PAGE'];
          }
        }

        //$translation->translate($record['__VALUE']);
        $translation = $this->setTranslation($record, $translation);
        $translations->add($translation);
      }
    }

    // Save file
    $generator->generateFile($translations, $filepath);

    $this->messenger()
      ->addStatus($this->t('File @filename has been generated', ['@filename' => $filepath]));
  }

  /**
   * @param array $record
   *
   * @return \Gettext\Translation
   */
  protected function addTranslation(array $record): Translation {
    return Translation::create($record['CONTEXT'], $record['__SOURCE']);
  }

  /**
   * @param array $record
   * @param \Gettext\Translation $translation
   *
   * @return \Gettext\Translation
   */
  protected function setTranslation(array $record, Translation $translation): Translation {
    $plural = !empty($record['PLURAL']) ? $record['PLURAL'] : NULL;

    if ($plural) {
      $valuesFrom = explode($this->getOption('plural_value_separator'), $record['__SOURCE']);
      $valuesTo = explode($this->getOption('plural_value_separator'), $record['__VALUE']);

      // Plural translation
      if (count($valuesTo) === 2 && count($valuesFrom) === count($valuesTo)) {
        $translation = Translation::create($record['CONTEXT'], $valuesFrom[0]);
        $translation->setPlural($valuesFrom[1]);
        $translation->translate($valuesTo[0]);
        $translation->translatePlural($valuesTo[1]);
      }
    }
    else {
      $translation->translate($record['__VALUE']);
    }

    return $translation;
  }

  /**
   * @return void
   */
  protected function cleanup(): void {
    $url = Url::fromRoute('locale.translate_status');
    $this->messenger()
      ->addStatus($this->t('Everything done. Check newly po generated files and go to the <a href=":url">:url</a> to import updates', [
        ':url' => $url->setAbsolute()
          ->toString(),
      ]));
  }

  /**
   * Set the default options for the class.
   *
   * @return $this
   */
  private function setDefaultOptions(): static {
    $defaults = self::$defaultOptions;

    $settings = Settings::get('drup_csv2po');
    if (!empty($settings)) {
      $defaults = array_merge($defaults, ($settings));
    }

    $this->options = $defaults;
    return $this;
  }

}
