<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Invoices\Danfe;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what the simplified DANFE (DANFE Simplificado - Etiqueta) prints from the authorized XML of an NF-e
 * (procNFe: the NF-e and its authorization protocol).
 *
 * The XML comes from SquidCore, but it is still read as untrusted input: no network access, no document type (so no
 * entity of any kind), at most 1 MB, and only the fields of the DANFE are read, through XPath on the NF-e namespace.
 * Values are kept raw (digits, ISO-8601 dates as written, the total in cents): formatting is up to whoever prints them.
 *
 * @package Infixs\SquidCore
 * @since   1.4.0
 */
class DanfeXmlParser {

	/**
	 * Largest XML accepted, in bytes. An NF-e with hundreds of items stays far below it.
	 */
	public const MAX_BYTES = 1048576;

	private const NAMESPACE_NFE = 'http://www.portalfiscal.inf.br/nfe';

	/**
	 * SEFAZ statuses of an authorized NF-e: authorized (100) and authorized out of time (150).
	 */
	private const AUTHORIZED_STATUSES = [ '100', '150' ];

	/**
	 * Model of the NF-e; 65 is the NFC-e, which has a DANFE of its own.
	 */
	private const MODEL_NFE = '55';

	/**
	 * Largest name kept, the size of xNome in the NF-e layout.
	 */
	private const NAME_MAX_LENGTH = 60;

	private const PROTOCOL_PATTERN = '/^\d{15}$/';

	/**
	 * Date and time with seconds and an optional offset, as the NF-e writes dhEmi and dhRecbto.
	 */
	private const DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/';

	/**
	 * @param string $xml Authorized XML (procNFe) as downloaded.
	 * @param string $key Access key (44 digits) the XML must belong to.
	 *
	 * @return array|\WP_Error The fields of the snapshot (environment, model, series, number, operation, emissionType,
	 *                         issuedAt, protocol, authorizedAt, sefazStatus, emitter, recipient, total), or an error
	 *                         whose code is DanfeSnapshot::ERROR_XML_INVALID, ERROR_KEY_MISMATCH or
	 *                         ERROR_NOT_AUTHORIZED.
	 */
	public function parse( string $xml, string $key ) {
		$xpath = $this->load( $xml );

		if ( ! $xpath ) {
			return new \WP_Error( DanfeSnapshot::ERROR_XML_INVALID );
		}

		$inf_nfe = $this->node( $xpath, '/nfe:nfeProc/nfe:NFe/nfe:infNFe' );

		if ( ! $inf_nfe ) {
			// An NF-e that was signed but never authorized comes without the protocol around it.
			return new \WP_Error( $this->node( $xpath, '/nfe:NFe/nfe:infNFe' ) ? DanfeSnapshot::ERROR_NOT_AUTHORIZED : DanfeSnapshot::ERROR_XML_INVALID );
		}

		if ( ! preg_match( '/^\d{44}$/', $key ) || $inf_nfe->getAttribute( 'Id' ) !== 'NFe' . $key ) {
			return new \WP_Error( DanfeSnapshot::ERROR_KEY_MISMATCH );
		}

		$ide = '/nfe:nfeProc/nfe:NFe/nfe:infNFe/nfe:ide/nfe:';

		if ( $this->text( $xpath, $ide . 'mod' ) !== self::MODEL_NFE ) {
			return new \WP_Error( DanfeSnapshot::ERROR_XML_INVALID );
		}

		$protocol = '/nfe:nfeProc/nfe:protNFe/nfe:infProt/nfe:';

		if ( ! $this->node( $xpath, '/nfe:nfeProc/nfe:protNFe/nfe:infProt' ) || ! in_array( $this->text( $xpath, $protocol . 'cStat' ), self::AUTHORIZED_STATUSES, true ) ) {
			return new \WP_Error( DanfeSnapshot::ERROR_NOT_AUTHORIZED );
		}

		if ( $this->digits( $this->text( $xpath, $protocol . 'chNFe' ) ) !== $key ) {
			return new \WP_Error( DanfeSnapshot::ERROR_KEY_MISMATCH );
		}

		$fields = [
			'environment' => $this->environment( $this->text( $xpath, $protocol . 'tpAmb' ), $this->text( $xpath, $ide . 'tpAmb' ) ),
			'model' => self::MODEL_NFE,
			'series' => $this->digits( $this->text( $xpath, $ide . 'serie' ) ),
			'number' => $this->digits( $this->text( $xpath, $ide . 'nNF' ) ),
			'operation' => $this->operation( $this->text( $xpath, $ide . 'tpNF' ) ),
			'emissionType' => $this->digits( $this->text( $xpath, $ide . 'tpEmis' ) ),
			'issuedAt' => $this->text( $xpath, $ide . 'dhEmi' ),
			'protocol' => $this->text( $xpath, $protocol . 'nProt' ),
			'authorizedAt' => $this->text( $xpath, $protocol . 'dhRecbto' ),
			'sefazStatus' => $this->text( $xpath, $protocol . 'cStat' ),
			'emitter' => $this->party( $xpath, '/nfe:nfeProc/nfe:NFe/nfe:infNFe/nfe:emit', 'enderEmit' ),
			'recipient' => $this->party( $xpath, '/nfe:nfeProc/nfe:NFe/nfe:infNFe/nfe:dest', 'enderDest' ),
			'total' => $this->cents( $this->text( $xpath, '/nfe:nfeProc/nfe:NFe/nfe:infNFe/nfe:total/nfe:ICMSTot/nfe:vNF' ) ),
		];

		return $this->isComplete( $fields ) ? $fields : new \WP_Error( DanfeSnapshot::ERROR_XML_INVALID );
	}

	/**
	 * The document ready for XPath, or null when it is not a well-formed XML within the limits.
	 */
	private function load( string $xml ): ?\DOMXPath {
		if ( $xml === '' || strlen( $xml ) > self::MAX_BYTES || ! class_exists( \DOMDocument::class ) ) {
			return null;
		}

		// Entities, internal or external, need a document type: none is accepted, before or after parsing.
		if ( stripos( $xml, '<!DOCTYPE' ) !== false || stripos( $xml, '<!ENTITY' ) !== false ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$loaded = $dom->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || $dom->doctype !== null ) {
			return null;
		}

		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'nfe', self::NAMESPACE_NFE );

		return $xpath;
	}

	/**
	 * Every field the DANFE must print, in the expected shape.
	 */
	private function isComplete( array $fields ): bool {
		$emitter = $fields['emitter'];

		return $fields['series'] !== ''
			&& $fields['number'] !== ''
			&& $fields['operation'] !== ''
			&& preg_match( self::PROTOCOL_PATTERN, $fields['protocol'] )
			&& preg_match( self::DATE_TIME_PATTERN, $fields['issuedAt'] )
			&& preg_match( self::DATE_TIME_PATTERN, $fields['authorizedAt'] )
			&& in_array( $emitter['documentType'], [ 'cnpj', 'cpf' ], true )
			&& $emitter['name'] !== ''
			&& $emitter['uf'] !== '';
	}

	/**
	 * Issuer or recipient: name, document (CNPJ, CPF or the foreign id), state registration and state.
	 *
	 * @param string $base    XPath of the emit or dest element.
	 * @param string $address Name of its address element.
	 *
	 * @return array{name: string, document: string, documentType: string, ie: string, uf: string}
	 */
	private function party( \DOMXPath $xpath, string $base, string $address ): array {
		$cnpj = $this->digits( $this->text( $xpath, $base . '/nfe:CNPJ' ) );
		$cpf = $this->digits( $this->text( $xpath, $base . '/nfe:CPF' ) );
		$document = '';
		$type = '';

		if ( strlen( $cnpj ) === 14 ) {
			$document = $cnpj;
			$type = 'cnpj';
		} elseif ( strlen( $cpf ) === 11 ) {
			$document = $cpf;
			$type = 'cpf';
		} elseif ( $this->node( $xpath, $base . '/nfe:idEstrangeiro' ) ) {
			// A foreign buyer may have no id at all; the element still tells the recipient is foreign.
			$document = substr( (string) preg_replace( '/[^0-9A-Za-z]/', '', $this->text( $xpath, $base . '/nfe:idEstrangeiro' ) ), 0, 20 );
			$type = 'foreign';
		}

		$uf = strtoupper( $this->text( $xpath, $base . '/nfe:' . $address . '/nfe:UF' ) );

		return [
			'name' => $this->name( $this->text( $xpath, $base . '/nfe:xNome' ) ),
			'document' => $document,
			'documentType' => $type,
			'ie' => strtoupper( (string) preg_replace( '/[^0-9A-Za-z]/', '', $this->text( $xpath, $base . '/nfe:IE' ) ) ),
			'uf' => preg_match( '/^[A-Z]{2}$/', $uf ) ? $uf : '',
		];
	}

	/**
	 * Environment of the authorization (tpAmb of the protocol, else of the NF-e): 1 is production, 2 homologation.
	 */
	private function environment( string $protocol, string $invoice ): string {
		$environment = $protocol !== '' ? $protocol : $invoice;

		return $environment === '1' ? 'production' : 'homologation';
	}

	/**
	 * Type of operation (tpNF): 1 is an outgoing NF-e, 0 an incoming one; blank for anything else.
	 */
	private function operation( string $type ): string {
		if ( $type === '1' ) {
			return 'out';
		}

		return $type === '0' ? 'in' : '';
	}

	/**
	 * A decimal value of the XML ("129.90") in integer cents, read as text so no float rounding gets in; null when it
	 * is not a plain amount (the total is optional on the DANFE).
	 */
	private function cents( string $value ): ?int {
		if ( ! preg_match( '/^(\d{1,13})(?:\.(\d{1,2}))?$/', $value, $matches ) ) {
			return null;
		}

		return (int) $matches[1] * 100 + (int) str_pad( $matches[2] ?? '', 2, '0' );
	}

	/**
	 * Plain text on one line, cut to the size of the NF-e names.
	 */
	private function name( string $value ): string {
		$text = trim( (string) preg_replace( '/[\s\x00-\x1F\x7F]+/u', ' ', wp_strip_all_tags( $value ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::NAME_MAX_LENGTH ) : substr( $text, 0, self::NAME_MAX_LENGTH );
	}

	private function node( \DOMXPath $xpath, string $query ): ?\DOMNode {
		$nodes = $xpath->query( $query );

		return $nodes instanceof \DOMNodeList && $nodes->length > 0 ? $nodes->item( 0 ) : null;
	}

	private function text( \DOMXPath $xpath, string $query ): string {
		$node = $this->node( $xpath, $query );

		return $node ? trim( (string) $node->textContent ) : '';
	}

	private function digits( string $value ): string {
		return (string) preg_replace( '/\D+/', '', $value );
	}
}
