<?php

namespace Drupal\icms_bundle_event_logic\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\node\NodeInterface;
use Drupal\views\Views;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller that renders the event_registrations view for a given node.
 */
class EventRegistrationsController {

  /**
   * Renders the registrations view for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   A render array.
   */
  public function view(NodeInterface $node) {
    $view = Views::getView('event_registrations');
    if (!$view) {
      throw new NotFoundHttpException();
    }

    $view->setArguments([$node->id()]);
    return $view->buildRenderable('page_1');
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
  public function access(NodeInterface $node) {
    return AccessResult::allowedIf($node->bundle() === 'icms_event')
      ->addCacheableDependency($node);
  }

}
