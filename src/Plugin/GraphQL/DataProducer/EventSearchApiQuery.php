<?php

namespace Drupal\icms_bundle_event_logic\Plugin\GraphQL\DataProducer;

use Drupal\graphql_search_api_query\Plugin\GraphQL\DataProducer\SearchApiQuery;

/**
 * Extended Search API Query data producer with custom label resolution.
 *
 * Overrides the filter label resolution to handle custom processor fields
 * that mimic entity references (e.g., topics from event occurrence parent nodes).
 *
 * @DataProducer(
 *   id = "event_search_api_query",
 *   name = @Translation("Event Search API Query"),
 *   description = @Translation("Extended Search API Query with custom facet label resolution"),
 *   produces = @ContextDefinition("any",
 *     label = @Translation("Search Results")
 *   ),
 *   consumes = {
 *     "indexId" = @ContextDefinition("string",
 *       label = @Translation("Index ID"),
 *       required = TRUE
 *     ),
 *     "keys" = @ContextDefinition("string",
 *       label = @Translation("Search Keys"),
 *       required = FALSE
 *     ),
 *     "filter" = @ContextDefinition("any",
 *       label = @Translation("Filter"),
 *       required = FALSE
 *     ),
 *     "facets" = @ContextDefinition("any",
 *       label = @Translation("Facets"),
 *       required = FALSE,
 *       multiple = TRUE
 *     ),
 *     "sort" = @ContextDefinition("any",
 *       label = @Translation("Sort"),
 *       required = FALSE,
 *       multiple = TRUE
 *     ),
 *     "limit" = @ContextDefinition("integer",
 *       label = @Translation("Limit"),
 *       required = FALSE
 *     ),
 *     "offset" = @ContextDefinition("integer",
 *       label = @Translation("Offset"),
 *       required = FALSE
 *     ),
 *     "language" = @ContextDefinition("string",
 *       label = @Translation("Language"),
 *       required = FALSE
 *     ),
 *   }
 * )
 */
class EventSearchApiQuery extends SearchApiQuery {

  /**
   * {@inheritdoc}
   */
  protected function resolveFilterLabel($index, $field_name, $filter_value, $language) {
    // Get the field configuration from the index.
    $fields = $index->getFields();
    if (!isset($fields[$field_name])) {
      return $filter_value;
    }

    $field = $fields[$field_name];
    $property_path = $field->getPropertyPath();

    // Custom handling for topics field from event_occurrence_parent_fields processor.
    if ($property_path === 'topics' && $field_name === 'topics') {
      try {
        $entity_type_manager = \Drupal::entityTypeManager();
        $term = $entity_type_manager->getStorage('taxonomy_term')->load($filter_value);

        if ($term) {
          // Handle translations.
          if ($term->isTranslatable() && $term->hasTranslation($language)) {
            $term = $term->getTranslation($language);
          }
          return $term->label();
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('icms_bundle_event_logic')->warning('Error resolving topic label: @message', ['@message' => $e->getMessage()]);
      }
    }

    // Fall back to parent implementation for all other fields.
    return parent::resolveFilterLabel($index, $field_name, $filter_value, $language);
  }

}
