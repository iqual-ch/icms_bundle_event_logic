<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_bundle_event_logic\ExistingSite;

use Drupal\texts\TextsInterface;
use PHPUnit\Framework\Attributes\Group;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * The event texts update keeps the texts editors changed (ICMS-848).
 */
#[Group('icms_bundle_event')]
class DefaultTextsUpdateTest extends ExistingSiteBase {

  /**
   * The stored value of the changed text, restored after the test.
   */
  protected ?string $originalValue = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!\Drupal::moduleHandler()->moduleExists('icms_bundle_event_logic')) {
      $this->markTestSkipped('The event bundle is not installed on this site.');
    }
    require_once dirname(__DIR__, 3) . '/icms_bundle_event_logic.install';
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->originalValue !== NULL) {
      $this->loadText()->setTranslationText($this->originalValue)->save();
    }
    parent::tearDown();
  }

  /**
   * A changed event text keeps its wording through the update hook.
   */
  public function testUpdateKeepsChangedText(): void {
    $text = $this->loadText();
    $this->originalValue = $text->getTranslationText();
    $text->setTranslationText('Changed by an editor')->save();

    icms_bundle_event_logic_update_9012();

    $this->assertSame('Changed by an editor', $this->loadText()->getTranslationText());
  }

  /**
   * Loads the add_to_calendar text in its original language.
   */
  protected function loadText(): TextsInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('texts');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('key', 'add_to_calendar')
      ->condition('context', 'event')
      ->execute();
    $this->assertCount(1, $ids, 'The add_to_calendar text exists.');
    $text = $storage->loadUnchanged(reset($ids));
    $this->assertInstanceOf(TextsInterface::class, $text);
    return $text;
  }

}
