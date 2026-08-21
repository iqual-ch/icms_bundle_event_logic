<?php

declare(strict_types=1);

namespace Drupal\icms_bundle_event_logic\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Puts the event registrations screen on Webform's own results route.
 *
 * Webform's submissions table lists every submission of a form with no notion
 * of which date it is for, and no notion that registering twice is one person.
 * The event screen replaces it on that same route, so the "Results" tab, its
 * sub-tabs and its access requirements all keep working, and anything without
 * event occurrences still falls through to Webform's table inside the
 * controller.
 *
 * @see \Drupal\icms_bundle_event_logic\Controller\EventRegistrationsController::results()
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $route = $collection->get('entity.node.webform.results_submissions');
    if ($route === NULL) {
      return;
    }

    $route->setDefault(
      '_controller',
      '\Drupal\icms_bundle_event_logic\Controller\EventRegistrationsController::results'
    );

    // _entity_list would otherwise take precedence over the controller.
    $route->setDefault('_entity_list', NULL);
  }

}
