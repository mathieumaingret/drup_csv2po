<?php

namespace Drupal\drup_csv2po\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\Language;
use Drupal\facets\Exception\Exception;
use Drupal\file\FileRepository;
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
     * @var string Name of CSV file containing values
     */
    protected string $csvFileName;

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
     * @var array
     */
    public static array $defaultOptions = [
        // theme or module
        'extension_type' => 'theme',
        // theme or module machine name
        'extension_name' => 'frontend',
        // translation directory name
        'translation_directory_name' => 'translations',
        // download csv content from remote url before converting
        'remote_csv_url' => null,
        // erase all existant translation
        'translations_replace_all' => TRUE,
        // allow to update existing translation if they exist, or force to create new ones
        'translations_allow_update' => FALSE,
        // separator for multiple cell values
        'plural_value_separator' => PHP_EOL
    ];

    /**
     * @param string $csvFileName
     * @param array $options
     */
    public function __construct(string $csvFileName, array $options = []) {
        $this->csvFileName = $csvFileName;
        $this->options = array_merge(self::$defaultOptions, $options);

        $this->translationDirectoryPath = \Drupal::service('extension.list.' . $this->getOption('extension_type'))->getPath($this->getOption('extension_name'));
        $this->translationDirectoryPath = './' . rtrim($this->translationDirectoryPath, '/') . '/' . $this->getOption('translation_directory_name') . '/';

        if (!empty($this->getOption('remote_csv_url'))) {
            $this->downloadCsv();
        }

        // Read csv data
        $this->readCsv();
    }

    public function run() {
        // Treat each language po file
        if (!empty($this->csvLanguages)) {
            foreach ($this->csvLanguages as $index => $csvLanguage) {
                $this->updatePoFile($csvLanguage);
            }
        }
    }

    /**
     * @return void
     */
    protected function readCsv() {
        \Drupal::messenger()->addStatus($this->t('Reading file...'));
        $this->csvReader = Reader::createFromPath($this->translationDirectoryPath . $this->csvFileName, 'r');
        $this->csvReader->setHeaderOffset(0);

        $headers = $this->csvReader->getHeader();

        // Récup des langues disponibles dans le fichier csv
        foreach ($headers as $header) {
            $langcode = strtolower($header);

            if ($langcode !== 'en' && $this->languageManager()->getLanguage($langcode) instanceof \Drupal\Core\Language\LanguageInterface) {
                $this->csvLanguages[$langcode] = $this->languageManager()->getLanguage($langcode);
            }
        }
        \Drupal::messenger()->addStatus($this->t('File read'));
    }

    /**
     * @return void
     */
    protected function downloadCsv() {
        try {
            \Drupal::messenger()->addStatus($this->t('Downloading file...'));
            $content = file_get_contents($this->getOption('remote_csv_url'));
            file_put_contents($this->translationDirectoryPath . $this->csvFileName, $content);
            \Drupal::messenger()->addStatus($this->t('File downloaded'));

        } catch (Exception $exception) {

        }
    }

    /**
     *
     * @param \Drupal\Core\Language\Language $language
     *
     * @return void
     */
    protected function updatePoFile(Language $language) {
        $langcode = $language->getId();
        $filename = 'frontend.' . $langcode . '.po';
        $filepath = $this->translationDirectoryPath . $filename;

        \Drupal::messenger()->addStatus($this->t('Treating @file...', ['@file' => $filepath]));

        $loader = new PoLoader();
        $generator = new PoGenerator();

        $date = new DrupalDateTime();

        //
        if (!@file_exists($filepath)) {
            \Drupal::service('file_system')->createFilename($filename, $this->translationDirectoryPath);
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

        \Drupal::messenger()->addStatus($this->t('File @filename has been generated', ['@filename' => $filepath]));
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


    public function setOption($key, $value) {
        $this->options[$key] = $value;
    }

    public function getOption($key) {
        return $this->options[$key] ?? NULL;
    }

    public function getOptions() {
        return $this->options;
    }

}
