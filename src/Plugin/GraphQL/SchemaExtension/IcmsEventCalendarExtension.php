<?php

namespace Drupal\icms_bundle_event_logic\Plugin\GraphQL\SchemaExtension;

use Drupal\graphql\GraphQL\ResolverBuilder;
use Drupal\graphql\GraphQL\ResolverRegistryInterface;
use Drupal\graphql\Plugin\GraphQL\SchemaExtension\SdlSchemaExtensionPluginBase;
use GraphQL\Language\Source;

/**
 * Exposes the event calendar settings to the frontend.
 *
 * The settings live in the icms_bundle_event_logic.calendar_settings config
 * object, which deliberately has no admin UI: projects tune the calendar with
 * a config override or `drush config:set` without touching the components.
 *
 * @SchemaExtension(
 *   id = "icms_event_calendar",
 *   name = "ICMS Event calendar",
 *   description = "Exposes the event calendar settings.",
 *   schema = "core_composable"
 * )
 */
class IcmsEventCalendarExtension extends SdlSchemaExtensionPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getBaseDefinition(): ?Source {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function registerResolvers(ResolverRegistryInterface $registry): void {
    $builder = new ResolverBuilder();

    $registry->addFieldResolver(
      'Query',
      'eventCalendarSettings',
      $builder->callback(fn () => \Drupal::config('icms_bundle_event_logic.calendar_settings'))
    );

    $defaults = [
      'weekStart' => ['week_start', 1],
      'showTopicFilter' => ['show_topic_filter', TRUE],
      'maxEventsPerDay' => ['max_events_per_day', 3],
      'showEventTime' => ['show_event_time', TRUE],
    ];
    foreach ($defaults as $field => [$key, $default]) {
      $registry->addFieldResolver(
        'EventCalendarSettings',
        $field,
        $builder->callback(fn ($config) => $config->get($key) ?? $default)
      );
    }
  }

}
