<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe;

use Infixs\SquidCore\Integrations\WooCommerce\Invoices\InvoiceRecord;

defined( 'ABSPATH' ) || exit;

/**
 * What the simplified DANFE of an authorized invoice prints, read once from its XML and kept in the order (about 700
 * bytes), so printing never downloads the XML again. Raw values only: digits, the dates as the XML writes them and
 * the total in cents; no address, email or phone.
 *
 * A failed read is kept too (a negative cache), with the number of attempts and when the next one is due, so a print
 * of many orders does not ask SquidCore again for an XML it could not give.
 *
 * Kept apart from the invoice record, so it never travels in the payload of the invoice actions.
 *
 * @package Infixs\SquidCore
 * @since   1.4.0
 */
class DanfeSnapshot {

	/**
	 * Version of the snapshot; one saved by another version is read again from the XML.
	 */
	public const VERSION = 1;

	public const READY = 'ready';

	public const FAILED = 'failed';

	public const SOURCE_XML = 'xml';

	/**
	 * SquidCore did not give the XML (not available yet, or the invoice not found).
	 */
	public const ERROR_XML_UNAVAILABLE = 'xml_unavailable';

	/**
	 * The XML is not a well-formed authorized NF-e (model 55) with the fields of the DANFE, or breaks the safety limits.
	 */
	public const ERROR_XML_INVALID = 'xml_invalid';

	/**
	 * The XML belongs to another access key.
	 */
	public const ERROR_KEY_MISMATCH = 'key_mismatch';

	/**
	 * The XML has no authorization protocol, or SEFAZ did not authorize it (cStat other than 100 or 150).
	 */
	public const ERROR_NOT_AUTHORIZED = 'not_authorized';

	/**
	 * The download failed (network, SquidCore unavailable, connection refused).
	 */
	public const ERROR_REQUEST_FAILED = 'request_failed';

	/**
	 * Failures a later attempt may overcome; the others stay until the invoice changes.
	 */
	public const RETRYABLE_ERRORS = [ self::ERROR_XML_UNAVAILABLE, self::ERROR_REQUEST_FAILED ];

	/**
	 * Seconds after which a failure a later attempt may overcome is read once more, when its automatic retries ran
	 * out: an outage or an XML SquidCore took long to give does not leave the order without its DANFE for good.
	 */
	public const RETRY_AGAIN_AFTER = 12 * 3600;

	private const PARTY = [
		'name' => '',
		'document' => '',
		'documentType' => '',
		'ie' => '',
		'uf' => '',
	];

	private const IDENTITY = [
		'v' => 0,
		'status' => '',
		'invoiceId' => '',
		'key' => '',
		'source' => '',
	];

	private const READY_FIELDS = [
		'environment' => '',
		'model' => '',
		'series' => '',
		'number' => '',
		'operation' => '',
		'emissionType' => '',
		'issuedAt' => '',
		'protocol' => '',
		'authorizedAt' => '',
		'sefazStatus' => '',
		'emitter' => self::PARTY,
		'recipient' => self::PARTY,
		'total' => null,
		'builtAt' => 0,
	];

	private const FAILED_FIELDS = [
		'error' => '',
		'attempts' => 0,
		'retryAt' => 0,
		'builtAt' => 0,
	];

	/**
	 * @var array
	 */
	private $data;

	/**
	 * @param array $data As saved in the order (toArray()).
	 */
	public function __construct( array $data ) {
		$defaults = self::IDENTITY + ( ( $data['status'] ?? '' ) === self::FAILED ? self::FAILED_FIELDS : self::READY_FIELDS );
		$this->data = array_merge( $defaults, array_intersect_key( $data, $defaults ) );

		foreach ( [ 'emitter', 'recipient' ] as $party ) {
			if ( array_key_exists( $party, $this->data ) ) {
				$this->data[ $party ] = array_merge( self::PARTY, array_intersect_key( (array) $this->data[ $party ], self::PARTY ) );
			}
		}
	}

	/**
	 * Snapshot of an authorized invoice read from its XML.
	 *
	 * @param array $fields DanfeXmlParser::parse().
	 */
	public static function ready( InvoiceRecord $record, array $fields ): self {
		return new self( array_merge( $fields, self::identity( $record, self::READY ), [ 'builtAt' => time() ] ) );
	}

	/**
	 * A read that failed.
	 *
	 * @param string $error    One of the ERROR_* codes.
	 * @param int    $attempts Reads tried for this invoice so far.
	 * @param int    $retryAt  Unix time of the next read, 0 when none follows.
	 */
	public static function failed( InvoiceRecord $record, string $error, int $attempts, int $retryAt ): self {
		return new self( array_merge( self::identity( $record, self::FAILED ), [
			'error' => $error,
			'attempts' => $attempts,
			'retryAt' => $retryAt,
			'builtAt' => time(),
		] ) );
	}

	public function toArray(): array {
		return $this->data;
	}

	public function isReady(): bool {
		return $this->data['status'] === self::READY;
	}

	public function isFailed(): bool {
		return $this->data['status'] === self::FAILED;
	}

	/**
	 * Whether it was built, by this version, from the invoice the order holds now: a new invoice (issued again after
	 * a rejection or a cancellation) makes it stale.
	 */
	public function isFreshFor( InvoiceRecord $record ): bool {
		return (int) $this->data['v'] === self::VERSION
			&& (string) $this->data['invoiceId'] === $record->id()
			&& (string) $this->data['key'] === $record->key();
	}

	/**
	 * A failed read whose next attempt is due: the retry time came, or the automatic retries of a failure a later
	 * attempt may overcome ran out RETRY_AGAIN_AFTER ago.
	 */
	public function isRetryDue( int $now ): bool {
		if ( ! $this->isFailed() ) {
			return false;
		}

		if ( $this->retryAt() > 0 ) {
			return $this->retryAt() <= $now;
		}

		return in_array( $this->error(), self::RETRYABLE_ERRORS, true ) && $this->builtAt() + self::RETRY_AGAIN_AFTER <= $now;
	}

	/**
	 * A failed read no other attempt follows.
	 */
	public function isFinal(): bool {
		return $this->isFailed() && $this->retryAt() === 0;
	}

	public function error(): string {
		return (string) ( $this->data['error'] ?? '' );
	}

	public function attempts(): int {
		return (int) ( $this->data['attempts'] ?? 0 );
	}

	public function retryAt(): int {
		return (int) ( $this->data['retryAt'] ?? 0 );
	}

	/**
	 * Unix time of the read that saved it.
	 */
	public function builtAt(): int {
		return (int) ( $this->data['builtAt'] ?? 0 );
	}

	public function invoiceId(): string {
		return (string) $this->data['invoiceId'];
	}

	public function key(): string {
		return (string) $this->data['key'];
	}

	public function environment(): string {
		return (string) ( $this->data['environment'] ?? '' );
	}

	public function number(): string {
		return (string) ( $this->data['number'] ?? '' );
	}

	public function series(): string {
		return (string) ( $this->data['series'] ?? '' );
	}

	/**
	 * @return string out or in.
	 */
	public function operation(): string {
		return (string) ( $this->data['operation'] ?? '' );
	}

	public function issuedAt(): string {
		return (string) ( $this->data['issuedAt'] ?? '' );
	}

	public function protocol(): string {
		return (string) ( $this->data['protocol'] ?? '' );
	}

	public function authorizedAt(): string {
		return (string) ( $this->data['authorizedAt'] ?? '' );
	}

	/**
	 * @return array{name: string, document: string, documentType: string, ie: string, uf: string}
	 */
	public function emitter(): array {
		return $this->data['emitter'] ?? self::PARTY;
	}

	/**
	 * @return array{name: string, document: string, documentType: string, ie: string, uf: string}
	 */
	public function recipient(): array {
		return $this->data['recipient'] ?? self::PARTY;
	}

	/**
	 * Total of the NF-e in cents, null when the XML had none to read.
	 */
	public function total(): ?int {
		return isset( $this->data['total'] ) ? (int) $this->data['total'] : null;
	}

	/**
	 * @return array{v: int, status: string, invoiceId: string, key: string, source: string}
	 */
	private static function identity( InvoiceRecord $record, string $status ): array {
		return [
			'v' => self::VERSION,
			'status' => $status,
			'invoiceId' => $record->id(),
			'key' => $record->key(),
			'source' => self::SOURCE_XML,
		];
	}
}
