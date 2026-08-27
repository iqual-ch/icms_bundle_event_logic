<?php

namespace Drupal\icms_bundle_event_logic\Plugin\GraphQL\SchemaExtension;

use Drupal\graphql\GraphQL\ResolverBuilder;
use Drupal\graphql\GraphQL\ResolverRegistryInterface;
use Drupal\graphql\Plugin\GraphQL\SchemaExtension\SdlSchemaExtensionPluginBase;
use Drupal\graphql_core_schema\TypeAwareSchemaExtensionInterface;
use Drupal\smart_date\Plugin\Field\FieldType\SmartDateItem;
use GraphQL\Language\Source;

/**
 * A schema extension formatting the end value of smart date items.
 *
 * The core composable schema's `formatted` field (formatted_date extension)
 * only resolves the start value of a smart date item. Event occurrences are
 * ranges, so the end value needs the same Drupal-date-format treatment to be
 * translatable and customizable per project.
 *
 * @SchemaExtension(
 *   id = "icms_event_dates",
 *   name = "ICMS Event dates",
 *   description = "Adds a formatted end value to smart date field items.",
 *   schema = "core_composable"
 * )
 */
class IcmsEventDatesExtension extends SdlSchemaExtensionPluginBase implements TypeAwareSchemaExtensionInterface {

  /**
   * {@inheritdoc}
   */
  public function getBaseDefinition(): ?Source {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionDefinition(): ?Source {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTypeExtensionDefinition(array $types) {
    if (in_array('FieldItemTypeSmartdate', $types)) {
      return $this->loadDefinitionFile('FieldItemTypeSmartdate');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function registerResolvers(ResolverRegistryInterface $registry): void {
    $builder = new ResolverBuilder();

    $registry->addFieldResolver(
      'FieldItemTypeSmartdate',
      'endFormatted',
      $builder->produce('formatted_date')
        ->map('timestamp', $builder->callback(
          fn (SmartDateItem $item) => $item->end_value !== NULL ? (int) $item->end_value : NULL
        ))
        ->map('format', $builder->fromArgument('format'))
        ->map('drupalDateFormat', $builder->fromArgument('drupalDateFormat'))
    );
  }

}
