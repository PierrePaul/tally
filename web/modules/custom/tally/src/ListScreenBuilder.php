<?php

namespace Drupal\tally;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the Projects and Clients screens share: the URL is the state.
 *
 * What is open (an edit dialog, a report, the search, the tab) lives in the
 * query string, so a reload or a shared link shows the same thing, and every
 * HTMX swap is just "fetch this URL, keep the screen element".
 */
abstract class ListScreenBuilder {

  use StringTranslationTrait;

  /**
   * The route of the screen, so URLs can be built from options.
   */
  protected const ROUTE = '';

  /**
   * Reads and validates what the URL says about the screen.
   *
   * @return array{status: string, q: string, edit: ?string, report: ?int, period: string, from: ?string, to: ?string, kinds: string[]}
   */
  public function options(Request $request): array {
    $get = fn (string $key) => $request->query->get($key) ?? $request->request->get($key);
    $period = (string) $get('period');
    $edit = (string) $get('edit');
    $kinds = ['billable', 'non'];
    $all = $request->query->all() + $request->request->all();
    if (isset($all['kinds_set'])) {
      $given = is_array($all['kinds'] ?? NULL) ? $all['kinds'] : [];
      $kinds = array_values(array_intersect($kinds, $given));
    }
    return [
      'status' => $get('status') === 'archived' ? 'archived' : 'active',
      'q' => mb_substr(trim((string) $get('q')), 0, 100),
      'edit' => $edit === 'new' || ctype_digit($edit) && $edit !== '' ? $edit : NULL,
      'report' => ctype_digit((string) $get('report')) && $get('report') !== '' ? (int) $get('report') : NULL,
      'period' => isset(ProjectReport::PERIODS[$period]) ? $period : 'week',
      'from' => (string) $get('from') ?: NULL,
      'to' => (string) $get('to') ?: NULL,
      'kinds' => $kinds,
    ];
  }

  /**
   * The screen URL with some options changed. NULL drops an option.
   */
  public function url(array $options, array $changes): string {
    return Url::fromRoute(static::ROUTE, [], ['query' => $this->query($options, $changes)])->toString();
  }

  /**
   * The non-default options as a query array.
   */
  protected function query(array $options, array $changes): array {
    $values = array_merge($options, $changes);
    $query = [];
    if ($values['status'] === 'archived') {
      $query['status'] = 'archived';
    }
    if ($values['q'] !== '') {
      $query['q'] = $values['q'];
    }
    if ($values['edit'] !== NULL) {
      $query['edit'] = $values['edit'];
    }
    if ($values['report'] !== NULL) {
      $query['report'] = $values['report'];
      if ($values['period'] !== 'week') {
        $query['period'] = $values['period'];
      }
      if ($values['period'] === 'custom') {
        $query += array_filter(['from' => $values['from'], 'to' => $values['to']]);
      }
      if ($values['kinds'] !== ['billable', 'non']) {
        $query['kinds_set'] = 1;
        $query['kinds'] = $values['kinds'];
      }
    }
    return $query;
  }

  /**
   * Where "+ Invoice more hours" goes.
   */
  protected function invoiceUrl(): string {
    return Url::fromRoute('tally.invoices')->toString();
  }

}
