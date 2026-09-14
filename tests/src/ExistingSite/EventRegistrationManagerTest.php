<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_bundle_event_logic\ExistingSite;

use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformInterface;
use weitzman\DrupalTestTraits\ExistingSiteBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that event registrations stay readable through Webform's own results.
 *
 * Registrations used to be listed by a view with one Views field per element of
 * the shipped registration form, which froze the listing to that single form.
 * Editors now duplicate the form to collect different data, so the listing has
 * to follow whatever elements the form actually has, and submissions have to be
 * attached to the event they were made from for Webform to find them.
 */
#[Group('icms_bundle_event')]
class EventRegistrationManagerTest extends ExistingSiteBase {

  /**
   * The registration manager under test.
   *
   * @var \Drupal\icms_bundle_event_logic\EventRegistrationManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!\Drupal::moduleHandler()->moduleExists('icms_bundle_event_logic')) {
      $this->markTestSkipped('The event bundle is not installed on this site.');
    }

    $this->manager = \Drupal::service('icms_bundle_event_logic.registration_manager');
  }

  /**
   * A submission carrying only node_id is attached to that event.
   *
   * Submissions arriving through the GraphQL mutation without a source entity
   * are stored against the GraphQL server, so Webform reports no registrations
   * for the event at all.
   */
  public function testSubmissionIsAttachedToItsEvent(): void {
    $event = $this->createNode(['type' => 'icms_event', 'title' => 'Attachment test event']);

    $submission = WebformSubmission::create([
      'webform_id' => 'icms_event_registration',
      'data' => [
        'name' => 'Test Person',
        'email' => 'test@example.com',
        'node_id' => (string) $event->id(),
      ],
    ]);
    $submission->save();
    $this->markEntityForCleanup($submission);

    $source = $submission->getSourceEntity();
    $this->assertNotNull($source, 'The submission has a source entity.');
    $this->assertSame('node', $source->getEntityTypeId());
    $this->assertSame($event->id(), $source->id());

    // This is the count Webform's per-event results page is built on.
    /** @var \Drupal\webform\WebformSubmissionStorageInterface $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
    $webform = Webform::load('icms_event_registration');
    $this->assertSame(1, $storage->getTotal($webform, $event));
  }

  /**
   * A submission that already names a source entity is left alone.
   */
  public function testExistingSourceEntityIsNotOverwritten(): void {
    $event = $this->createNode(['type' => 'icms_event', 'title' => 'Kept source event']);
    $other = $this->createNode(['type' => 'icms_event', 'title' => 'Ignored node_id event']);

    $submission = WebformSubmission::create([
      'webform_id' => 'icms_event_registration',
      'entity_type' => 'node',
      'entity_id' => (string) $event->id(),
      'data' => ['node_id' => (string) $other->id()],
    ]);
    $submission->save();
    $this->markEntityForCleanup($submission);

    $this->assertSame($event->id(), $submission->getSourceEntity()->id());
  }

  /**
   * A submission naming a node that does not exist is not attached.
   */
  public function testUnknownNodeIsNotAttached(): void {
    $submission = WebformSubmission::create([
      'webform_id' => 'icms_event_registration',
      'data' => ['node_id' => '999999999'],
    ]);
    $submission->save();
    $this->markEntityForCleanup($submission);

    $this->assertNotSame('node', $submission->get('entity_type')->value);
  }

  /**
   * A duplicated form gets a results table covering its own elements.
   *
   * This is the case the retired view could not serve: an editor duplicates the
   * registration form, adds a field, and expects to read the answers.
   */
  public function testDuplicatedFormGetsItsOwnColumns(): void {
    $duplicate = $this->createRegistrationFormDuplicate('icms_test_dup_registration');

    $columns = $duplicate->getState('results.custom.columns');

    $this->assertContains('element__dietary', $columns, 'The element added after duplication has a column.');
    $this->assertContains('element__name', $columns, 'The inherited elements still have columns.');
    $this->assertTrue($duplicate->getState('results.custom.default'), 'The columns apply to the per-event listing.');

    // The routing elements carry the event and occurrence IDs and are never
    // read by an editor.
    $this->assertNotContains('element__node_id', $columns);
    $this->assertNotContains('element__occurrence_id', $columns);

    // Submission triage and request metadata are noise on a registration list.
    foreach (['sticky', 'locked', 'notes', 'langcode', 'remote_addr'] as $hidden) {
      $this->assertNotContains($hidden, $columns);
    }

    // Webform only offers "Submitted to" to a user who may view any submission,
    // so it is stored unconditionally to keep the list independent of whoever
    // saved the form. Webform drops it again on a per-event listing.
    $this->assertContains('entity', $columns);

    // Webform's per-event results pages check the form's own access rules, not
    // the "view event registrations" permission.
    $roles = $duplicate->getAccessRules()['view_any']['roles'];
    $this->assertContains('content_editor', $roles);
    $this->assertContains('administrator_client', $roles);
  }

  /**
   * An element added to an existing form appears in the results table.
   */
  public function testElementAddedLaterGetsColumn(): void {
    $webform = $this->createRegistrationFormDuplicate('icms_test_grow_registration');

    $webform->set('elements', $webform->get('elements') . "\ncompany:\n  '#type': textfield\n  '#title': Company\n");
    $webform->save();

    $columns = Webform::load('icms_test_grow_registration')->getState('results.custom.columns');
    $this->assertContains('element__company', $columns);
  }

  /**
   * A table an editor customised by hand is never rewritten.
   */
  public function testEditorCustomisationIsKept(): void {
    $webform = $this->createRegistrationFormDuplicate('icms_test_custom_registration');

    // What Webform's "Customize table" form writes.
    $webform->setState('results.custom.columns', ['serial', 'element__name']);

    // Any later save must leave that list alone, even though the form grew.
    $webform->set('elements', $webform->get('elements') . "\nphone:\n  '#type': tel\n  '#title': Phone\n");
    $webform->save();

    $columns = Webform::load('icms_test_custom_registration')->getState('results.custom.columns');
    $this->assertSame(['serial', 'element__name'], $columns);
  }

  /**
   * A form that is not a registration form is left untouched.
   *
   * Client projects build their own webforms; only forms carrying the event
   * routing elements are event registration forms.
   */
  public function testPlainFormIsUntouched(): void {
    $webform = Webform::create([
      'id' => 'icms_test_plain_form',
      'title' => 'Plain test form',
      'elements' => "name:\n  '#type': textfield\n  '#title': Name\n",
    ]);
    $webform->save();
    $this->markEntityForCleanup($webform);

    $webform = Webform::load('icms_test_plain_form');

    $this->assertFalse($this->manager->isRegistrationForm($webform));
    $this->assertNull($webform->getState('results.custom.columns'));
    $this->assertSame([], $webform->getAccessRules()['view_any']['roles']);
  }

  /**
   * Duplicates the shipped registration form and adds an element to it.
   *
   * @param string $id
   *   The ID for the duplicate.
   *
   * @return \Drupal\webform\WebformInterface
   *   The saved duplicate, loaded fresh.
   */
  protected function createRegistrationFormDuplicate(string $id): WebformInterface {
    $original = Webform::load('icms_event_registration');
    $this->assertNotNull($original, 'The bundle ships the registration form.');

    $duplicate = $original->createDuplicate();
    $duplicate->set('id', $id);
    $duplicate->set('title', 'Duplicate ' . $id);
    $duplicate->set(
      'elements',
      $duplicate->get('elements') . "\ndietary:\n  '#type': textfield\n  '#title': 'Dietary requirements'\n"
    );
    $duplicate->save();
    $this->markEntityForCleanup($duplicate);

    return Webform::load($id);
  }

}
