<?php

declare(strict_types=1);

namespace Drupal\icms_bundle_event_logic;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * The rules that turn raw registrations into the list people actually read.
 *
 * Webform lists every submission a form ever received. For an event that reads
 * badly: all dates are mixed together, and registering again does not replace
 * the earlier submission, so the same person appears once per attempt. An
 * editor checking who is coming on one date has to do the filtering and the
 * de-duplicating by eye.
 *
 * This service holds those rules so every screen applies the same ones. It is
 * deliberately free of any opinion about *why* somebody registered twice — a
 * project that treats a second submission as a cancellation extends this and
 * adds that on top.
 */
class EventRegistrations {

  /**
   * Stands for submissions whose occurrence no longer exists.
   */
  public const OCCURRENCE_NONE = 'none';

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The date formatter.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    DateFormatterInterface $dateFormatter,
    TimeInterface $time,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->dateFormatter = $dateFormatter;
    $this->time = $time;
  }

  /**
   * Lists an event's occurrences, soonest first.
   *
   * The occurrence paragraphs are not stored in date order, so they are sorted
   * here rather than taken as they come.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return array
   *   Each entry has id, label, start and upcoming keys.
   */
  public function occurrences(NodeInterface $event): array {
    if (!$event->hasField('field_icms_event_occurrence')) {
      return [];
    }

    $now = $this->time->getRequestTime();
    $occurrences = [];

    foreach ($event->get('field_icms_event_occurrence')->referencedEntities() as $paragraph) {
      if (!$paragraph->hasField('field_icms_date') || $paragraph->get('field_icms_date')->isEmpty()) {
        continue;
      }

      $start = (int) ($paragraph->get('field_icms_date')->first()->getValue()['value'] ?? 0);

      $occurrences[] = [
        'id' => (string) $paragraph->id(),
        'label' => $this->dateFormatter->format($start, 'custom', 'j. M Y - H:i'),
        'start' => $start,
        'upcoming' => $start >= $now,
      ];
    }

    usort($occurrences, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

    return $occurrences;
  }

  /**
   * The occurrence a screen should show before anyone picks one.
   *
   * The next upcoming date is what an editor checking registrations wants. Once
   * an event is over there is no upcoming date left, so the most recent past one
   * is the next best thing.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return string|null
   *   The occurrence ID, or NULL when the event has none.
   */
  public function defaultOccurrenceId(NodeInterface $event): ?string {
    $occurrences = $this->occurrences($event);
    if (!$occurrences) {
      return NULL;
    }

    foreach ($occurrences as $occurrence) {
      if ($occurrence['upcoming']) {
        return $occurrence['id'];
      }
    }

    return end($occurrences)['id'];
  }

  /**
   * Whether the event has registrations for a date it no longer has.
   *
   * Deleting an occurrence leaves its registrations behind. They are surfaced
   * rather than dropped, so somebody can see and remove them.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return bool
   *   TRUE if the event has orphaned registrations.
   */
  public function hasOrphans(NodeInterface $event): bool {
    $known = array_column($this->occurrences($event), 'id');

    foreach ($this->submissions($event) as $submission) {
      $occurrence_id = (string) ($submission->getElementData('occurrence_id') ?? '');
      if ($occurrence_id === '' || !in_array($occurrence_id, $known, TRUE)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The newest submission per person for one occurrence.
   *
   * Identity is the account where there is one. A submission without one —
   * possible on a cloned form that allows anonymous registration — falls back to
   * the email address, so the same person is still counted once. Failing both,
   * the submission stands alone rather than being merged with unrelated ones.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   * @param string|null $occurrenceId
   *   An occurrence ID, self::OCCURRENCE_NONE for orphans, or NULL for all.
   *
   * @return \Drupal\webform\WebformSubmissionInterface[]
   *   The surviving submissions, oldest registration first.
   */
  public function latestPerPerson(NodeInterface $event, ?string $occurrenceId = NULL): array {
    $known = array_column($this->occurrences($event), 'id');
    $latest = [];

    foreach ($this->submissions($event) as $submission) {
      $submission_occurrence = (string) ($submission->getElementData('occurrence_id') ?? '');
      $is_orphan = $submission_occurrence === '' || !in_array($submission_occurrence, $known, TRUE);

      if ($occurrenceId === self::OCCURRENCE_NONE) {
        if (!$is_orphan) {
          continue;
        }
      }
      elseif ($occurrenceId !== NULL && $submission_occurrence !== $occurrenceId) {
        continue;
      }

      // Two submissions are only the same person within one occurrence:
      // registering for June and for July are separate registrations.
      $key = $submission_occurrence . '|' . $this->personKey($submission);

      $previous = $latest[$key] ?? NULL;
      if ($previous === NULL || $this->isNewer($submission, $previous)) {
        $latest[$key] = $submission;
      }
    }

    return array_values($latest);
  }

  /**
   * All registrations attached to an event.
   *
   * Submissions are attached to the node as their source entity, which is what
   * makes this a single indexed query regardless of which webform the event
   * uses — a cloned form is included without being named anywhere.
   *
   * Access is deliberately not checked here. Per-submission access would hide
   * other people's registrations from exactly the users meant to read the list.
   * Callers are responsible for authorising; the results screen sits behind its
   * route's access check.
   *
   * @param \Drupal\node\NodeInterface $event
   *   The event node.
   *
   * @return \Drupal\webform\WebformSubmissionInterface[]
   *   The submissions, oldest first.
   */
  protected function submissions(NodeInterface $event): array {
    $storage = $this->entityTypeManager->getStorage('webform_submission');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $event->id())
      ->sort('sid')
      ->execute();

    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Identifies the person behind a submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission.
   *
   * @return string
   *   A key that is stable for one person.
   */
  protected function personKey(WebformSubmissionInterface $submission): string {
    $owner = $submission->getOwner();
    if ($owner !== NULL && (int) $owner->id() > 0) {
      return 'user:' . $owner->id();
    }

    $mail = trim((string) ($submission->getElementData('email') ?? ''));
    if ($mail !== '') {
      return 'mail:' . mb_strtolower($mail);
    }

    return 'submission:' . $submission->id();
  }

  /**
   * Determines whether a submission supersedes another.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission to test.
   * @param \Drupal\webform\WebformSubmissionInterface $other
   *   The submission to compare against.
   *
   * @return bool
   *   TRUE if $submission is the more recent of the two.
   */
  protected function isNewer(WebformSubmissionInterface $submission, WebformSubmissionInterface $other): bool {
    $created = (int) $submission->getCreatedTime();
    $other_created = (int) $other->getCreatedTime();

    // Two submissions saved within the same second are indistinguishable by
    // time, so the serial ID decides — it only ever grows.
    if ($created === $other_created) {
      return (int) $submission->id() > (int) $other->id();
    }

    return $created > $other_created;
  }

}
