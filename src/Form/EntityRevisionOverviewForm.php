<?php

namespace Drupal\diff\Form;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\diff\DiffEntityComparison;
use Drupal\diff\DiffLayoutManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for revision overview page.
 */
class EntityRevisionOverviewForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The entity comparison.
   *
   * @var \Drupal\diff\DiffEntityComparison
   */
  protected $entityComparison;

  /**
   * The field diff layout plugin manager service.
   *
   * @var \Drupal\diff\DiffLayoutManager
   */
  protected $diffLayoutManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * EntityRevisionOverviewForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter.
   * @param \Drupal\diff\DiffEntityComparison $entity_comparison
   *   The entity comparison.
   * @param \Drupal\diff\DiffLayoutManager $diff_layout_manager
   *   The field diff layout plugin manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, DiffEntityComparison $entity_comparison, DiffLayoutManager $diff_layout_manager, RendererInterface $renderer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->entityComparison = $entity_comparison;
    $this->diffLayoutManager = $diff_layout_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('diff.entity_comparison'),
      $container->get('plugin.manager.diff.layout'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'entity_revision_overview_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $entity = NULL) {
    /** @var \Drupal\Core\Entity\ContentEntityBase $entity */
    $langcode = $entity->language()->getId();
    $langname = $entity->language()->getName();
    $languages = $entity->getTranslationLanguages();
    $has_translations = (count($languages) > 1);

    $entity_type = $entity->getEntityTypeId();
    $entity_storage = $this->entityTypeManager->getStorage($entity_type);

    $pager_limit = $this->getDiffConfig()->get('general_settings.revision_pager_limit');

    $query = $entity_storage->getQuery()
      ->condition($entity->getEntityType()->getKey('id'), $entity->id())
      ->pager($pager_limit)
      ->allRevisions()
      ->sort($entity->getEntityType()->getKey('revision'), 'DESC')
      ->accessCheck(FALSE)
      ->execute();
    $revision_ids = array_keys($query);

    $revision_count = count($revision_ids);
    $build['#title'] = $has_translations ? $this->t('@langname revisions for %title', [
      '@langname' => $langname,
      '%title' => $entity->label(),
    ]) : $this->t('Revisions for %title', [
      '%title' => $entity->label(),
    ]);
    $build['entity_id'] = [
      '#type' => 'hidden',
      '#value' => $entity->id(),
    ];
    $build['entity_type'] = [
      '#type' => 'hidden',
      '#value' => $entity_type,
    ];

    $table_header = [];
    $table_header['revision'] = $this->t('Revision');

    // Allow comparisons only if there are 2 or more revisions.
    if ($revision_count > 1) {
      $table_header += [
        'select_column_one' => '',
        'select_column_two' => '',
      ];
    }
    $table_header['operations'] = $this->t('Operations');

    // Contains the table listing the revisions.
    $build['entity_revisions_table'] = [
      '#type' => 'table',
      '#header' => $table_header,
      '#attributes' => ['class' => ['diff-revisions']],
    ];
    $build['entity_revisions_table']['#attached']['library'][] = 'diff/diff.general';
    $build['entity_revisions_table']['#attached']['drupalSettings']['diffRevisionRadios'] = $this
      ->getDiffConfig()->get('general_settings.radio_behavior');

    $default_revision = $entity->getRevisionId();
    // Add rows to the table.
    foreach ($revision_ids as $key => $revision_id) {
      $previous_revision = NULL;
      if (isset($revision_ids[$key + 1])) {
        $previous_revision = $entity_storage->loadRevision($revision_ids[$key + 1]);
      }
      /** @var \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\RevisionLogInterface $revision */
      if ($revision = $entity_storage->loadRevision($revision_id)) {
        if (!$revision->hasTranslation($langcode)) {
          continue;
        }

        if (!$revision->getTranslation($langcode)->isRevisionTranslationAffected()) {
          continue;
        }

        $username = [
          '#theme' => 'username',
          '#account' => $revision->getRevisionUser(),
        ];
        $revision_date = $this->dateFormatter->format($revision->getRevisionCreationTime(), 'short');

        // Use revision link to link to revisions that are not active.
        if ($revision_id != $entity->getRevisionId()) {
          $link = Link::createFromRoute($revision_date, 'entity.' . $entity_type . '.revision', [$entity_type => $entity->id(), "{$entity_type}_revision" => $revision_id]);
        }
        else {
          $link = $entity->toLink($revision_date);
        }

        if ($revision_id == $default_revision) {
          $row = [
            'revision' => $this->buildRevision($link, $username, $revision, $previous_revision),
          ];

          // Allow comparisons only if there are 2 or more revisions.
          if ($revision_count > 1) {
            $row += [
              'select_column_one' => $this->buildSelectColumn('radios_left', $revision_id, FALSE),
              'select_column_two' => $this->buildSelectColumn('radios_right', $revision_id, $revision_id),
            ];
          }

          $row['operations'] = [
            '#prefix' => '<em>',
            '#markup' => $this->t('Current revision'),
            '#suffix' => '</em>',
            '#attributes' => [
              'class' => ['revision-current'],
            ],
          ];
          $row['#attributes'] = [
            'class' => ['revision-current'],
          ];
        }
        else {
          $route_params = [
            $entity_type => $entity->id(),
            "{$entity_type}_revision" => $revision_id,
            'langcode' => $langcode,
          ];
          $links = [];
          $revert_url = $has_translations
            ? Url::fromRoute("entity.{$entity_type}.revision_revert_translation", $route_params)
            : Url::fromRoute("entity.{$entity_type}.revision_revert", [$entity_type => $entity->id(), "{$entity_type}_revision" => $revision_id]);
          if ($revert_url->access()) {
            $links['revert'] = [
              'title' => $revision_id < $entity->getRevisionId() ? $this->t('Revert') : $this->t('Set as current revision'),
              'url' => $revert_url,
            ];
          }
          $delete_url = Url::fromRoute("entity.{$entity_type}.revision_delete", $route_params);
          if ($delete_url->access()) {
            $links['delete'] = [
              'title' => $this->t('Delete'),
              'url' => $delete_url,
            ];
          }

          // Here we don't have to deal with 'only one revision' case because
          // if there's only one revision it will also be the default one,
          // entering on the first branch of this if else statement.
          $row = [
            'revision' => $this->buildRevision($link, $username, $revision, $previous_revision),
            'select_column_one' => $this->buildSelectColumn('radios_left', $revision_id,
              isset($revision_ids[1]) ? $revision_ids[1] : FALSE),
            'select_column_two' => $this->buildSelectColumn('radios_right', $revision_id, FALSE),
            'operations' => [
              '#type' => 'operations',
              '#links' => $links,
            ],
          ];
        }

        // Add the row to the table.
        $build['entity_revisions_table'][] = $row;
      }
    }

    // Allow comparisons only if there are 2 or more revisions.
    if ($revision_count > 1) {
      $build['submit'] = [
        '#type' => 'submit',
        '#button_type' => 'primary',
        '#value' => t('Compare selected revisions'),
        '#attributes' => [
          'class' => [
            'diff-button',
          ],
        ],
      ];
    }
    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $input = $form_state->getUserInput();

    if (count($form_state->getValue('entity_revisions_table')) <= 1) {
      $form_state->setErrorByName('entity_revisions_table', $this->t('Multiple revisions are needed for comparison.'));
    }
    elseif (!isset($input['radios_left']) || !isset($input['radios_right'])) {
      $form_state->setErrorByName('entity_revisions_table', $this->t('Select two revisions to compare.'));
    }
    elseif ($input['radios_left'] == $input['radios_right']) {
      // @todo Radio-boxes selection resets if there are errors.
      $form_state->setErrorByName('entity_revisions_table', $this->t('Select different revisions to compare.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $input = $form_state->getUserInput();
    $revision_id_left = $input['radios_left'];
    $revision_id_right = $input['radios_right'];
    $entity_id = $input['entity_id'];
    $entity_type = $input['entity_type'];

    // Always place the older revision on the left side of the comparison
    // and the newer revision on the right side (however revisions can be
    // compared both ways if we manually change the order of the parameters).
    if ($revision_id_left > $revision_id_right) {
      $aux = $revision_id_left;
      $revision_id_left = $revision_id_right;
      $revision_id_right = $aux;
    }
    // Builds the redirect Url.
    $redirect_url = Url::fromRoute(
      'entity.' . $entity_type . '.revisions_diff',
      [
        $entity_type => $entity_id,
        'left_revision' => $revision_id_left,
        'right_revision' => $revision_id_right,
        'filter' => $this->diffLayoutManager->getDefaultLayout(),
      ]
    );
    $form_state->setRedirectUrl($redirect_url);
  }

  /**
   * Returns diff module configs object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   Immutable config object.
   */
  protected function getDiffConfig() {
    return $this->config('diff.settings');
  }

  /**
   * Set and return configuration for revision.
   *
   * @param \Drupal\Core\Link $link
   *   Link attribute.
   * @param mixed $username
   *   Username attribute.
   * @param \Drupal\Core\Entity\ContentEntityInterface $revision
   *   Revision parameter for getRevisionDescription function.
   * @param \Drupal\Core\Entity\ContentEntityInterface $previous_revision
   *   (optional) Previous revision for getRevisionDescription function.
   *   Defaults to NULL.
   *
   * @return array
   *   Configuration for revision.
   */
  protected function buildRevision(Link $link, $username, ContentEntityInterface $revision, ContentEntityInterface $previous_revision = NULL) {
    return [
      '#type' => 'inline_template',
      '#template' => '{% trans %}{{ date }} by {{ username }}{% endtrans %}{% if message %}<p class="revision-log">{{ message }}</p>{% endif %}',
      '#context' => [
        'date' => $link->toString(),
        'username' => $this->renderer->renderPlain($username),
        'message' => [
          '#markup' => $this->entityComparison->getRevisionDescription($revision, $previous_revision),
          '#allowed_tags' => Xss::getAdminTagList(),
        ],
      ],
    ];
  }

  /**
   * Set column attributes and return config array.
   *
   * @param string $name
   *   Name attribute.
   * @param string $return_val
   *   Return value attribute.
   * @param string $default_val
   *   Default value attribute.
   *
   * @return array
   *   Configuration array.
   */
  protected function buildSelectColumn($name, $return_val, $default_val) {
    return [
      '#type' => 'radio',
      '#title_display' => 'invisible',
      '#name' => $name,
      '#return_value' => $return_val,
      '#default_value' => $default_val,
    ];
  }

}
