<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products\FiscalImport;

use Infixs\SquidCore\Integrations\WebmaniaBR\WebmaniaBR;
use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceSettings;
use Infixs\SquidCore\Support\NoticeDismissals;

defined( 'ABSPATH' ) || exit;

/**
 * Notices of the import due for a user (TS AppNotice), shown inside the admin app and on a few WordPress screens:
 * - webmania-import-ncm: the suggestion to bring the NCM codes of WebmaniaBR;
 * - double-emission: WebmaniaBR and SquidCore both issue the invoice of the orders by themselves;
 * - fiscal-import-done: an import finished in the background, for the one who started it.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class FiscalImportNoticeBoard {

	public const IMPORT = 'webmania-import-ncm';

	public const DOUBLE_EMISSION = 'double-emission';

	public const DONE = 'fiscal-import-done';

	public const IDS = [ self::IMPORT, self::DOUBLE_EMISSION, self::DONE ];

	public const MODE_SNOOZE = 'snooze';

	public const MODE_FOREVER = 'forever';

	public const MODES = [ self::MODE_SNOOZE, self::MODE_FOREVER ];

	private const IMPORT_SNOOZE = 7 * DAY_IN_SECONDS;

	/**
	 * The risk of two invoices per order is never dismissed for good: it comes back after this, while it lasts.
	 */
	private const DOUBLE_EMISSION_SNOOZE = 30 * DAY_IN_SECONDS;

	/**
	 * @var FiscalImportSuggestion
	 */
	protected $suggestion;

	/**
	 * @var FiscalImportRunRepository
	 */
	protected $runs;

	/**
	 * @var WebmaniaBR
	 */
	protected $webmania;

	/**
	 * @var InvoiceSettings
	 */
	protected $invoices;

	/**
	 * @var NoticeDismissals
	 */
	protected $dismissals;

	public function __construct( FiscalImportSuggestion $suggestion, FiscalImportRunRepository $runs, WebmaniaBR $webmania, InvoiceSettings $invoices, NoticeDismissals $dismissals ) {
		$this->suggestion = $suggestion;
		$this->runs = $runs;
		$this->webmania = $webmania;
		$this->invoices = $invoices;
		$this->dismissals = $dismissals;
	}

	/**
	 * @return array<int, array{id: string, kind: string, count: int|null, actionUrl: string|null}>
	 */
	public function due( int $userId ): array {
		$notices = [];

		if ( $this->doubleEmission() && ! $this->dismissals->isDismissed( $userId, self::DOUBLE_EMISSION ) ) {
			$notices[] = $this->notice( self::DOUBLE_EMISSION, 'warning', null, $this->webmania->settingsUrl() );
		}

		$done = $this->doneRun( $userId );

		if ( $done !== null ) {
			$notices[] = $this->notice( self::DONE, 'success', $done['counts']['writes'], null );
		}

		if ( $this->suggestion->isDue() && ! $this->dismissals->isDismissed( $userId, self::IMPORT ) ) {
			$notices[] = $this->notice( self::IMPORT, 'info', $this->suggestion->candidates(), null );
		}

		return $notices;
	}

	/**
	 * WebmaniaBR (active) issues the invoices by itself and so does SquidCore: two invoices per order.
	 */
	public function doubleEmission(): bool {
		return $this->webmania->autoEmission() && $this->invoices->isEnabled();
	}

	/**
	 * Dismisses a notice for the user.
	 *
	 * @param string $mode MODE_SNOOZE or MODE_FOREVER.
	 *
	 * @return int|null Unix time it may show again (0: never); null for an unknown notice.
	 */
	public function dismiss( int $userId, string $id, string $mode ): ?int {
		switch ( $id ) {
			case self::IMPORT:
				if ( $mode === self::MODE_FOREVER ) {
					$this->dismissals->dismissForever( $userId, $id );
					return 0;
				}

				return $this->dismissals->snooze( $userId, $id, self::IMPORT_SNOOZE );

			case self::DOUBLE_EMISSION:
				return $this->dismissals->snooze( $userId, $id, self::DOUBLE_EMISSION_SNOOZE );

			case self::DONE:
				$this->acknowledgeDone( $userId );
				return 0;
		}

		return null;
	}

	/**
	 * The one who started the finished import saw it: its notice goes away.
	 */
	public function acknowledgeDone( int $userId ): void {
		if ( $this->doneRun( $userId ) === null ) {
			return;
		}

		$this->runs->update( function ( array $state ) use ( $userId ): array {
			if ( $state['run'] !== null && $state['run']['startedById'] === $userId ) {
				$state['run']['doneNotice'] = false;
			}

			return $state;
		} );
	}

	/**
	 * The import that finished with its notice due for the user, null when none.
	 */
	private function doneRun( int $userId ): ?array {
		$run = $this->runs->snapshot()['run'];

		$due = $run !== null
			&& $userId > 0
			&& $run['mode'] === FiscalImportRun::MODE_APPLY
			&& $run['status'] === FiscalImportRun::DONE
			&& $run['doneNotice']
			&& $run['startedById'] === $userId;

		return $due ? $run : null;
	}

	private function notice( string $id, string $kind, ?int $count, ?string $actionUrl ): array {
		return [
			'id' => $id,
			'kind' => $kind,
			'count' => $count,
			'actionUrl' => $actionUrl,
		];
	}
}
