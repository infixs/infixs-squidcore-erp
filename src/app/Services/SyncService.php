<?php

namespace Infixs\SquidCore\Services;

use Infixs\SquidCore\AvelPress\Config\SettingsRepository;
use Infixs\SquidCore\Integrations\WooCommerce\ActivityRepository;
use Infixs\SquidCore\Integrations\WooCommerce\SyncDispatcher;
use Infixs\SquidCore\Integrations\WooCommerce\SyncStatusRepository;

defined( 'ABSPATH' ) || exit;

/**
 * What the store sends to SquidCore and how the sending is going.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class SyncService {

	/**
	 * @var SettingsRepository
	 */
	protected $settings;

	/**
	 * @var SyncDispatcher
	 */
	protected $dispatcher;

	/**
	 * @var SyncStatusRepository
	 */
	protected $status;

	/**
	 * @var ActivityRepository
	 */
	protected $activity;

	public function __construct( SettingsRepository $settings, SyncDispatcher $dispatcher, SyncStatusRepository $status, ActivityRepository $activity ) {
		$this->settings = $settings;
		$this->dispatcher = $dispatcher;
		$this->status = $status;
		$this->activity = $activity;
	}

	/**
	 * @return array{catalog: bool, orders: bool}
	 */
	public function options(): array {
		return [
			'catalog' => $this->settings->boolean( 'sync.catalog', true ),
			'orders' => $this->settings->boolean( 'sync.orders', true ),
		];
	}

	public function saveOptions( bool $catalog, bool $orders ): void {
		$this->settings->update( 'sync', [
			'catalog' => $catalog,
			'orders' => $orders,
		] );
	}

	/**
	 * @return array{woocommerceActive: bool, catalog: bool, orders: bool, pending: int, today: array{sent: int, failed: int}, lastSuccess: array|null, lastFailure: array|null}
	 */
	public function overview(): array {
		$last_success = $this->status->lastSuccess();
		$last_failure = $this->status->lastFailure();
		$today = $this->activity->today();

		return array_merge( $this->options(), [
			'woocommerceActive' => class_exists( 'WooCommerce' ),
			'pending' => $this->dispatcher->pendingCount(),
			// Pushes of today, in the timezone of the store, for the summary card; the chart has the whole period.
			'today' => [
				'sent' => $today[ ActivityRepository::SENT ],
				'failed' => $today[ ActivityRepository::FAILED ],
			],
			'lastSuccess' => $last_success ? [
				'topic' => (string) $last_success['topic'],
				'entityId' => (int) $last_success['entity_id'],
				'ignored' => ! empty( $last_success['ignored'] ),
				'at' => (string) $last_success['at'],
			] : null,
			'lastFailure' => $last_failure ? [
				'topic' => (string) $last_failure['topic'],
				'entityId' => (int) $last_failure['entity_id'],
				'message' => (string) $last_failure['message'],
				'at' => (string) $last_failure['at'],
			] : null,
		] );
	}
}
