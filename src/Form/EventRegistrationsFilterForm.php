<?php

declare(strict_types=1);

namespace Drupal\icms_bundle_event_logic\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\icms_bundle_event_logic\EventRegistrations;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Picks which date of an event to show registrations for, and narrows by text.
 *
 * Both choices are kept in the query string rather than in the session, so a
 * particular view can be linked to, bookmarked, and reused by the CSV link —
 * which is what makes "download what I am looking at" possible.
 */
class EventRegistrationsFilterForm extends FormBase {

  /**
   * The shared registration rules.
   */
  protected EventRegistrations $registrations;

  /**
   * Constructor.
   *
   * @param \Drupal\icms_bundle_event_logic\EventRegistrations $registrations
   *   The shared registration rules.
   */
  public function __construct(EventRegistrations $registrations) {
    $this->registrations = $registrations;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('icms_bundle_event_logic.event_registrations'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'icms_event_registrations_filter';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\node\NodeInterface|null $node
   *   The event node.
   * @param string|null $current
   *   The selected occurrence.
   * @param string $search
   *   The current search text.
   *
   * @return array
   *   The form array.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, ?string $current = NULL, string $search = ''): array {
    if ($node === NULL) {
      return $form;
    }

    $options = [];
    foreach ($this->registrations->occurrences($node) as $occurrence) {
      $options[$occurrence['id']] = $occurrence['label'];
    }

    // Deleting a date leaves its registrations behind; this is the only way to
    // reach them, and so to delete them.
    if ($this->registrations->hasOrphans($node)) {
      $options[EventRegistrations::OCCURRENCE_NONE] = $this->t('Without a valid date');
    }

    $form['#attributes']['class'][] = 'icms-event-registrations-filter';
    $form['node'] = ['#type' => 'value', '#value' => $node->id()];

    if ($options) {
      $form['occurrence'] = [
        '#type' => 'select',
        '#title' => $this->t('Date'),
        '#options' => $options,
        '#default_value' => $current !== NULL && isset($options[$current]) ? $current : NULL,
      ];
    }

    $form['search'] = [
      '#type' => 'search',
      '#title' => $this->t('Search'),
      '#description' => $this->t('Matches any answer in the table.'),
      '#default_value' => $search,
      '#size' => 30,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Filter'),
      ],
    ];

    if ($current !== NULL || $search !== '') {
      $form['actions']['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Reset'),
        '#url' => Url::fromRoute('entity.node.webform.results_submissions', ['node' => $node->id()]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];

    $occurrence = $form_state->getValue('occurrence');
    if ($occurrence !== NULL && $occurrence !== '') {
      $query['occurrence'] = $occurrence;
    }

    $search = trim((string) $form_state->getValue('search'));
    if ($search !== '') {
      $query['search'] = $search;
    }

    $form_state->setRedirectUrl(Url::fromRoute(
      'entity.node.webform.results_submissions',
      ['node' => $form_state->getValue('node')],
      ['query' => $query]
    ));
  }

}
