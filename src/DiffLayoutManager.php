<?php

namespace Drupal\diff;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Plugin type manager for field diff builders.
 *
 * @ingroup diff_layout_builder
 */
class DiffLayoutManager extends DefaultPluginManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Wrapper object for writing/reading simple configuration from diff.settings.yml
   */
  protected $config;

  /**
   * Wrapper object for writing/reading simple configuration from diff.plugins.yml
   */
  protected $layoutPluginsConfig;

  /**
   * Constructs a DiffLayoutManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param EntityTypeManagerInterface $entity_type_manager
   *   Entity Manager service.
   * @param ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $configFactory) {
    parent::__construct('Plugin/diff/Layout', $namespaces, $module_handler, '\Drupal\diff\DiffLayoutInterface', 'Drupal\diff\Annotation\DiffLayoutBuilder');

    $this->setCacheBackend($cache_backend, 'diff_layout_builder_plugins');
    $this->alterInfo('field_diff_builder_info');
    $this->entityTypeManager = $entity_type_manager;
    $this->config = $configFactory->get('diff.settings');
    $this->layoutPluginsConfig =  $configFactory->get('diff.layout_plugins');
  }

  /**
   * Gets the applicable layout plugins.
   *
   * Loop over the plugins that can be used to display the diff comparison
   * sorting them by the weight.
   *
   * @return array
   *   The layout plugin options.
   */
  public function getPluginOptions() {
    $plugins = $this->config->get('general_settings' . '.' . 'layout_plugins');
    $plugin_options = [];
    // Get the plugins sorted and build an array keyed by the plugin id.
    if ($plugins) {
      // Sort the plugins based on their weight.
      uasort($plugins, 'Drupal\Component\Utility\SortArray::sortByWeightElement');
      foreach ($plugins as $key => $value) {
        $plugin = $this->getDefinition($key);
        if ($value['enabled']) {
          $plugin_options[$key] = $plugin['label'];
        }
      }
    }
    return $plugin_options;
  }

  /**
   * Gets the default layout plugin selected.
   *
   * Take the first option of the array returned by getPluginOptions.
   *
   * @return string
   *   The id of the default plugin.
   */
  public function getDefaultLayout() {
    $plugins = array_keys($this->getPluginOptions());
    return reset($plugins);
  }
}
