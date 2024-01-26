<?php

// FileCompiler=0

declare(strict_types=1);

namespace ProcessWire;

use Fluency\Caching\TranslationCache;
use Fluency\App\{
  FluencyMarkup as Markup,
  FluencyLocalization as Localization
};
use Fluency\Caching\EngineLanguagesCache;
use Fluency\Components\FluencyApiUsageTableFieldset;
use Fluency\DataTransferObjects\{
  EngineInfoData,
  EngineTranslatableLanguagesData,
  FluencyConfigData,
  FluencyEngineSelectData,
};
use Fluency\Engines\{ FluencyEngineConfig, FluencyEngine };
use \RuntimeException;

use function Fluency\Functions\{ createLanguageConfigName, isLanguageConfigName };

/**
 * @todo Check default values for production
 */

class FluencyConfig extends ModuleConfig {

  private const LANGUAGE_ASSOCIATION_COLUMN_COUNT = 3;

  private const ENGINE_NAMESPACE = 'Fluency\Engines\%{ENGINE_DIRECTORY}\%{CLASS_NAME}';

  private ?FluencyEngine $engine = null;

  private ?FluencyEngineConfig $engineConfig = null;

  private ?EngineInfoData $engineInfo = null;

  private ?FluencyConfigData $fluencyConfigData = null;

  /**
   * Config variable default values
   * If an engine has been selected, merges default values from the engine configuration
   *
   * @return array Default key/values for module configuration
   */
  public function getDefaults(): array {
    return [
      'translation_api_ready' => false,
      'translation_cache_enabled' => true,
      'selected_engine' => null,
      'inputfield_translation_action' => 'translate_to_all_languages',
    ];
  }

  /**
   * Creates a module config DTO that is passed to classes outside of FluencyConfig, including
   * Engine and Engine Config classes optionally
   *
   * NOTE: Does not return configured languages.
   */
  public function getConfigData(): ?FluencyConfigData {
    if (!!$this->fluencyConfigData) {
      return $this->fluencyConfigData;
    }

    $moduleConfig = $this->getModuleConfig();

    if (!$moduleConfig->selected_engine) {
      return null;
    }

    $selectedEngine = FluencyEngineSelectData::fromJson($moduleConfig->selected_engine);

    $fluencyDefaultProperties =  array_keys($this->getDefaults());
    $engineDefaults = $selectedEngine->configClass::getConfig();

    $configData = (array) $moduleConfig;

    $configData['selected_engine'] = $selectedEngine;

    // Unset raw language config values
    $fluencyConfig = array_filter(
      $configData,
      fn ($k) => !isLanguageConfigName($k) &&
        !array_key_exists($k, $engineDefaults) &&
        in_array($k, $fluencyDefaultProperties),
      ARRAY_FILTER_USE_KEY
    );

    // Separate out engine config and relocate them under the engine property
    $fluencyConfig['engine'] = array_filter(
      array_merge($engineDefaults, $configData),
      fn ($k) => array_key_exists($k, $engineDefaults),
      ARRAY_FILTER_USE_KEY
    );

    return $this->fluencyConfigData = FluencyConfigData::fromArray($fluencyConfig);
  }

  /**
   * Initialize the selected engine, info, and config. Assign to instance variables, then return
   * self for chaining
   *
   * @return self
   */
  public function initTranslationEngine(): ?array {
    $configData = $this->getConfigData();

    if (!$configData->selected_engine) {
      return null;
    }

    $selectedEngine = $configData->selected_engine;

    // Create translation engine classes
    $this->engineInfo ??= $selectedEngine->info();

    $this->engineConfig ??= new $selectedEngine->configClass($configData);

    $this->engine ??= new $selectedEngine->engineClass($configData);

    return [
      'engine' => $this->engine,
      'config' => $this->engineConfig,
      'info' => $this->engineInfo
    ];
  }

  /**
   * Internal module use only
   *
   * @param  array ...$newConfigData Named arguments
   */
  private function saveModuleConfig(...$newConfigData): void {
    $this->modules->saveModuleConfigData('Fluency', [
      ...(array) $this->getModuleConfig(),
      ...$newConfigData
    ]);
  }

  /**
   * Internal module use only
   *
   * @return object Config as an object
   */
  private function getModuleConfig(): object {
    return (object) [...$this->getDefaults(), ...$this->modules->getModuleConfigData('Fluency')];
  }

  /**
   * Resets all configured engine data by removing the selected engine and it's associated settings
   * @return [type] [description]
   */
  public function resetEngineData(): void {

  }

  /**
   * This renders all Fluency config fields, as well as integrating the Translation Engine Config
   * fields, when necessary, when an engine is selected
   *
   * @return InputfieldWrapper Object for output to module config page.
   */
  public function getInputFields(): InputfieldWrapper {
    $inputfields = parent::getInputFields();
    $modules = $this->modules;
    $moduleConfig = $this->getModuleConfig();

    /**
     * Module Information
     */

    $inputfields->add([
      'type' => 'InputfieldMarkup',
      'label' => __('Information'),
      'value' => Markup::concat(
        Markup::h(3, __("Fluency - Integrated content translation for ProcessWire")),
        Markup::p([
          __("Fluency brings powerful third party translation services to enhance and extend the powerful core multi-language capabilities of ProcessWire. It provides a clean user interface for editing content, a RESTful API for developers who want to use Fluency asynchronously within the ProcessWire admin, and a stable module interface that can be accessed throughout your codebase."),
          __('Fluency is also modular. New translation service providers can be added as Fluency Translation Engines.')
        ])
      ),
    ]);

    /**
     * Engine select
     */

    $engineSelected = $moduleConfig->selected_engine;

    $fieldset = $modules->InputfieldFieldset;
    $fieldset->label = __('Translation Engine Configuration');
    $fieldset->collapsed = Inputfield::collapsedNever;
    $fieldset->add([
      "selected_engine" => [
        'type' => 'InputfieldSelect',
        'label' => __('Select Engine'),
        'collapsed' =>  Inputfield::collapsedNever,
        'themeBorder' => 'hide',
        'description' => __('Each translation engine uses a differend third party service. Each have their own set of features and languages that they translate. Features and abilities will vary depending on the Translation Engine chosen. Use the one that best suits your application.'),
        'notes' => $engineSelected ? null
          : __('You must choose a translation engine and save the page to continue configuring Fluency'),
        'required' => true,
        'options' => $this->createTranslationEngineOptions()
      ]
    ]);

    if (!$engineSelected) {
      return $inputfields->add($fieldset);
    }

    // Init translation engine when one has been selected
    [
      'engine' => $engine,
      'info' => $engineInfo,
      'config' => $engineConfig
    ] = $this->initTranslationEngine();

    // Engine credits
    $fieldset->add([
      'type' => 'InputfieldMarkup',
      'name' => '',
      'skipLabel' =>  Inputfield::skipLabelHeader,
      'themeBorder' => 'hide',
      'value' => $modules->MarkupAdminDataTable
        ->set('sortable', false)
        ->headerRow([
          __('Name'),
          __('Engine Version'),
          __('Translation Provider'),
          __('Provider API Version'),
          __('Service Documenation'),
          __('Created By'),
          __('Creator Website'),
        ])->row([
          $engineInfo->name,
          $engineInfo->version,
          $engineInfo->provider,
          $engineInfo->providerApiVersion,
          [__('Link') => $engineInfo->providerApiDocs],
          $engineInfo->authorName ?? '-',
          $engineInfo->authorUrl ? [__('Link') => $engineInfo->authorUrl] : '-',
        ])->render(),
    ]);

    if ($engineInfo->details) {
      $fieldset->add([
        'type' => 'InputfieldMarkup',
        'name' => '',
        'skipLabel' =>  Inputfield::skipLabelHeader,
        'themeBorder' => 'hide',
        'value' => $engineInfo->details,
      ]);
    }

    // Import the engine's configuration fields
    $fieldset->import($engineConfig->renderConfigInputs());

    $inputfields->add($fieldset);

    $previouslySelectedEngine = $this->page->meta()->get('previously_selected_engine');

    $engineChanged = $previouslySelectedEngine !== $moduleConfig->selected_engine;

    if ($engineChanged) {
      $this->page->meta()->set('previously_selected_engine', $moduleConfig->selected_engine);
    }

    $engineLanguages = null;

    // Verify API credentials by getting translatable languages
    // 2 birds, 1 stone
    if ($moduleConfig->selected_engine && !$moduleConfig->translation_api_ready || $engineChanged) {
      $engineLanguages = $engine->getLanguages();

      if ($engineLanguages->error) {
        $this->saveModuleConfig(translation_api_ready: false);

        $this->wire->message($engineLanguages->message);

        return $inputfields;
      }

      $this->saveModuleConfig(translation_api_ready: true);
    }

    // Get languages if the API
    $engineLanguages ??= $engine->getLanguages();

    // Handle authentication failure, show error message
    if ($engineLanguages->error) {
      $this->saveModuleConfig(translation_api_ready: false);

      if (!$engineChanged) {
        $this->wire->error(
          Localization::get('errors', $engineLanguages->error)
        );
      }

      return $inputfields;
    }

    /**
     * API usage table
     */

    if ($this->engineInfo->providesUsageData) {
      $inputfields->import(FluencyApiUsageTableFieldset::render());
    }

    if (!$this->engineInfo->providesUsageData) {
      ['title' => $title, 'noUsageProvided' => $noUsage] = (array) Localization::getFor('usage');

      $inputfields->add([
        'type' => 'InputfieldMarkup',
        'label' => $title,
        'value' => Markup::p($noUsage),
      ]);
    }

    /**
     * Available source/target languages
     */

    $liMarkup = (object) array_reduce(
      $engineLanguages->languages,
      function ($markup, $language) {
        $sourceCode = strtolower($language->sourceCode);
        $targetCode = strtolower($language->targetCode);

        $sourceItem = Markup::li("{$language->sourceName} - {$sourceCode}");
        $targetItem = Markup::li("{$language->targetName} - {$targetCode}");

        !in_array($sourceItem, $markup['source']) && ($markup['source'][] = $sourceItem);
        !in_array($targetItem, $markup['target']) && ($markup['target'][] = $targetItem);

        return $markup;
      },
      ['source' => [], 'target' => []],
    );

    $inputfields->add([
      'type' => 'InputfieldFieldset',
      'name' => 'fieldset_available_langauges',
      'label' => __('Translation Engine Available Languages'),
      'description' => __('Ensure that the languages you are translating are available here'),
      'collapsed' => Inputfield::collapsedYes,
      'children' => [
        // Source Languages
        [
          'type' => 'InputfieldMarkup',
          'label' => __('Source Languages'),
          'notes' => __('The language you are translating from must be listed in this column.'),
          'value' => Markup::ol($liMarkup->source),
          'columnWidth' => 50,
          'collapsed' => Inputfield::collapsedNever,
          'themeBorder' => 'hide',
        ],
        // Target Languages
        [
          'type' => 'InputfieldMarkup',
          'label' => __('Target Languages'),
          'notes' => __('The language you are translating to must be listed in this column.'),
          'value' => Markup::ol($liMarkup->target),
          'columnWidth' => 50,
          'collapsed' => Inputfield::collapsedNever,
          'themeBorder' => 'hide',
        ],
      ]
    ]);

    /**
     * Language Associations
     */

    $fieldset = $modules->InputfieldFieldset;
    $fieldset->name = 'fieldset_language_associations';
    $fieldset->label = __('Language Associations');
    $fieldset->description = __('Choose translation languages to associate with the languages configured in ProcessWire.');

    // Set up a language association for all languages present in PW
    foreach ($this->languages as $pwLanguage) {
      $fieldset->import(
        $this->addLanguageAssociationSelect($pwLanguage, $engineLanguages)
      );
    }

    // If there are empty columns, add to keep inputfield proportions and make the language
    // associations list look nice.
    $remainingCols = $this->languages->count() % self::LANGUAGE_ASSOCIATION_COLUMN_COUNT;

    while ($remainingCols >= 0) {
      $fieldset->append($this->createFillerFieldset(self::LANGUAGE_ASSOCIATION_COLUMN_COUNT));

      $remainingCols--;
    }

    $inputfields->add($fieldset);

    /**
     * Fluency Options
     */

    $inputfields->add([
      'type' => 'InputfieldFieldset',
      'name' => 'fieldset_general_configurations',
      'label' => __('Fluency Options'),
      'children' => [
        // Translation caching
        'translation_cache_enabled' => [
          'type' => 'InputfieldCheckbox',
          'label' => __('Enable Translation Caching'),
          'collapsed' => Inputfield::collapsedNever,
          'themeBorder' => 'hide',
          'notes' => 'Cached translations are kept for one month.',
          'columnWidth' => 50,
          'defaultValue' => $this->getDefaults()['translation_cache_enabled'],
          'description' => __('Fluency has the ability to cache translations. Enabling caching can help keep API usage lower and increase the speed of translation when the same content is translated more than once.'),
          'checkedValue' => 1,
          'uncheckedValue' => 0
        ],
        // Cache clearing
        'translation_cache_management' => [
          'type' => 'InputfieldMarkup',
          'label' => __('Translation Cache Management'),
          'collapsed' => $moduleConfig->translation_cache_enabled ? Inputfield::collapsedNever
            : Inputfield::collapsedHidden,
          'columnWidth' => 50,
          'themeBorder' => 'hide',
          'themeColor' => 'none',
          'children' => [
            'type' => 'InputfieldButton',
            'collapsed' => Inputfield::collapsedNever,
            'text' => __('Clear Translation Cache'),
            'name' => "cache_clear_button",
            'notes' => __('Cached translations: ') . (new TranslationCache())->count(),
            'value' => 1,
            'attributes' => [
              'icon' => 'bomb',
              // CHANGE TO addClass
              'class' => $modules->InputfieldButton->getAttribute('class') . ' js-ft-clear-translation-cache'
            ]
          ]
        ],
        // Cache clearing
        'translatable_languages_cache_management' => [
          'type' => 'InputfieldMarkup',
          'label' => __('Translatable Languages Cache Management'),
          'description' => __('Fluency caches the list of languages a third party supports. If a translation service introduces a language that is not available in Fluency, clear this cache.'),
          'collapsed' => Inputfield::collapsedNever,
          'columnWidth' => 50,
          'themeBorder' => 'hide',
          'themeColor' => 'none',
          'children' => [
            'type' => 'InputfieldButton',
            'collapsed' => Inputfield::collapsedNever,
            'text' => __('Clear Translatable Languages Cache'),
            'name' => "cache_clear_button",
            'notes' => (new EngineLanguagesCache())->count() ? __('Languages are cached')
              : __('There are no languages cached'),
            'value' => 1,
            'attributes' => [
              'icon' => 'bomb',
              'class' => $modules->InputfieldButton->getAttribute('class') . ' js-ft-clear-translatable-languages-cache'
            ]
          ]
        ],
        "inputfield_translation_action" => [
          'type' => 'InputfieldSelect',
          'label' => __('Inputfield Translation Action'),
          'collapsed' =>  Inputfield::collapsedNever,
          'themeBorder' => 'hide',
          'description' => __('Field translations can be made using two different methods. Translations can be made by clicking a button on each language tab that translates from the default langauge, or a button on each tab that translates to all languages at once.'),
          'notes' => $engineSelected ? null
            : __('You must choose a translation engine and save the page to continue configuring Fluency'),
          'required' => true,
          'options' => [
            'translate_each_language' => 'Individual language tab translation',
            'translate_to_all_languages' => 'Translate to all languages',
            'both' => 'Both',
          ]
        ],
        // Localization
        'fluency_localization' => [
          'type' => 'InputfieldMarkup',
          'collapsed' => Inputfield::collapsedNever,
          'label' => __('Localizing Fluency'),
          'themeBorder' => 'hide',
          'value' => Markup::concat(
            Markup::p([
              __("All UI components, messages, and errors can be translated using ProcessWire's language configuration pages located under Setup > Languages. Under 'Site Translation Files' click 'Find Files To Translate' and choose /site/modules/Fluency/app/FluencyLocalization.php"),
              Markup::a("{$this->pages->get('name=languages')->url}", __('ProcessWire Language Configuration Pages'), [], '_blank')
            ])
          ),
        ]
      ]
    ]);

    /**
     * Donations
     */

    $inputfields->add([
      'type' => 'InputfieldMarkup',
      'name' => 'fieldset_donation',
      'skipLabel' =>  Inputfield::skipLabelHeader,
      'collapsed' => Inputfield::collapsedNever,
      'value' => Markup::concat(
        Markup::h(3, __("Support ProcessWire Module Development")),
        Markup::p([
          __("Module developers are dedicated members of the ProcessWire community and an important component of the ecosystem. Many hours and hard work are put into developing and maintaining modules that create great developer and end user experiences. Consider donating to developers of the modules you find most useful or are a consistent part of your projects!"),
        ]),
        Markup::div(
          Markup::a(
            href: 'https://paypal.me/noonesboy',
            content: Markup::img("{$this->wire('urls')->get('Fluency')}/assets/img/paypal_me.png", 'PayPal Me'),
            rel: 'noopener',
            target: '_blank'
          ),
          'button-donate'
        )
      )
    ]);

    return $inputfields;
  }

  /**
   * Private support methods
   */

  /**
   * Creates a fillter fieldset to act as spacing in fieldset column layouts
   *
   * @param int $width Width as a %
   */
  private function createFillerFieldset(int $width): InputfieldFieldset {
    $filler = $this->modules->InputfieldFieldset;
    $filler->attr('style', $filler->attr('style') . ' visibility: hidden !important;');
    $filler->wrapAttr('style', $filler->attr('style') . ' visibility: hidden !important;');
    $filler->columnWidth = 100 / $width;
    $filler->themeBorder = 'hide';
    $filler->themeColor = 'none';

    return $filler;
  }

  /**
   * Adds a language association select inputfield for a given ProcessWire language object
   *
   * @param Language                        $pwLanguage             ProcessWire language object
   * @param EngineTranslatableLanguagesData $engineLanguages        Object from translation engine
   *                                                                containing source/target
   */
  private function addLanguageAssociationSelect(
    Language $pwLanguage,
    EngineTranslatableLanguagesData $engineLanguages,
  ): InputfieldWrapper {
    $userLanguage = $this->user->language->name;

    // Get information from languages configured in ProcessWire
    $pwLanguageName = $pwLanguage->getLanguageValue($userLanguage, 'name');
    $pwLanguageTitle = $pwLanguage->getLanguageValue($userLanguage, 'title');

    $isDefault = $pwLanguageName === 'default';

    $options = array_reduce($engineLanguages->languages, fn($options, $language) => [
      ...$options,
      ...[json_encode($language) => $language->targetName]
    ], []);

    $configName = createLanguageConfigName($pwLanguage->id, $this->engineInfo);

    return $this->modules->InputfieldWrapper->add([
      [
        'type' => 'InputfieldFieldset',
        'themeBorder' => 'hide',
        'collapsed' => Inputfield::collapsedNever,
        'label' => ($isDefault ? __('ProcessWire Default:') : 'ProcessWire:') . " $pwLanguageTitle",
        'columnWidth' => 100 / self::LANGUAGE_ASSOCIATION_COLUMN_COUNT,
        'children' => [
          $configName => [
            'type' => 'InputfieldSelect',
            'themeBorder' =>  'hide',
            'collapsed' =>  Inputfield::collapsedNever,
            'label' =>  __("Translator Language"),
            'required' => $isDefault,
            'options' => $options
          ]
        ]
      ]
    ]);
  }

  /**
   * Get all available translation engines located in app/Engines as InputfieldSelect option
   * keys/values
   *
   * @return array
   */
  private function createTranslationEngineOptions(): array {
    $fluencyEngineDir = __DIR__ . '/app/Engines/';

    $engineDirs = array_filter(
      scandir($fluencyEngineDir),
      fn ($directory) => !str_contains($directory, '.') && !str_contains($directory, 'Traits')
    );

    // Create config engine select options for all found engines
    return array_reduce($engineDirs, function ($engines, $engineDir) use ($fluencyEngineDir) {
      $contents = scandir("{$fluencyEngineDir}{$engineDir}");

      // Get all engine classes in this engine directory
      $engineAssets = array_reduce($contents, function ($classes, $item) {
        str_ends_with($item, 'Info.php') && $classes['infoClass'] = $item;
        str_ends_with($item, 'Engine.php') && $classes['engineClass'] = $item;
        str_ends_with($item, 'Config.php') && $classes['configClass'] = $item;

        return $classes;
      }, []);

      // Check that this engine has all of the required files
      count($engineAssets) < 3 && throw new RuntimeException(
        __("Translation engines require Info, Engine, and Config files/classes")
      );

      // Create fully qualified class names
      array_walk($engineAssets, function (&$asset) use ($engineDir) {
        $asset = strtr(self::ENGINE_NAMESPACE, [
          '%{ENGINE_DIRECTORY}' => $engineDir,
          '%{CLASS_NAME}' => rtrim($asset, '.php'),
        ]);
      });

      $engineData = FluencyEngineSelectData::fromArray($engineAssets);

      $engines[json_encode($engineData)] = $engineData->info()->name;

      return $engines;
    }, []);
  }
}
