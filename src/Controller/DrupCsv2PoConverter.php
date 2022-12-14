<?php

namespace Drupal\drup_csv2po\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Url;
use Drupal\facets\Exception\Exception;
use Drupal\file\FileRepository;
use Drupal\pathauto\MessengerInterface;
use League\Csv\Reader;
use Gettext\Translations;
use Gettext\Translation;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;

/**
 * Returns responses for Drup Csv2Po routes.
 */
class DrupCsv2PoConverter extends ControllerBase {

    /**
     * @var array
     */
    private array $options;

    /**
     * @var array
     */
    public static array $defaultOptions = [
        // theme or module
        'extension_type' => 'theme',
        // theme or module machine name
        'extension_name' => null,
        // translation directory name
        'extension_translations_directory' => 'translations',
        // download csv content from remote url before converting
        'csv_remote_url' => null,
        // save remove csv content to local file
        'csv_output_filename' => 'translations.csv',
        // erase all existant translation
        'translations_replace_all' => TRUE,
        // allow to update existing translation if they exist, or force to create new ones
        'translations_allow_update' => FALSE,
        // separator for multiple cell values
        'plural_value_separator' => PHP_EOL
    ];

    /**
     * @var string
     */
    protected string $translationDirectoryPath;

    /**
     * @var array
     */
    protected array $csvLanguages = [];

    /**
     * @var \League\Csv\Reader
     */
    protected \League\Csv\Reader $csvReader;

    /**
     * @var \Drupal\Core\File\FileSystemInterface
     */
    private FileSystemInterface $file_system;

    /**
     * @var \Drupal\Core\Extension\ExtensionList
     */
    private ExtensionList $extension_list;

    /**
     * @var \Drupal\Core\Theme\ThemeManagerInterface
     */
    private ThemeManagerInterface $theme_manager;

    /**
     * @var \Drupal\Core\Extension\ThemeExtensionList
     */
    private ThemeExtensionList $theme_extension_list;

    /**
     * @var \Drupal\Core\Extension\ModuleExtensionList
     */
    private ModuleExtensionList $module_extension_list;

    /**
     * @param \Drupal\Core\File\FileSystemInterface $file_system
     * @param \Drupal\Core\Extension\ModuleExtensionList $module_extension_list
     * @param \Drupal\Core\Extension\ThemeExtensionList $theme_extension_list
     */
    public function __construct(FileSystemInterface $file_system, ModuleExtensionList $module_extension_list, ThemeExtensionList $theme_extension_list, ThemeManagerInterface $theme_manager) {
        $this->file_system = $file_system;
        $this->theme_manager = $theme_manager;
        $this->theme_extension_list = $theme_extension_list;
        $this->module_extension_list = $module_extension_list;

        $this->options = self::$defaultOptions;
    }

    public function setOption($key, $value) {
        $this->options[$key] = $value;
        return $this;
    }

    public function setOptions($options) {
        $this->options = $options;
        return $this;
    }

    public function getOption($key) {
        return $this->options[$key] ?? NULL;
    }

    public function getOptions() {
        return $this->options;
    }


    public function run() {
        // Options management
        if ($this->prepareOptions()) {
            // Download remote CSV file
            $this->downloadCsv();
            // Read file for parsing
            $this->readCsv();

            // Treat each language po file
            if (!empty($this->csvLanguages)) {
                foreach ($this->csvLanguages as $index => $csvLanguage) {
                    $this->updatePoFile($csvLanguage);
                }
            }
        }

        $this->cleanup();

        return $this;
    }


    /**
     * @return bool
     */
    protected function prepareOptions() {
        $this->extension_list = $this->getOption('extension_type') === 'theme' ? $this->theme_extension_list : $this->module_extension_list;

        // Error is extension is module but no module name provided
        if ($this->getOption('extension_type') === 'module' && empty($this->getOption('extension_name'))) {
            $this->messenger()->addError($this->t('Option <em>@option</em> is missing or misspelled', ['@option' => 'extension_name']));
            return false;
        }
        // Set default theme if not defined
        if ($this->getOption('extension_type') === 'theme' && empty($this->getOption('extension_name'))) {
            $this->setOption('extension_name', $this->theme_manager->getActiveTheme()->getName());
        }

        // Directories
        $this->translationDirectoryPath = $this->extension_list->getPath($this->getOption('extension_name'));
        $this->translationDirectoryPath = './' . rtrim($this->translationDirectoryPath, '/') . '/' . $this->getOption('extension_translations_directory') . '/';

        // Remote url is mandatory but empty
        if (empty($this->getOption('csv_remote_url'))) {
            $this->messenger()->addError($this->t('Option <em>@option</em> is missing or misspelled', ['@option' => 'csv_remote_url']));
            return false;
        }

        return true;
    }


    protected function downloadCsv() {
        try {
            $this->messenger()->addMessage($this->t('Downloading file...'));
            $content = file_get_contents($this->getOption('csv_remote_url'));
            file_put_contents($this->translationDirectoryPath . $this->getOption('csv_output_filename'), $content);
            $this->messenger()->addStatus($this->t('File downloaded'));
        }
        catch (\Exception $exception) {
            $this->messenger()->addError($this->t('Unable to download file'));
            $this->messenger()->addError($exception->getMessage());
        }

        return $this;
    }

    protected function readCsv() {
        $this->messenger()->addMessage($this->t('Reading file...'));
        $this->csvReader = Reader::createFromPath($this->translationDirectoryPath . $this->getOption('csv_output_filename'), 'r');
        $this->csvReader->setHeaderOffset(0);

        $headers = $this->csvReader->getHeader();

        // R??cup des langues disponibles dans le fichier csv
        foreach ($headers as $header) {
            $langcode = strtolower($header);

            if ($langcode !== 'en' && $this->languageManager()->getLanguage($langcode) instanceof \Drupal\Core\Language\LanguageInterface) {
                $this->csvLanguages[$langcode] = $this->languageManager()->getLanguage($langcode);
            }
        }
        $this->messenger()->addMessage($this->t('File read'));

        return $this;
    }

    /**
     *
     * @param \Drupal\Core\Language\Language $language
     *
     * @return void
     */
    protected function updatePoFile(Language $language) {
        $langcode = $language->getId();
        $filename = $this->getOption('extension_name') . '.' . $langcode . '.po';
        $filepath = $this->translationDirectoryPath . $filename;

        $this->messenger()->addMessage($this->t('Treating @file...', ['@file' => $filepath]));

        $loader = new PoLoader();
        $generator = new PoGenerator();

        $date = new DrupalDateTime();

        //
        if (!@file_exists($filepath)) {
            $this->file_system->createFilename($filename, $this->translationDirectoryPath);
        }

        // Remplacement total : cr??ation fichier
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
        $translations->getHeaders()->set('PO-Revision-Date', $date->format('Y-m-d H:i+0000'));

        // Traitement traductions
        $previousTranslationComment = '';
        foreach ($this->csvReader->getRecords() as $record) {
            if (isset($record['EN'], $record[strtoupper($langcode)]) && !empty($record[strtoupper($langcode)])) {
                $record['__SOURCE'] = trim($record['EN']);
                $record['__VALUE'] = trim($record[strtoupper($langcode)]);

                // Nouvelle traduction, car nouveau fichier
                if ($this->getOption('translations_replace_all') === TRUE) {
                    $translation = $this->addTranslation($record);
                }
                else {
                    // Mise ?? jour de la traduction si existante
                    if ($this->getOption('translations_allow_update') === TRUE) {
                        $translation = $translations->find($record['CONTEXT'], $record['__SOURCE']);
                        if (!$translation) {
                            $translation = $this->addTranslation($record);
                        }
                    }
                    // Ou force nouvelle traduction ?? la suite dans le fichier
                    else {
                        $translation = $this->addTranslation($record);
                    }
                }

                // Commentaire
                if (isset($record['PAGE']) && !empty($record['PAGE'])) {
                    if (strtolower($previousTranslationComment) !== strtolower($record['PAGE'])) {
                        $translation->getComments()->add('---------------------------------------------------------------------');
                        $translation->getComments()->add('------ ' . strtoupper($record['PAGE']));
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

        $this->messenger()->addStatus($this->t('File @filename has been generated', ['@filename' => $filepath]));
    }

    /**
     * @param array $record
     *
     * @return \Gettext\Translation
     */
    protected function addTranslation(array $record) {
        $translation = Translation::create($record['CONTEXT'], $record['__SOURCE']);

        return $translation;
    }

    /**
     * @param array $record
     * @param \Gettext\Translation $translation
     *
     * @return \Gettext\Translation
     */
    protected function setTranslation(array $record, Translation $translation) {
        $plural = isset($record['PLURAL']) && !empty($record['PLURAL']) ? $record['PLURAL'] : NULL;

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

    protected function cleanup() {
        $url = Url::fromRoute('locale.translate_status');
        $this->messenger()->addStatus($this->t('Everything done. Check newly po generated files and go to the <a href=":url">:url</a> to import updates', [':url' => $url->setAbsolute()->toString()]));
    }
}
