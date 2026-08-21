<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_bundle_event_logic\ExistingSite;

use Drupal\icms_bundle_event_logic\EventRegistrations;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\webform\Entity\WebformSubmission;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests the rules behind the event registrations screen.
 *
 * An event has several dates and a person may register more than once, because
 * Webform keeps every submission. What an editor needs to read is one date at a
 * time with one row per person, and a CSV of exactly that.
 *
 * @group icms_bundle_event_logic
 */
class EventRegistrationsTest extends ExistingSiteBase {

  /**
   * The service under test.
   */
  protected EventRegistrations $registrations;

  /**
   * The event node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $event;

  /**
   * The occurrence IDs, soonest first.
   *
   * @var string[]
   */
  protected array $occurrenceIds = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->registrations = \Drupal::service('icms_bundle_event_logic.event_registrations');

    $now = \Drupal::time()->getRequestTime();

    // Created out of order on purpose: the service sorts by date, it does not
    // trust the field order.
    $paragraphs = [];
    foreach ([$now + 86400 * 30, $now + 86400 * 7] as $start) {
      $paragraph = Paragraph::create([
        'type' => 'icms_event_occurrence',
        'field_icms_date' => [['value' => $start, 'end_value' => $start + 3600]],
      ]);
      $paragraph->save();
      $this->markEntityForCleanup($paragraph);
      $paragraphs[] = $paragraph;
    }

    $this->event = $this->createNode([
      'type' => 'icms_event',
      'title' => 'Registrations test event',
      'field_icms_webform' => ['target_id' => 'icms_event_registration'],
      'field_icms_event_occurrence' => array_map(
        static fn(Paragraph $p): array => [
          'target_id' => $p->id(),
          'target_revision_id' => $p->getRevisionId(),
        ],
        $paragraphs
      ),
    ]);

    $this->occurrenceIds = array_column($this->registrations->occurrences($this->event), 'id');
  }

  /**
   * Occurrences come back in date order, soonest first.
   */
  public function testOccurrencesAreSortedByDate(): void {
    $occurrences = $this->registrations->occurrences($this->event);

    $this->assertCount(2, $occurrences);
    $this->assertLessThan(
      $occurrences[1]['start'],
      $occurrences[0]['start'],
      'The soonest date comes first, whatever order the paragraphs are in.'
    );
    $this->assertTrue($occurrences[0]['upcoming']);
  }

  /**
   * The screen opens on the next upcoming date.
   */
  public function testDefaultOccurrenceIsTheNextUpcomingDate(): void {
    $this->assertSame(
      $this->occurrenceIds[0],
      $this->registrations->defaultOccurrenceId($this->event)
    );
  }

  /**
   * Registering twice for one date leaves only the newer submission.
   */
  public function testOnlyTheNewestSubmissionPerPersonAndDateSurvives(): void {
    $this->submit('Anna Old', 'anna@example.com', $this->occurrenceIds[0]);
    $this->submit('Anna New', 'anna@example.com', $this->occurrenceIds[0]);
    $this->submit('Bruno Berg', 'bruno@example.com', $this->occurrenceIds[0]);

    $names = $this->names($this->registrations->latestPerPerson($this->event, $this->occurrenceIds[0]));

    $this->assertContains('Anna New', $names);
    $this->assertNotContains('Anna Old', $names, 'The superseded registration is gone.');
    $this->assertContains('Bruno Berg', $names);
    $this->assertCount(2, $names);
  }

  /**
   * Registering for two dates is two registrations, not a duplicate.
   */
  public function testTheSamePersonCountsOncePerDate(): void {
    $this->submit('Anna June', 'anna@example.com', $this->occurrenceIds[0]);
    $this->submit('Anna July', 'anna@example.com', $this->occurrenceIds[1]);

    $this->assertSame(['Anna June'], $this->names(
      $this->registrations->latestPerPerson($this->event, $this->occurrenceIds[0])
    ));
    $this->assertSame(['Anna July'], $this->names(
      $this->registrations->latestPerPerson($this->event, $this->occurrenceIds[1])
    ));

    // Both survive when no date is selected: they are different registrations.
    $this->assertCount(2, $this->registrations->latestPerPerson($this->event));
  }

  /**
   * A date filter excludes the other dates' registrations.
   */
  public function testRegistrationsAreFilteredByDate(): void {
    $this->submit('Bruno Berg', 'bruno@example.com', $this->occurrenceIds[0]);
    $this->submit('Carla Cast', 'carla@example.com', $this->occurrenceIds[1]);

    $this->assertSame(['Bruno Berg'], $this->names(
      $this->registrations->latestPerPerson($this->event, $this->occurrenceIds[0])
    ));
    $this->assertSame(['Carla Cast'], $this->names(
      $this->registrations->latestPerPerson($this->event, $this->occurrenceIds[1])
    ));
  }

  /**
   * A registration for a deleted date is reachable rather than lost.
   */
  public function testRegistrationsForDeletedDateAreReachable(): void {
    $this->assertFalse($this->registrations->hasOrphans($this->event));

    $this->submit('Dora Ghost', 'dora@example.com', '999999999');

    $this->assertTrue($this->registrations->hasOrphans($this->event));
    $this->assertSame(['Dora Ghost'], $this->names(
      $this->registrations->latestPerPerson($this->event, EventRegistrations::OCCURRENCE_NONE)
    ));

    // And it stays out of a real date's list.
    $this->assertSame([], $this->names(
      $this->registrations->latestPerPerson($this->event, $this->occurrenceIds[0])
    ));
  }

  /**
   * Registrations of a cloned form are included without being named anywhere.
   *
   * They are found through the submission's source entity, not through the
   * webform ID, which is what lets an editor duplicate the form.
   */
  public function testRegistrationsOfClonedFormAreIncluded(): void {
    $original = \Drupal::entityTypeManager()->getStorage('webform')->load('icms_event_registration');
    $clone = $original->createDuplicate();
    $clone->set('id', 'icms_test_reg_clone');
    $clone->set('title', 'Cloned registration');
    $clone->save();
    $this->markEntityForCleanup($clone);

    $this->submit('Erik Clone', 'erik@example.com', $this->occurrenceIds[0], 'icms_test_reg_clone');

    $this->assertSame(['Erik Clone'], $this->names(
      $this->registrations->latestPerPerson($this->event, $this->occurrenceIds[0])
    ));
  }

  /**
   * Creates a registration for this test's event.
   *
   * @param string $name
   *   The registrant's name.
   * @param string $mail
   *   The registrant's email.
   * @param string $occurrenceId
   *   The occurrence to register for.
   * @param string $webformId
   *   The webform to submit.
   */
  protected function submit(string $name, string $mail, string $occurrenceId, string $webformId = 'icms_event_registration'): void {
    $submission = WebformSubmission::create([
      'webform_id' => $webformId,
      'data' => [
        'name' => $name,
        'email' => $mail,
        'event_occurrence' => 'date ' . $occurrenceId,
        'occurrence_id' => $occurrenceId,
        'node_id' => (string) $this->event->id(),
      ],
    ]);
    $submission->save();
    $this->markEntityForCleanup($submission);
  }

  /**
   * The names on a list of submissions.
   *
   * @param \Drupal\webform\WebformSubmissionInterface[] $submissions
   *   The submissions.
   *
   * @return string[]
   *   The submitted names.
   */
  protected function names(array $submissions): array {
    return array_map(
      static fn($submission): string => (string) $submission->getElementData('name'),
      $submissions
    );
  }

}
