<?php

namespace Drupal\icms_bundle_event_logic\Plugin\search_api\processor;

use Drupal\paragraphs\ParagraphInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds parent node fields to event occurrence paragraphs during indexing.
 *
 * @SearchApiProcessor(
 *   id = "event_occurrence_parent_fields",
 *   label = @Translation("Event Occurrence Parent Fields"),
 *   description = @Translation("Adds parent node status and topics to event occurrence paragraphs for faceting and filtering."),
 *   stages = {
 *     "add_properties" = 0,
 *     "preprocess_index" = -10,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class EventOccurrenceParentFields extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource || $datasource->getEntityTypeId() === 'paragraph') {
      $definition = [
        'label' => $this->t('Parent Node Status'),
        'description' => $this->t('Published status of the parent event node'),
        'type' => 'boolean',
        'processor_id' => $this->getPluginId(),
      ];
      $properties['parent_status'] = new ProcessorProperty($definition);

      // Register field_icms_topics property to match the property_path in config.
      // This allows graphql_search_api_query's resolveFilterLabel() to find
      // the field definition and resolve term names automatically.
      $definition = [
        'label' => $this->t('Topics'),
        'description' => $this->t('Topics from the parent event node'),
        'type' => 'integer',
        'is_list' => TRUE,
        'processor_id' => $this->getPluginId(),
      ];
      $properties['field_icms_topics'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $entity = $item->getOriginalObject()->getValue();

    // Only process paragraph entities of type icms_event_occurrence.
    if (!$entity instanceof ParagraphInterface || $entity->bundle() !== 'icms_event_occurrence') {
      return;
    }

    // Get the parent entity (node).
    $parent = $entity->getParentEntity();
    if (!$parent || $parent->getEntityTypeId() !== 'node') {
      return;
    }

    // Get the fields.
    $fields = $item->getFields();

    // Add parent_status field.
    if (isset($fields['parent_status'])) {
      $fields['parent_status']->addValue($parent->get('status')->value);
    }

    // Add topics field (term IDs).
    if (isset($fields['topics']) && $parent->hasField('field_icms_topics')) {
      $topics = $parent->get('field_icms_topics')->referencedEntities();
      foreach ($topics as $topic) {
        $fields['topics']->addValue($topic->id());
      }
    }
  }

}
