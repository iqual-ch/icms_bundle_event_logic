<?php

declare(strict_types=1);

namespace Drupal\icms_bundle_event_logic;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps event registrations readable through Webform's own results pages.
 *
 * Registrations used to be listed by a hand-built view with one Views field per
 * element of the shipped registration form. That froze the listing to a single
 * form: an editor who duplicated it to collect different data got a form with
 * no columns, and the view's bundle filter hid it entirely.
 *
 * Webform builds its results table from the form's elements at request time, so
 * it follows a duplicated or edited form on its own. Two things stand in the
 * way, and this service supplies both: submissions coming from the decoupled
 * frontend have to be attached to their event, and the raw table needs the
 * routing elements and triage columns taken out of it.
 */
class EventRegistrationManager {

  /**
   * Columns Webform offers by default that editors do not need.
   *
   * Starred, Locked and Notes are submission triage; language and IP address
   * are noise on a registration list.
   */
  const HIDDEN_COLUMNS = [
    'sticky',
    'locked',
    'notes',
    'langcode',
    'remote_addr',
  ];

  /**
   * Elements that exist only to carry routing data, never to be read.
   *
   * A form is recognised as a registration form by carrying all of them, which
   * is what makes a duplicate inherit this treatment while a client's contact
   * form is left alone.
   */
  const ROUTING_ELEMENTS = [
    'node_id',
    'occurrence_id',
  ];

  /**
   * Roles that may read registrations.
   */
  const RESULTS_ROLES = [
    'content_editor',
    'administrator_client',
  ];

  /**
   * State key holding the column list this service last wrote.
   */
  const MANAGED_COLUMNS_STATE = 'icms_bundle_event_logic.managed_columns';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether a webform collects event registrations.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   *
   * @return bool
   *   TRUE if the form carries every routing element a registration needs.
   */
  public function isRegistrationForm(WebformInterface $webform): bool {
    $elements = $webform->getElementsDecodedAndFlattened();

    foreach (self::ROUTING_ELEMENTS as $key) {
      if (!isset($elements[$key])) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Points a submission at the event node named by its node_id element.
   *
   * Webform records the entity a form was submitted from, and its whole source
   * entity feature set keys off it: the per-node results and download pages,
   * source entity filters, getTotal(). A submission arriving through the
   * GraphQL mutation without sourceEntityType/sourceEntityId is stored against
   * the GraphQL server instead of the event, so Drupal reports no registrations
   * at all. The event ID is already submitted as a hidden node_id element, so
   * it can be promoted to the real source entity here.
   *
   * This is a safety net for submissions from older frontends and from other
   * integrations; the frontend passes the source entity itself.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The webform submission.
   *
   * @return bool
   *   TRUE if the source entity was changed.
   */
  public function attachSourceEntity(WebformSubmissionInterface $submission): bool {
    // A submission that already points at a node is either correct or was set
    // deliberately, so it is left alone.
    if ($submission->get('entity_type')->value === 'node') {
      return FALSE;
    }

    $node_id = $submission->getElementData('node_id');
    if (!is_scalar($node_id) || !is_numeric($node_id)) {
      return FALSE;
    }

    // Guard against a stale or hand-edited value: pointing the submission at a
    // node that no longer exists would break every source entity query.
    $node = $this->entityTypeManager->getStorage('node')->load((int) $node_id);
    if (!$node instanceof NodeInterface) {
      return FALSE;
    }

    $submission->set('entity_type', 'node');
    $submission->set('entity_id', (string) $node->id());

    return TRUE;
  }

  /**
   * Keeps a registration form's results table in sync with its elements.
   *
   * Webform's "Customize table" stores an explicit column list, which freezes
   * the table: an element added later gets no column, which is the whole reason
   * the custom view was retired. Recomputing the list whenever the form is
   * saved keeps the noise hidden while new elements still appear on their own.
   *
   * An editor who customised the table by hand keeps their layout: the column
   * list this service last wrote is remembered, and the moment the stored list
   * differs from it the table is left to the editor from then on.
   *
   * The settings live in webform state rather than config, and a duplicated
   * webform inherits none of it, so a cloned registration form would otherwise
   * start from Webform's raw defaults.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   *
   * @return bool
   *   TRUE if the results table was written.
   */
  public function syncResultsTable(WebformInterface $webform): bool {
    if (!$this->isRegistrationForm($webform)) {
      return FALSE;
    }

    $stored = $webform->getState('results.custom.columns');
    $managed = $webform->getState(self::MANAGED_COLUMNS_STATE);

    // The editor took the table over; stop managing it.
    if (!empty($stored) && $stored !== $managed) {
      return FALSE;
    }

    $columns = $this->resultsColumns($webform);
    if (!$columns) {
      return FALSE;
    }

    $webform->setState('results.custom.columns', $columns);
    $webform->setState('results.custom.sort', 'created');
    $webform->setState('results.custom.direction', 'desc');
    $webform->setState('results.custom.limit', 50);
    // Without this the per-form settings are ignored on a per-event listing,
    // which is the only place registrations are ever read.
    $webform->setState('results.custom.default', TRUE);
    $webform->setState(self::MANAGED_COLUMNS_STATE, $columns);

    return TRUE;
  }

  /**
   * Lets the event editing roles read a registration form's results.
   *
   * Webform's per-node results and download pages check the webform's own
   * access rules, not the "view event registrations" permission, and the event
   * editing roles hold none of the global webform permissions. The grant is per
   * webform rather than the site-wide "view any webform submission" permission,
   * so it exposes nothing beyond event registrations. Duplicating a webform
   * copies its access rules, so cloned forms inherit this.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   *
   * @return bool
   *   TRUE if the access rules were changed.
   */
  public function grantResultsAccess(WebformInterface $webform): bool {
    if (!$this->isRegistrationForm($webform)) {
      return FALSE;
    }

    $rules = $webform->getAccessRules();
    $existing = $rules['view_any']['roles'] ?? [];
    $merged = array_values(array_unique(array_merge($existing, self::RESULTS_ROLES)));

    if ($merged === $existing) {
      return FALSE;
    }

    $rules['view_any']['roles'] = $merged;
    $webform->setAccessRules($rules);

    return TRUE;
  }

  /**
   * Builds the column list for a registration form's results table.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   *
   * @return string[]
   *   Column names, in Webform's own order, minus the ones editors do not need.
   */
  protected function resultsColumns(WebformInterface $webform): array {
    /** @var \Drupal\webform\WebformSubmissionStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('webform_submission');

    $columns = array_keys($storage->getDefaultColumns($webform, NULL, NULL, TRUE));
    $hidden = array_merge(self::HIDDEN_COLUMNS, $this->routingElementColumns());

    return $this->withSourceEntityColumn(array_values(array_diff($columns, $hidden)));
  }

  /**
   * Adds the "Submitted to" column, whoever happens to be saving the form.
   *
   * Webform only offers that column to a user who may view any submission, so
   * the computed list would otherwise depend on who saved the form — an admin
   * editing it in the UI would get a different table than a deployment does.
   *
   * Storing it unconditionally is safe: Webform intersects a custom column list
   * with the columns available in the current context, so it is dropped again on
   * a per-event listing, where every row is the same event anyway. It survives
   * on the form's own results page, which is the one place the event a
   * registration belongs to is worth showing.
   *
   * @param string[] $columns
   *   The computed column names.
   *
   * @return string[]
   *   The column names including 'entity', in Webform's own ordering.
   */
  protected function withSourceEntityColumn(array $columns): array {
    if (in_array('entity', $columns, TRUE)) {
      return $columns;
    }

    // Webform lists the source entity directly before the submitting user.
    $position = array_search('uid', $columns, TRUE);
    if ($position === FALSE) {
      $columns[] = 'entity';
      return $columns;
    }

    array_splice($columns, (int) $position, 0, ['entity']);

    return $columns;
  }

  /**
   * The results column names of the routing elements.
   *
   * @return string[]
   *   Column names, as Webform names element columns.
   */
  protected function routingElementColumns(): array {
    return array_map(
      static fn (string $key): string => 'element__' . $key,
      self::ROUTING_ELEMENTS
    );
  }

}
