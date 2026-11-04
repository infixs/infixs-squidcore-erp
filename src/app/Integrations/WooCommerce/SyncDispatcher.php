<?php

namespace Infixs\SquidCore\Integrations\WooCommerce;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosTrackingCodes;
use Infixs\SquidCore\Integrations\WooCommerce\Products\ProductNcmResolver;
use Infixs\SquidCore\Integrations\WooCommerce\Serializers\CategorySerializer;
use Infixs\SquidCore\Integrations\WooCommerce\Serializers\OrderSerializer;
use Infixs\SquidCore\Integrations\WooCommerce\Serializers\ProductSerializer;
use Infixs\SquidCore\Support\ApiErrorMessage;
use Infixs\SquidCore\Support\BackgroundQueue;
use Infixs\SquidCore\Support\SquidCoreClient;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and executes the async push of WooCommerce entities to SquidCore.
 *
 * Jobs run through the BackgroundQueue (Action Scheduler, falling back to WP-Cron).
 * The entity is re-read (fresh state) at execution time, so bursts of hooks
 * for the same entity collapse into the latest state.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class SyncDispatcher {

	public const HOOK = 'infixs_squidcore_sync_push';

	private const EVENTS_ENDPOINT = 'integrations/apps/woocommerce/events';

	public const DISPATCH_DELAY = 5;

	/**
	 * Priority of the pushes of many products at once (an import, a background NCM change): after the pushes of the
	 * store's day-to-day changes (10).
	 */
	public const BULK_PRIORITY = 20;

	private const MAX_ATTEMPTS = 3;

	/**
	 * Retry delays in seconds, indexed by the attempt that just failed.
	 */
	private const RETRY_DELAYS = [ 1 => 60, 2 => 300 ];

	/**
	 * @var SquidCoreClient
	 */
	protected $client;

	/**
	 * @var ProductSerializer
	 */
	protected $productSerializer;

	/**
	 * @var CategorySerializer
	 */
	protected $categorySerializer;

	/**
	 * @var OrderSerializer
	 */
	protected $orderSerializer;

	/**
	 * @var SyncStatusRepository
	 */
	protected $status;

	/**
	 * @var ActivityRepository
	 */
	protected $activity;

	/**
	 * @var BackgroundQueue
	 */
	protected $queue;

	/**
	 * @param SquidCoreClient $client
	 * @param SyncStatusRepository $status
	 * @param ActivityRepository $activity
	 * @param BackgroundQueue $queue
	 * @param ProductNcmResolver $ncmResolver
	 * @param CorreiosTrackingCodes|null $trackingCodes Tracking codes of Correios Automático in the order pushes.
	 */
	public function __construct( SquidCoreClient $client, SyncStatusRepository $status, ActivityRepository $activity, BackgroundQueue $queue, ProductNcmResolver $ncmResolver, ?CorreiosTrackingCodes $trackingCodes = null ) {
		$this->client = $client;
		$this->status = $status;
		$this->activity = $activity;
		$this->queue = $queue;
		$this->productSerializer = new ProductSerializer( $ncmResolver );
		$this->categorySerializer = new CategorySerializer();
		$this->orderSerializer = new OrderSerializer( $ncmResolver, $trackingCodes );
	}

	/**
	 * Registers the queue handler. Must run on every request (front, admin,
	 * cron), otherwise scheduled pushes would never execute.
	 */
	public function registerHandler(): void {
		add_action( self::HOOK, [ $this, 'handle' ] );
	}

	/**
	 * Schedules a push for an entity. Deduplicates pending first-attempt jobs
	 * for the same topic + entity.
	 *
	 * @param string $topic e.g. "product.updated".
	 * @param int $entityId
	 * @param int $delay Seconds before the push.
	 * @param int $priority Action Scheduler priority of the first attempt (lower runs first). Bulk pushes pass a
	 *                      higher number, so the pushes of the store's day-to-day changes go ahead of them; the
	 *                      retries always take the default.
	 */
	public function dispatch( string $topic, int $entityId, int $delay = self::DISPATCH_DELAY, int $priority = 10 ): void {
		$this->queue->schedule( self::HOOK, [ $this->buildJob( $topic, $entityId, 1 ) ], time() + $delay, true, $priority );
	}

	/**
	 * Sends the current state of an entity right away, in one attempt, whatever the sync options say.
	 * Used when another feature needs SquidCore to know the entity first (e.g. before issuing an invoice).
	 *
	 * A success counts as sent for the activity chart; a failure does not count as lost here, because the caller
	 * owns the retry policy (an automatic emission pushes the order again on its next attempt) and only it knows
	 * when the push is lost for good.
	 *
	 * @param string $topic e.g. "order.updated".
	 * @param int $entityId
	 *
	 * @return true|\WP_Error Code `squidcore_sync_ignored` when SquidCore accepted the event but discarded it.
	 */
	public function pushNow( string $topic, int $entityId ) {
		$result = $this->send( $topic, $entityId, $this->client->withMaxAttempts( 1 ) );

		if ( is_wp_error( $result ) || $result === true ) {
			return $result;
		}

		if ( $result === null ) {
			return new \WP_Error( 'squidcore_entity_not_found', __( 'The item to send no longer exists.', 'infixs-squidcore-erp' ), [ 'status' => 404 ] );
		}

		return new \WP_Error(
			'squidcore_sync_ignored',
			__( 'SquidCore received the data but discarded it: receiving data from this store is disabled in the WooCommerce integration in SquidCore.', 'infixs-squidcore-erp' ),
			[ 'status' => 409 ]
		);
	}

	/**
	 * Queue handler: serializes the current entity state and sends the event.
	 *
	 * @param mixed $job [ 'topic' => string, 'id' => int, 'attempt' => int ]
	 */
	public function handle( $job ): void {
		if ( ! is_array( $job ) || empty( $job['topic'] ) || empty( $job['id'] ) ) {
			return;
		}

		$topic = (string) $job['topic'];
		$entity_id = (int) $job['id'];
		$attempt = isset( $job['attempt'] ) ? max( 1, (int) $job['attempt'] ) : 1;

		if ( ! $this->client->isConfigured() || $this->isTrashedProduct( $topic, $entity_id ) ) {
			return;
		}

		$result = $this->send( $topic, $entity_id, $this->client );

		if ( is_wp_error( $result ) ) {
			$this->handleFailure( $topic, $entity_id, $attempt, $result );
		}
	}

	/**
	 * Number of pushes waiting in the queue, retries included.
	 */
	public function pendingCount(): int {
		return $this->queue->pendingCount( self::HOOK );
	}

	/**
	 * Serializes the current entity state, sends the event and records the outcome.
	 *
	 * @return bool|\WP_Error|null True when SquidCore took the event, false when it discarded it (inbound sync off
	 *                             on its side), null when the entity no longer exists.
	 */
	protected function send( string $topic, int $entity_id, SquidCoreClient $client ) {
		$payload = $this->buildPayload( $topic, $entity_id );

		if ( null === $payload ) {
			return null;
		}

		$response = $client->post( self::EVENTS_ENDPOINT, [
			'topic' => $topic,
			'siteUrl' => home_url(),
			'payload' => $payload,
		] );

		if ( is_wp_error( $response ) ) {
			$this->status->recordFailure( $topic, $entity_id, ApiErrorMessage::describe( $response ) );
			return $response;
		}

		$ignored = isset( $response['data']['status'] ) && 'ignored' === $response['data']['status'];

		$this->status->recordSuccess( $topic, $entity_id, $ignored );

		// A discarded event still reached SquidCore: the chart shows what the store sent.
		$this->activity->increment( ActivityRepository::SENT );

		return ! $ignored;
	}

	/**
	 * Serializes the fresh entity state for a topic.
	 *
	 * @param string $topic
	 * @param int $entity_id
	 *
	 * @return array|null Null aborts the push (entity no longer exists).
	 */
	protected function buildPayload( string $topic, int $entity_id ): ?array {
		if ( $this->isDeletedTopic( $topic ) ) {
			return [ 'id' => $entity_id ];
		}

		$entity_type = strtok( $topic, '.' );

		switch ( $entity_type ) {
			case 'product':
				if ( ! function_exists( 'wc_get_product' ) ) {
					return null;
				}
				$product = wc_get_product( $entity_id );
				return $product instanceof \WC_Product ? $this->productSerializer->serialize( $product ) : null;

			case 'category':
				$term = get_term( $entity_id, 'product_cat' );
				return $term instanceof \WP_Term ? $this->categorySerializer->serialize( $term ) : null;

			case 'order':
				if ( ! function_exists( 'wc_get_order' ) ) {
					return null;
				}
				$order = wc_get_order( $entity_id );
				return $order instanceof \WC_Order ? $this->orderSerializer->serialize( $order ) : null;
		}

		return null;
	}

	protected function handleFailure( string $topic, int $entity_id, int $attempt, \WP_Error $error ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[infixs-squidcore] Sync push failed (topic: %s, entity: %d, attempt: %d/%d): %s',
			$topic,
			$entity_id,
			$attempt,
			self::MAX_ATTEMPTS,
			$error->get_error_message()
		) );

		// A refused event (bad token, no integration, invalid data) would be refused again.
		if ( $attempt >= self::MAX_ATTEMPTS || ! ApiErrorMessage::isTransient( $error ) ) {
			// Only the failure that ends the attempts counts, so the retries of one push do not inflate the chart.
			$this->activity->increment( ActivityRepository::FAILED );
			return;
		}

		$delay = self::RETRY_DELAYS[ $attempt ] ?? 300;

		$this->queue->schedule( self::HOOK, [ $this->buildJob( $topic, $entity_id, $attempt + 1 ) ], time() + $delay );
	}

	protected function buildJob( string $topic, int $entity_id, int $attempt ): array {
		return [
			'topic' => $topic,
			'id' => $entity_id,
			'attempt' => $attempt,
		];
	}

	protected function isDeletedTopic( string $topic ): bool {
		return substr( $topic, -8 ) === '.deleted';
	}

	/**
	 * A queued product push whose product went to the trash meanwhile: the trash already queued its deletion
	 * (product.deleted), which may even have run first (bulk pushes wait behind it), so the product is not sent after
	 * it. A push made right away (pushNow) is not affected.
	 */
	protected function isTrashedProduct( string $topic, int $entity_id ): bool {
		return strpos( $topic, 'product.' ) === 0 && ! $this->isDeletedTopic( $topic ) && get_post_status( $entity_id ) === 'trash';
	}
}
