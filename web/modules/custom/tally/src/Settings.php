<?php

namespace Drupal\tally;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\tally\Entity\Invoice;
use Drupal\user\UserDataInterface;

/**
 * The current user's preferences and the business details for invoices.
 *
 * Kept in core's user.data, one array per user, so there is no entity or
 * schema to maintain. Unset keys fall back to DEFAULTS.
 */
class Settings {

  public const ACCENTS = ['indigo' => 'Indigo', 'teal' => 'Teal', 'violet' => 'Violet', 'orange' => 'Burnt orange'];

  public const DEFAULTS = [
    'company_name' => '',
    // Stripe: both secrets are stored encrypted (see StripeAccounts).
    'stripe_secret_key' => '',
    'stripe_webhook_secret' => '',
    'stripe_endpoint' => '',
    'company_text' => '',
    'company_address' => '',
    // The id of the logo's file entity, 0 for none.
    'logo' => 0,
    'gst_number' => '',
    'qst_number' => '',
    'currency' => 'CAD',
    'invoice_language' => 'en',
    'gst_rate' => Invoice::GST_RATE,
    'qst_rate' => Invoice::QST_RATE,
    'payment_days' => 30,
    'accent' => 'indigo',
    'billable' => TRUE,
    'zoom' => '30',
  ];

  /**
   * Settings read so far in this request, by user id.
   */
  protected array $loaded = [];

  public function __construct(protected UserDataInterface $userData, protected AccountProxyInterface $currentUser) {}

  /**
   * All settings, defaults filled in.
   */
  public function all(): array {
    return $this->forUser((int) $this->currentUser->id());
  }

  /**
   * One user's settings, defaults filled in. The public invoice page has no
   * signed-in user, so it reads the invoice owner's business details here.
   */
  public function forUser(int $uid): array {
    if (!isset($this->loaded[$uid])) {
      $saved = $this->userData->get('tally', $uid, 'settings');
      $this->loaded[$uid] = (is_array($saved) ? $saved : []) + static::DEFAULTS;
    }
    return $this->loaded[$uid];
  }

  public function get(string $key): mixed {
    return $this->all()[$key];
  }

  /**
   * Saves the given keys and leaves the others as they were.
   */
  public function save(array $values): void {
    $this->saveFor((int) $this->currentUser->id(), $values);
  }

  /**
   * The same for any user, for code that has no signed-in one.
   */
  public function saveFor(int $uid, array $values): void {
    $merged = array_intersect_key($values, static::DEFAULTS) + $this->forUser($uid);
    $this->userData->set('tally', $uid, 'settings', $merged);
    $this->loaded[$uid] = $merged + static::DEFAULTS;
  }

  /**
   * The accent to put on <html>, or NULL for the default indigo.
   */
  public function accent(): ?string {
    $accent = $this->get('accent');
    return $accent !== 'indigo' && isset(static::ACCENTS[$accent]) ? $accent : NULL;
  }

}
