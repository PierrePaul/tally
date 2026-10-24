<?php

namespace Drupal\tally\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\tally\Entity\Client;
use Drupal\tally\Entity\Invoice;
use Drupal\tally\InvoiceLanguage;
use Drupal\tally\Settings;
use Drupal\tally\StripeAccounts;
use Drupal\Core\Url;
use Drupal\tally\TimeScreenBuilder;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Settings screen: account, appearance, business details and defaults.
 *
 * One form, one Save button. Each section is a card; the theme styles them
 * through the settings-* classes.
 */
class SettingsForm extends FormBase {

  /**
   * The interface languages, each named in its own language.
   */
  public const INTERFACE_LANGUAGES = ['fr' => 'Français', 'en' => 'English'];

  public function __construct(protected Settings $settings, protected PasswordInterface $password, protected AccountProxyInterface $account, protected EntityTypeManagerInterface $entityTypeManager, protected StripeAccounts $stripe) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tally.settings'), $container->get('password'), $container->get('current_user'), $container->get('entity_type.manager'), $container->get('tally.stripe_accounts'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tally_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = $this->user();
    $values = $this->settings->all();
    $form['#attributes']['class'][] = 'settings';

    $form['head'] = [
      '#markup' => '<header class="page-head"><div class="page-head__titles"><div class="eyebrow">' . $this->t('Configuration') . '</div><h1>' . $this->t('Settings') . '</h1></div></header>',
    ];

    $form['account'] = $this->section($this->t('Account'), $this->t('How you sign in.'));
    $form['account']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $user->getAccountName(),
      '#required' => TRUE,
      '#maxlength' => UserInterface::USERNAME_MAX_LENGTH,
    ];
    $form['account']['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $user->getEmail(),
      '#required' => TRUE,
    ];
    $form['account']['current_pass'] = [
      '#type' => 'password',
      '#title' => $this->t('Current password'),
      '#description' => $this->t('Only needed to change your email or password.'),
      '#autocomplete' => 'current-password',
    ];
    $form['account']['new_pass'] = [
      '#type' => 'password',
      '#title' => $this->t('New password'),
      '#description' => $this->t('Leave empty to keep the current one.'),
      '#autocomplete' => 'new-password',
    ];

    $form['appearance'] = $this->section($this->t('Appearance'), $this->t('The language and the accent color are saved with your account. Light and dark mode is the toggle at the top of the sidebar.'));
    $form['appearance']['interface_language'] = [
      '#type' => 'select',
      '#title' => $this->t('Interface language'),
      '#description' => $this->t('The language of the menus, buttons, dates and amounts. Invoices have their own language, set below.'),
      '#options' => static::INTERFACE_LANGUAGES,
      '#default_value' => static::interfaceLanguage($user->getPreferredLangcode(FALSE)),
    ];
    $form['appearance']['accent'] = [
      '#type' => 'radios',
      '#title' => $this->t('Accent color'),
      '#options' => array_map(fn ($label) => $this->t($label), Settings::ACCENTS),
      '#default_value' => $values['accent'],
    ];

    $form['business'] = $this->section($this->t('Business details'), $this->t('Printed on your invoices. In Québec an invoice needs your name, your GST and QST registration numbers, and the taxes charged.'));
    $form['business']['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company name'),
      '#default_value' => $values['company_name'],
      '#maxlength' => 255,
    ];
    $form['business']['company_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Text under the company name'),
      '#description' => $this->t('Optional. Printed under the name on invoices, for example a tagline or your phone and website.'),
      '#default_value' => $values['company_text'],
      '#rows' => 2,
      '#maxlength' => 500,
    ];
    $form['business']['company_address'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Company address'),
      '#default_value' => $values['company_address'],
      '#rows' => 3,
    ];
    $form['business']['logo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Logo'),
      '#description' => $this->t('Optional. Shown to the left of your name on invoices. PNG or JPG, up to 1 MB.'),
      '#upload_location' => 'public://tally/logo',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png jpg jpeg'],
        'FileSizeLimit' => ['fileLimit' => 1048576],
      ],
      '#default_value' => $values['logo'] ? [$values['logo']] : [],
    ];
    $form['business']['gst_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GST registration number'),
      '#description' => $this->t('Nine digits, then RT and four digits. Example: 123456789 RT 0001.'),
      '#default_value' => $values['gst_number'],
      '#maxlength' => 20,
    ];
    $form['business']['qst_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('QST registration number'),
      '#description' => $this->t('Ten digits, then TQ and four digits. Example: 1234567890 TQ 0001.'),
      '#default_value' => $values['qst_number'],
      '#maxlength' => 20,
    ];

    $form['invoicing'] = $this->section($this->t('Invoicing defaults'), $this->t('Used when you create something new. Existing clients and invoices keep what they have.'));
    $form['invoicing']['currency'] = [
      '#type' => 'select',
      '#title' => $this->t('Currency for new clients'),
      '#options' => Client::CURRENCIES,
      '#default_value' => $values['currency'],
    ];
    $form['invoicing']['invoice_language'] = [
      '#type' => 'select',
      '#title' => $this->t('Invoice language for new clients'),
      '#description' => $this->t('What a client reads on the shared page, in the PDF and on the Stripe payment page. Each client keeps its own language, and you can change it on each draft.'),
      '#options' => InvoiceLanguage::LANGUAGES,
      '#default_value' => InvoiceLanguage::normalize($values['invoice_language']),
    ];
    foreach (['gst_rate' => $this->t('GST rate (%)'), 'qst_rate' => $this->t('QST rate (%)')] as $key => $title) {
      $form['invoicing'][$key] = [
        '#type' => 'textfield',
        '#title' => $title,
        '#default_value' => Invoice::formatRate((int) $values[$key]),
        '#size' => 8,
        '#description' => $key === 'gst_rate'
          ? $this->t('Drafts follow this rate. When an invoice is sent, it keeps the rates it was sent with, so a later change never touches it.')
          : NULL,
      ];
    }
    $form['invoicing']['payment_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Payment terms (days)'),
      '#description' => $this->t('A new invoice is due this many days after its issue date.'),
      '#default_value' => $values['payment_days'],
      '#min' => 0,
      '#max' => 365,
    ];

    $uid = (int) $this->account->id();
    $saved_key = $this->stripe->secretKey($uid);
    $saved_whsec = $this->stripe->webhookSecret($uid);
    $form['payments'] = $this->section($this->t('Payments (Stripe)'), $this->t('Lets your clients pay an invoice by credit card from the shared page. The money goes to your own Stripe account. Both secrets are stored encrypted.'));
    $form['payments']['stripe_secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret key'),
      '#description' => ($saved_key !== '' ? $this->t('Saved: @hint. Leave empty to keep it.', ['@hint' => StripeAccounts::hint($saved_key)]) . ' ' : '')
        . $this->t('From the Stripe dashboard, Developers, API keys. A restricted key (rk_…) that can only write Checkout Sessions is safest. Without a key, invoices have no pay button.'),
      '#autocomplete' => 'off',
      '#maxlength' => 255,
    ];
    $form['payments']['stripe_webhook_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook URL'),
      '#description' => $this->t('In Stripe, add a webhook endpoint with this URL and the events checkout.session.completed and checkout.session.async_payment_succeeded. This is how Stripe tells Tally an invoice was paid.'),
      '#value' => Url::fromRoute('tally.stripe.webhook', ['endpoint' => $this->stripe->endpointId($uid)], ['absolute' => TRUE])->toString(),
      '#attributes' => ['readonly' => 'readonly'],
    ];
    $form['payments']['stripe_webhook_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Webhook signing secret'),
      '#description' => ($saved_whsec !== '' ? $this->t('Saved: @hint. Leave empty to keep it.', ['@hint' => StripeAccounts::hint($saved_whsec)]) . ' ' : '')
        . $this->t('Shown by Stripe after you add the endpoint (whsec_…). Without it, payments cannot be confirmed.'),
      '#autocomplete' => 'off',
      '#maxlength' => 255,
    ];
    if ($saved_key !== '' || $saved_whsec !== '') {
      $form['payments']['stripe_remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove the saved Stripe keys'),
      ];
    }

    $form['time'] = $this->section($this->t('Time defaults'));
    $form['time']['billable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('New timers are billable'),
      '#default_value' => $values['billable'],
    ];
    $form['time']['zoom'] = [
      '#type' => 'select',
      '#title' => $this->t('Calendar spacing'),
      '#options' => ['15' => $this->t('15 min'), '30' => $this->t('30 min'), '60' => $this->t('1 hr')],
      '#default_value' => $values['zoom'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save settings'), '#button_type' => 'primary'];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $user = $this->user();
    $name = trim($form_state->getValue('name'));
    $mail = trim($form_state->getValue('mail'));

    $others = fn (string $field, string $value) => array_filter(
      $this->entityTypeManager->getStorage('user')->loadByProperties([$field => $value]),
      fn (UserInterface $other) => $other->id() != $user->id(),
    );
    if ($others('name', $name)) {
      $form_state->setErrorByName('name', $this->t('That name is already taken.'));
    }
    if ($others('mail', $mail)) {
      $form_state->setErrorByName('mail', $this->t('That email is already used by another account.'));
    }

    $new_pass = (string) $form_state->getValue('new_pass');
    if ($new_pass !== '' && mb_strlen($new_pass) < 8) {
      $form_state->setErrorByName('new_pass', $this->t('Use at least 8 characters.'));
    }
    // The password protects the sign-in details: changing either needs it.
    if (($new_pass !== '' || strcasecmp($mail, (string) $user->getEmail()) !== 0)
      && !$this->password->check((string) $form_state->getValue('current_pass'), (string) $user->getPassword())) {
      $form_state->setErrorByName('current_pass', $this->t('Enter your current password to change your email or password.'));
    }

    foreach (['gst_number' => ['/^(\d{9}) ?RT ?(\d{4})$/', 'RT'], 'qst_number' => ['/^(\d{10}) ?TQ ?(\d{4})$/', 'TQ']] as $key => [$pattern, $mid]) {
      $value = strtoupper(trim($form_state->getValue($key)));
      if ($value === '') {
        continue;
      }
      if (!preg_match($pattern, $value, $m)) {
        $form_state->setErrorByName($key, $this->t('That number does not look right. Check the format below the field.'));
        continue;
      }
      $form_state->setValue($key, $m[1] . ' ' . $mid . ' ' . $m[2]);
    }

    foreach (['gst_rate', 'qst_rate'] as $key) {
      if (static::parseRate((string) $form_state->getValue($key)) === NULL) {
        $form_state->setErrorByName($key, $this->t('Enter a rate from 0 to 30, with up to three decimals. Example: 9.975.'));
      }
    }

    if (!$form_state->getValue('stripe_remove')) {
      foreach (['stripe_secret_key' => ['/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $this->t('That does not look like a Stripe secret key. It starts with sk_ or rk_.')], 'stripe_webhook_secret' => ['/^whsec_[A-Za-z0-9]+$/', $this->t('That does not look like a webhook signing secret. It starts with whsec_.')]] as $key => [$pattern, $message]) {
        $value = trim((string) $form_state->getValue($key));
        if ($value !== '' && !preg_match($pattern, $value)) {
          $form_state->setErrorByName($key, $message);
        }
      }
    }

    $days = $form_state->getValue('payment_days');
    if (!is_numeric($days) || $days < 0 || $days > 365 || (int) $days != $days) {
      $form_state->setErrorByName('payment_days', $this->t('Enter a whole number of days from 0 to 365.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = $this->user();
    $user->setUsername(trim($form_state->getValue('name')));
    $user->setEmail(trim($form_state->getValue('mail')));
    $language = static::interfaceLanguage($form_state->getValue('interface_language'));
    $user->set('preferred_langcode', $language);
    if ((string) $form_state->getValue('new_pass') !== '') {
      $user->setPassword($form_state->getValue('new_pass'));
    }
    $user->save();

    if ($form_state->getValue('stripe_remove')) {
      $this->stripe->save((int) $this->account->id(), '', '');
    }
    else {
      $key = trim((string) $form_state->getValue('stripe_secret_key'));
      $whsec = trim((string) $form_state->getValue('stripe_webhook_secret'));
      $this->stripe->save((int) $this->account->id(), $key !== '' ? $key : NULL, $whsec !== '' ? $whsec : NULL);
    }
    $logo = $this->saveLogo((int) (($form_state->getValue('logo') ?: [0])[0] ?? 0), (int) $this->settings->get('logo'));
    $this->settings->save([
      'logo' => $logo,
      'company_text' => trim($form_state->getValue('company_text')),
      'accent' => $form_state->getValue('accent'),
      'company_name' => trim($form_state->getValue('company_name')),
      'company_address' => trim($form_state->getValue('company_address')),
      'gst_number' => $form_state->getValue('gst_number'),
      'qst_number' => $form_state->getValue('qst_number'),
      'currency' => isset(Client::CURRENCIES[$form_state->getValue('currency')]) ? $form_state->getValue('currency') : 'CAD',
      'invoice_language' => InvoiceLanguage::normalize($form_state->getValue('invoice_language')),
      'gst_rate' => static::parseRate((string) $form_state->getValue('gst_rate')),
      'qst_rate' => static::parseRate((string) $form_state->getValue('qst_rate')),
      'payment_days' => (int) $form_state->getValue('payment_days'),
      'billable' => (bool) $form_state->getValue('billable'),
      'zoom' => isset(TimeScreenBuilder::ZOOMS[$form_state->getValue('zoom')]) ? $form_state->getValue('zoom') : '30',
    ]);
    // The next page loads in the chosen language, so the message is worded in it.
    $this->messenger()->addStatus($this->t('Settings saved.', [], ['langcode' => $language]));
  }

  /**
   * The given language when it is one of the interface languages, else French.
   */
  public static function interfaceLanguage(?string $langcode): string {
    return isset(static::INTERFACE_LANGUAGES[$langcode]) ? $langcode : 'fr';
  }

  /**
   * Keeps the chosen logo file, lets go of the one it replaces.
   *
   * @return int
   *   The file id to store, 0 for none.
   */
  protected function saveLogo(int $new, int $old): int {
    $storage = $this->entityTypeManager->getStorage('file');
    $usage = \Drupal::service('file.usage');
    $uid = (int) $this->account->id();
    // A posted file id is only trusted for a file the user owns.
    $new_file = $new ? $storage->load($new) : NULL;
    if ($new_file && (int) $new_file->getOwnerId() !== $uid) {
      $new_file = NULL;
    }
    if ($new && $new !== $old && $new_file) {
      $new_file->setPermanent();
      $new_file->save();
      $usage->add($new_file, 'tally', 'user', $uid);
    }
    elseif ($new && $new !== $old) {
      $new = $old;
    }
    $old_file = $old && $old !== $new ? $storage->load($old) : NULL;
    if ($old_file && (int) $old_file->getOwnerId() === $uid) {
      $usage->delete($old_file, 'tally', 'user', $uid);
      $old_file->delete();
    }
    return $new;
  }

  /**
   * "9.975" or "9,975" as 9975, or NULL when it is not a rate from 0 to 30.
   */
  protected static function parseRate(string $text): ?int {
    if (!preg_match('/^(\d{1,2})(?:[.,](\d{1,3}))?$/', trim($text), $m)) {
      return NULL;
    }
    $rate = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    return $rate <= 30000 ? $rate : NULL;
  }

  protected function user(): UserInterface {
    return $this->entityTypeManager->getStorage('user')->load($this->account->id());
  }

  /**
   * A card with a heading and optional help text.
   */
  protected function section($title, $help = NULL): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'settings-card']],
      'title' => ['#markup' => '<div class="settings-card__head"><h2>' . $title . '</h2>' . ($help ? '<p>' . $help . '</p>' : '') . '</div>'],
    ];
  }

}
