<?php

declare(strict_types=1);

namespace Drupal\icms_bundle_event_logic\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\icms_bundle_event_logic\EventRegistrationManager;
use Drupal\icms_bundle_event_logic\EventRegistrations;
use Drupal\icms_bundle_event_logic\Form\EventRegistrationsFilterForm;
use Drupal\node\NodeInterface;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\WebformEntityReferenceManagerInterface;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The registrations for one date of an event.
 *
 * Webform's own results table lists every submission a form ever received. For
 * an event that reads badly: all dates are mixed together, and registering
 * again does not replace the earlier submission, so the same person appears
 * once per attempt. This shows one date at a time, one row per person.
 *
 * The columns come from whichever webform the event points at, so a cloned form
 * with extra questions gains columns without anything being configured here —
 * the property that made Webform's table worth keeping in the first place.
 *
 * The protected methods are the extension points: a project with its own idea
 * of what a second submission means (a cancellation, say) overrides
 * results() and skippedColumns() rather than rebuilding the screen.
 */
class EventRegistrationsController extends ControllerBase {

  /**
   * The shared registration rules.
   */
  protected EventRegistrations $registrations;

  /**
   * Resolves the webform an entity points at.
   */
  protected WebformEntityReferenceManagerInterface $entityReferenceManager;

  /**
   * The webform element plugin manager.
   */
  protected WebformElementManagerInterface $elementManager;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructor.
   *
   * @param \Drupal\icms_bundle_event_logic\EventRegistrations $registrations
   *   The shared registration rules.
   * @param \Drupal\webform\WebformEntityReferenceManagerInterface $entityReferenceManager
   *   Resolves the webform an entity points at.
   * @param \Drupal\webform\Plugin\WebformElementManagerInterface $elementManager
   *   The webform element plugin manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   */
  public function __construct(
    EventRegistrations $registrations,
    WebformEntityReferenceManagerInterface $entityReferenceManager,
    WebformElementManagerInterface $elementManager,
    RequestStack $requestStack,
    DateFormatterInterface $dateFormatter,
  ) {
    $this->registrations = $registrations;
    $this->entityReferenceManager = $entityReferenceManager;
    $this->elementManager = $elementManager;
    $this->requestStack = $requestStack;
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('icms_bundle_event_logic.event_registrations'),
      $container->get('webform.entity_reference_manager'),
      $container->get('plugin.manager.webform.element'),
      $container->get('request_stack'),
      $container->get('date.formatter'),
    );
  }

  /**
   * The results page.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node whose registrations to show.
   *
   * @return array
   *   A render array.
   */
  public function results(NodeInterface $node): array {
    // This screen is about event dates. Anything else carrying a webform keeps
    // Webform's own results table rather than being shown a date filter that
    // means nothing to it.
    if (!$node->hasField('field_icms_event_occurrence')) {
      return $this->entityTypeManager()
        ->getListBuilder('webform_submission')
        ->render();
    }

    $webform = $this->entityReferenceManager->getWebform($node);
    if ($webform === NULL) {
      return ['#markup' => $this->t('This event has no registration form.')];
    }

    $occurrence_id = $this->currentOccurrenceId($node);
    $search = $this->currentSearch();
    $submissions = $this->currentSubmissions($node, $webform, $occurrence_id, $search);

    $build = [
      '#cache' => [
        'tags' => ['webform_submission_list'],
        // The table is per date and per search, and the default date moves
        // with time.
        'contexts' => ['url.query_args:occurrence', 'url.query_args:search'],
      ],
    ];

    $build['filter'] = $this->formBuilder()->getForm(
      EventRegistrationsFilterForm::class,
      $node,
      $occurrence_id,
      $search
    );

    $build['table'] = $this->buildTable(
      $webform,
      $submissions,
      $this->t('Registrations (@count)', ['@count' => count($submissions)])
    );

    // Offering a download of an empty table would just hand over a file with
    // nothing but headers in it.
    if ($submissions) {
      $build['download'] = [
        '#type' => 'link',
        '#title' => $this->t('Download CSV'),
        '#url' => Url::fromRoute(
          'icms_bundle_event_logic.event_registrations_csv',
          ['node' => $node->id()],
          ['query' => $this->currentQuery($occurrence_id, $search)]
        ),
        '#attributes' => ['class' => ['button']],
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ];
    }

    return $build;
  }

  /**
   * Streams the current view as CSV.
   *
   * Exports exactly what the page shows — same date, same search, same rows in
   * the same order — because it reads the filters from the same query string.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   The CSV download.
   */
  public function csv(NodeInterface $node): StreamedResponse {
    $webform = $this->entityReferenceManager->getWebform($node);
    $occurrence_id = $this->currentOccurrenceId($node);
    $search = $this->currentSearch();

    $submissions = $webform === NULL
      ? []
      : $this->currentSubmissions($node, $webform, $occurrence_id, $search);
    $columns = $webform === NULL ? [] : $this->columns($webform);

    $response = new StreamedResponse(function () use ($columns, $submissions, $webform): void {
      $handle = fopen('php://output', 'w');

      // Excel opens a UTF-8 CSV as the local codepage without this.
      fwrite($handle, "\xEF\xBB\xBF");

      fputcsv($handle, array_map(
        static fn(array $column): string => (string) $column['title'],
        $columns
      ));

      foreach ($submissions as $submission) {
        fputcsv($handle, $this->rowValues($webform, $submission, $columns, FALSE));
      }

      fclose($handle);
    });

    $filename = sprintf('registrations-%s-%s.csv', $node->id(), $occurrence_id ?: 'all');
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

    return $response;
  }

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
   * The submissions the current request asks for.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param \Drupal\webform\WebformInterface $webform
   *   The event's webform.
   * @param string|null $occurrenceId
   *   The occurrence to show.
   * @param string $search
   *   The search text.
   *
   * @return \Drupal\webform\WebformSubmissionInterface[]
   *   The submissions to show.
   */
  protected function currentSubmissions(NodeInterface $node, WebformInterface $webform, ?string $occurrenceId, string $search): array {
    $submissions = $this->registrations->latestPerPerson($node, $occurrenceId);

    return $search === ''
      ? $submissions
      : $this->filterBySearch($webform, $submissions, $search);
  }

  /**
   * Narrows submissions to those with the search text in a visible answer.
   *
   * Matching the formatted values is what makes the search agree with the
   * table: an editor searching for an option's label finds the row that shows
   * that label, not the row whose stored value happens to contain the text.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The event's webform.
   * @param \Drupal\webform\WebformSubmissionInterface[] $submissions
   *   The submissions to narrow.
   * @param string $search
   *   The search text.
   *
   * @return \Drupal\webform\WebformSubmissionInterface[]
   *   The matching submissions.
   */
  protected function filterBySearch(WebformInterface $webform, array $submissions, string $search): array {
    $columns = $this->columns($webform);
    $needle = mb_strtolower($search);

    return array_values(array_filter($submissions, function (WebformSubmissionInterface $submission) use ($webform, $columns, $needle): bool {
      foreach ($this->rowValues($webform, $submission, $columns, FALSE) as $value) {
        if (is_scalar($value) && str_contains(mb_strtolower((string) $value), $needle)) {
          return TRUE;
        }
      }

      return FALSE;
    }));
  }

  /**
   * The occurrence the request asks for, or the sensible default.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   *
   * @return string|null
   *   The occurrence ID, EventRegistrations::OCCURRENCE_NONE, or NULL.
   */
  protected function currentOccurrenceId(NodeInterface $node): ?string {
    $requested = $this->requestStack->getCurrentRequest()?->query->get('occurrence');

    if (is_string($requested) && $requested !== '') {
      return $requested;
    }

    return $this->registrations->defaultOccurrenceId($node);
  }

  /**
   * The search text the request asks for.
   *
   * @return string
   *   The search text, or an empty string.
   */
  protected function currentSearch(): string {
    $requested = $this->requestStack->getCurrentRequest()?->query->get('search');

    return is_string($requested) ? trim($requested) : '';
  }

  /**
   * The query string that reproduces the current view.
   *
   * @param string|null $occurrenceId
   *   The occurrence being shown.
   * @param string $search
   *   The search text.
   *
   * @return array
   *   Query parameters.
   */
  protected function currentQuery(?string $occurrenceId, string $search): array {
    $query = [];

    if ($occurrenceId !== NULL) {
      $query['occurrence'] = $occurrenceId;
    }
    if ($search !== '') {
      $query['search'] = $search;
    }

    return $query;
  }

  /**
   * Builds one table of submissions.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The event's webform.
   * @param \Drupal\webform\WebformSubmissionInterface[] $submissions
   *   The submissions to show.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $caption
   *   An optional caption.
   *
   * @return array
   *   A render array.
   */
  protected function buildTable(WebformInterface $webform, array $submissions, $caption): array {
    $columns = $this->columns($webform);

    $header = array_map(static fn(array $column) => $column['title'], $columns);
    $header[] = ['data' => $this->t('Operations')];

    $rows = [];
    foreach ($submissions as $submission) {
      $row = $this->rowValues($webform, $submission, $columns, TRUE);
      $row[] = [
        'data' => [
          '#type' => 'operations',
          '#links' => [
            'view' => [
              'title' => $this->t('View'),
              'url' => $submission->toUrl('canonical'),
            ],
            'delete' => [
              'title' => $this->t('Delete'),
              'url' => $submission->toUrl('delete-form'),
            ],
          ],
        ],
      ];
      $rows[] = $row;
    }

    return [
      '#type' => 'table',
      '#caption' => $caption,
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No registrations for this date.'),
    ];
  }

  /**
   * The columns for a webform.
   *
   * Taken from the form's own elements, so a cloned form with extra questions
   * gains columns without anything being configured here.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   *
   * @return array
   *   Each entry has key and title.
   */
  protected function columns(WebformInterface $webform): array {
    $skip = $this->skippedColumns();
    $columns = [];

    foreach ($webform->getElementsInitializedFlattenedAndHasValue('view') as $key => $element) {
      if (in_array($key, $skip, TRUE)) {
        continue;
      }

      $columns[] = [
        'key' => $key,
        'title' => $element['#title'] ?? $key,
      ];
    }

    $columns[] = ['key' => '__created', 'title' => $this->t('Submitted')];

    return $columns;
  }

  /**
   * Element keys that carry routing data rather than an answer.
   *
   * A subclass adds its own — an attendance element it groups by, say.
   *
   * @return string[]
   *   Element keys to leave out of the table.
   */
  protected function skippedColumns(): array {
    // The same keys the results table hides, kept in one place.
    return EventRegistrationManager::ROUTING_ELEMENTS;
  }

  /**
   * Renders one submission's values.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The webform.
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission.
   * @param array $columns
   *   The columns.
   * @param bool $html
   *   TRUE for the table, FALSE for CSV.
   *
   * @return array
   *   The cell values.
   */
  protected function rowValues(WebformInterface $webform, WebformSubmissionInterface $submission, array $columns, bool $html): array {
    $values = [];

    foreach ($columns as $column) {
      if ($column['key'] === '__created') {
        $values[] = $this->dateFormatter->format($submission->getCreatedTime(), 'short');
        continue;
      }

      $element = $webform->getElement($column['key']);
      if ($element === NULL) {
        $values[] = '';
        continue;
      }

      // The element plugin turns a stored key into what the editor expects to
      // read — an option's label rather than its machine value, for instance.
      $plugin = $this->elementManager->getElementInstance($element);
      $formatted = $html
        ? $plugin->formatHtml($element, $submission)
        : $plugin->formatText($element, $submission);

      $values[] = is_array($formatted) ? ['data' => $formatted] : $formatted;
    }

    return $values;
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
