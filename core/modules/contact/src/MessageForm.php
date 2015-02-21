<?php

/**
 * @file
 * Contains \Drupal\contact\MessageForm.
 */

namespace Drupal\contact;

use Drupal\Component\Utility\String;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for contact message forms.
 */
class MessageForm extends ContentEntityForm {

  /**
   * The message being used by this form.
   *
   * @var \Drupal\contact\MessageInterface
   */
  protected $entity;

  /**
   * The flood control mechanism.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The contact mail handler service.
   *
   * @var \Drupal\contact\MailHandlerInterface
   */
  protected $mailHandler;

  /**
   * Constructs a MessageForm object.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood control mechanism.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\contact\MailHandlerInterface $mail_handler
   *   The contact mail handler service.
   */
  public function __construct(EntityManagerInterface $entity_manager, FloodInterface $flood, LanguageManagerInterface $language_manager, MailHandlerInterface $mail_handler) {
    parent::__construct($entity_manager);
    $this->flood = $flood;
    $this->languageManager = $language_manager;
    $this->mailHandler = $mail_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('flood'),
      $container->get('language_manager'),
      $container->get('contact.mail_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $user = $this->currentUser();
    $message = $this->entity;
    $form = parent::form($form, $form_state, $message);
    $form['#attributes']['class'][] = 'contact-form';

    if (!empty($message->preview)) {
      $form['preview'] = array(
        '#theme_wrappers' => array('container__preview'),
        '#attributes' => array('class' => array('preview')),
      );
      $form['preview']['message'] = $this->entityManager->getViewBuilder('contact_message')->view($message, 'full');
    }

    $form['name'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#maxlength' => 255,
      '#required' => TRUE,
    );
    $form['mail'] = array(
      '#type' => 'email',
      '#title' => $this->t('Your email address'),
      '#required' => TRUE,
    );
    if ($user->isAnonymous()) {
      $form['#attached']['library'][] = 'core/drupal.form';
      $form['#attributes']['data-user-info-from-browser'] = TRUE;
    }
    // Do not allow authenticated users to alter the name or email values to
    // prevent the impersonation of other users.
    else {
      $form['name']['#type'] = 'item';
      $form['name']['#value'] = $user->getUsername();
      $form['name']['#required'] = FALSE;
      $form['name']['#markup'] = String::checkPlain($user->getUsername());

      $form['mail']['#type'] = 'item';
      $form['mail']['#value'] = $user->getEmail();
      $form['mail']['#required'] = FALSE;
      $form['mail']['#markup'] = String::checkPlain($user->getEmail());
    }

    // The user contact form has a preset recipient.
    if ($message->isPersonal()) {
      $form['recipient'] = array(
        '#type' => 'item',
        '#title' => $this->t('To'),
        '#value' => $message->getPersonalRecipient()->id(),
        'name' => array(
          '#theme' => 'username',
          '#account' => $message->getPersonalRecipient(),
        ),
      );
    }

    $form['copy'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Send yourself a copy'),
      // Do not allow anonymous users to send themselves a copy, because it can
      // be abused to spam people.
      '#access' => $user->isAuthenticated(),
    );
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function actions(array $form, FormStateInterface $form_state) {
    $elements = parent::actions($form, $form_state);
    $elements['submit']['#value'] = $this->t('Send message');
    $elements['preview'] = array(
      '#value' => $this->t('Preview'),
      '#validate' => array('::validate'),
      '#submit' => array('::submitForm', '::preview'),
    );
    return $elements;
  }

  /**
   * Form submission handler for the 'preview' action.
   */
  public function preview(array $form, FormStateInterface $form_state) {
    $message = $this->entity;
    $message->preview = TRUE;
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $message = $this->entity;
    $user = $this->currentUser();
    $this->mailHandler->sendMailMessages($message, $user);

    $this->flood->register('contact', $this->config('contact.settings')->get('flood.interval'));
    drupal_set_message($this->t('Your message has been sent.'));

    // To avoid false error messages caused by flood control, redirect away from
    // the contact form; either to the contacted user account or the front page.
    if ($message->isPersonal() && $user->hasPermission('access user profiles')) {
      $form_state->setRedirectUrl($message->getPersonalRecipient()->urlInfo());
    }
    else {
      $form_state->setRedirect('<front>');
    }
    // Save the message. In core this is a no-op but should contrib wish to
    // implement message storage, this will make the task of swapping in a real
    // storage controller straight-forward.
    $message->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function init(FormStateInterface $form_state) {
    $message = $this->entity;

    // Make the message inherit the current content language unless specifically
    // set.
    if ($message->isNew() && !$message->langcode->value) {
      $language_content = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT);
      $message->langcode->value = $language_content->getId();
    }

    parent::init($form_state);
  }

}