<?php

declare(strict_types=1);

namespace Drupal\icms_bundle_event_logic\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Sends the retired registrations URLs to Webform's results pages.
 *
 * The registrations listing used to be a view rendered here. It has been
 * retired in favour of Webform's own per-node results, which builds its table
 * from the form's elements at request time and so follows a duplicated or
 * edited registration form. These paths stay as redirects.
 */
class EventRegistrationsController {

  /**
   * Redirects to the event's webform submissions table.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   A redirect to the submissions listing.
   */
  public function submissions(NodeInterface $node): LocalRedirectResponse {
    return $this->redirectTo('entity.node.webform.results_submissions', $node);
  }

  /**
   * Redirects to the event's webform download form.
   *
   * The view offered a one-click CSV here. Webform's equivalent is a form with
   * format and column options rather than an immediate download, so the old URL
   * lands on that form.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   A redirect to the download form.
   */
  public function export(NodeInterface $node): LocalRedirectResponse {
    return $this->redirectTo('entity.node.webform.results_export', $node);
  }

  /**
   * Access callback: only allow on event nodes.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(NodeInterface $node): AccessResultInterface {
    return AccessResult::allowedIf($node->bundle() === 'icms_event')
      ->addCacheableDependency($node);
  }

  /**
   * Builds a redirect to a webform node route for the given node.
   *
   * @param string $route_name
   *   The target route.
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   The redirect, varying by URL so it is not served from another node's
   *   cached response.
   */
  protected function redirectTo(string $route_name, NodeInterface $node): LocalRedirectResponse {
    $url = Url::fromRoute($route_name, ['node' => $node->id()]);

    $response = new LocalRedirectResponse($url->toString());
    $response->addCacheableDependency($node);

    return $response;
  }

}
