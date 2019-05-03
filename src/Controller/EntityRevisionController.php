<?php

namespace Drupal\diff\Controller;

use Drupal\Core\Routing\RouteMatch;

/**
 * Returns responses for entity revision overview.
 */
class EntityRevisionController extends PluginRevisionController {

  /**
   * Returns a form for revision overview page.
   */
  public function revisionOverview(RouteMatch $route_match) {
    $entity_type_id = $route_match->getRouteObject()->getDefault('_entity_revisions_overview');
    $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($route_match->getParameter($entity_type_id));

    return $this->formBuilder()->getForm('Drupal\diff\Form\EntityRevisionOverviewForm', $entity);
  }

}
