<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices;

use Infixs\SquidCore\Support\SquidCoreUrls;

defined( 'ABSPATH' ) || exit;

/**
 * What the store owner reads about a rejection they can fix (SefazRejection): what probably happened, the steps and
 * the screen of SquidCore where it is fixed. The order box, the dashboard and the order notes all take the texts from
 * here.
 *
 * @package Infixs\SquidCore
 * @since   1.5.0
 */
class SefazRejectionAdvice {

	/**
	 * @var SquidCoreUrls
	 */
	protected $urls;

	public function __construct( SquidCoreUrls $urls ) {
		$this->urls = $urls;
	}

	/**
	 * Advice for the invoice an order holds.
	 *
	 * @return array|null See build(); null when the invoice was not rejected with a known cause.
	 */
	public function forRecord( InvoiceRecord $record ): ?array {
		$rejection = $record->rejection();

		return $rejection ? $this->build( $rejection, $record->number(), $record->series() ) : null;
	}

	/**
	 * Advice for an invoice of the SquidCore list (InvoiceView).
	 *
	 * @param array $row InvoiceView: status, statusSefaz, mensagemSefaz, numero, serie.
	 *
	 * @return array|null See build(); null when the invoice was not rejected with a known cause.
	 */
	public function forRemote( array $row ): ?array {
		if ( ! in_array( strtoupper( (string) ( $row['status'] ?? '' ) ), [ 'ERROR', 'DENIED' ], true ) ) {
			return null;
		}

		$rejection = SefazRejection::classify( self::text( $row['statusSefaz'] ?? '' ), self::text( $row['mensagemSefaz'] ?? '' ) );

		return $rejection ? $this->build( $rejection, self::text( $row['numero'] ?? '' ), self::text( $row['serie'] ?? '' ) ) : null;
	}

	/**
	 * @param string $number Number of the rejected invoice, blank when unknown.
	 * @param string $series Series of the rejected invoice, blank when unknown.
	 *
	 * @return array{kind: string, title: string, cause: string, steps: string[], summary: string, summarySteps: string[], actionUrl: string, actionLabel: string}
	 *               `cause` and `steps` are about this invoice; `summary` and `summarySteps` say the same about several
	 *               invoices of the kind at once, without their numbers, for a single notice about all of them.
	 */
	public function build( SefazRejection $rejection, string $number, string $series ): array {
		switch ( $rejection->kind() ) {
			case SefazRejection::NUMBER_USED:
				$cause = __( 'SEFAZ already has an NF-e with the same number and series, issued by another system — for example, the one the store used before SquidCore.', 'infixs-squidcore-erp' );
				$existing = $rejection->existingInvoice();

				return $this->numberingAdvice(
					$rejection->kind(),
					__( 'The number of this NF-e was already used', 'infixs-squidcore-erp' ),
					$existing ? $cause . ' ' . sprintf(
						/* translators: 1: number of the NF-e SEFAZ already has, 2: its series, 3: month and year it was issued (e.g. "April 2026"). */
						__( 'SEFAZ has NF-e no. %1$s of series %2$s, from %3$s.', 'infixs-squidcore-erp' ),
						$existing['number'],
						$existing['series'],
						self::monthYear( $existing['year'], $existing['month'] )
					) : $cause,
					__( 'SEFAZ already has NF-e with these numbers, issued by another system — for example, the one the store used before SquidCore.', 'infixs-squidcore-erp' )
				);

			case SefazRejection::NUMBER_VOIDED:
				return $this->numberingAdvice(
					$rejection->kind(),
					__( 'This NF-e number was voided', 'infixs-squidcore-erp' ),
					$number !== '' && $series !== '' ? sprintf(
						/* translators: 1: NF-e number, 2: series. */
						__( 'Number %1$s of series %2$s was already voided at SEFAZ, usually by the system the store used before SquidCore. A voided number cannot be used again.', 'infixs-squidcore-erp' ),
						$number,
						$series
					) : __( 'This number was already voided at SEFAZ, usually by the system the store used before SquidCore. A voided number cannot be used again.', 'infixs-squidcore-erp' ),
					__( 'These numbers were already voided at SEFAZ, usually by the system the store used before SquidCore. A voided number cannot be used again.', 'infixs-squidcore-erp' )
				);
		}

		$cause = __( 'This same invoice already reached SEFAZ, for example in an earlier attempt whose answer was lost.', 'infixs-squidcore-erp' );
		$steps = [
			__( 'Click "Refresh" before issuing it again: the invoice may already be authorized.', 'infixs-squidcore-erp' ),
			__( 'If it stays like this, check the invoice in the SquidCore panel.', 'infixs-squidcore-erp' ),
		];

		return [
			'kind' => $rejection->kind(),
			'title' => __( 'SEFAZ already received this NF-e', 'infixs-squidcore-erp' ),
			'cause' => $cause,
			'steps' => $steps,
			'summary' => $cause,
			'summarySteps' => $steps,
			'actionUrl' => $this->urls->appUrl(),
			'actionLabel' => __( 'Open SquidCore', 'infixs-squidcore-erp' ),
		];
	}

	/**
	 * Line added to the order note of the rejection: what to do, in short.
	 */
	public static function noteLine( SefazRejection $rejection ): string {
		if ( $rejection->isNumbering() ) {
			return __( 'Adjust the NF-e numbering in SquidCore (Settings → NF-e numbering) and issue it again.', 'infixs-squidcore-erp' );
		}

		return __( 'Use "Refresh" on the order to fetch the status of the invoice.', 'infixs-squidcore-erp' );
	}

	/**
	 * The two numbering kinds share the steps and the screen: the next number of the series, or a new series. The
	 * last used number counts the voided ones too, since a voiding may go past the last NF-e issued.
	 *
	 * @param string $summary The cause for several invoices of the kind.
	 *
	 * @return array See build().
	 */
	private function numberingAdvice( string $kind, string $title, string $cause, string $summary ): array {
		$fix = [
			__( 'Check in the previous system, or with your accountant, the last number used in this series (an NF-e issued or a voided number).', 'infixs-squidcore-erp' ),
			__( 'In SquidCore, under Settings → NF-e numbering, set "Next number" to the one after the last used. If you prefer, create a series the store never used (for example, series 2 starting at 1) and make it the default.', 'infixs-squidcore-erp' ),
		];

		return [
			'kind' => $kind,
			'title' => $title,
			'cause' => $cause,
			'steps' => array_merge( $fix, [ __( 'Then click "Try again" to issue the invoice.', 'infixs-squidcore-erp' ) ] ),
			'summary' => $summary,
			'summarySteps' => array_merge( $fix, [ __( 'Then click "Try again" on each invoice below.', 'infixs-squidcore-erp' ) ] ),
			'actionUrl' => $this->urls->nfeNumberingUrl(),
			'actionLabel' => __( 'Adjust numbering in SquidCore', 'infixs-squidcore-erp' ),
		];
	}

	/**
	 * Month and year in the language of the site ("abril de 2026" on a Brazilian store). Noon of the 15th in UTC, so
	 * no time zone moves it to another month.
	 */
	private static function monthYear( int $year, int $month ): string {
		/* translators: PHP date format of a month and year, e.g. "F Y" for "April 2026" (pt-BR: "F \d\e Y"). */
		$format = _x( 'F Y', 'month and year of an NF-e', 'infixs-squidcore-erp' );

		return (string) wp_date( $format, (int) gmmktime( 12, 0, 0, $month, 15, $year ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * @param mixed $value
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
