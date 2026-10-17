<?php

namespace Drupal\custom_registration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * Provides a custom user registration form.
 */
class CustomRegistrationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_registration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#required' => TRUE,
    ];

    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
    ];

    $form['pass'] = [
      '#type' => 'password_confirm',
      '#title' => $this->t('Password'),
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create account'),
    ];
    $form['#attributes']['class'][] = 'custom-registration-form';
    $form['#attached']['library'][] = 'custom_registration/registration';
    return $form;
  }

  /**
   * {@inheritdoc}
   */

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $name = $form_state->getValue('name');
    $mail = $form_state->getValue('mail');

    $existing_name = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['name' => $name]);

    if (!empty($existing_name)) {
      $form_state->setErrorByName(
        'name',
        $this->t('This username is already taken.')
      );
    }

    $existing_mail = \Drupal::entityTypeManager()
      ->getStorage('user')
      ->loadByProperties(['mail' => $mail]);

    if (!empty($existing_mail)) {
      $form_state->setErrorByName(
        'mail',
        $this->t('This email is already registered.')
      );
    }
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $account = User::create([
      'name' => $form_state->getValue('name'),
      'mail' => $form_state->getValue('mail'),
      'status' => 1,
    ]);

    $account->setPassword($form_state->getValue('pass'));
    $account->save();

    $this->messenger()->addStatus($this->t('Your account has been created successfully.'));
  }

}
