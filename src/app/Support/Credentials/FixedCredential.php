<?php

namespace Infixs\SquidCore\Support\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * A token that is not the stored connection: one typed in the form and tested before saving, or the token just issued
 * by the authorization, used before it is stored. A refusal changes nothing stored.
 *
 * @package Infixs\SquidCore
 * @since   1.0.0
 */
class FixedCredential implements CredentialProvider {

	/**
	 * @var string
	 */
	protected $token;

	/**
	 * @var string
	 */
	protected $method;

	/**
	 * @param string $token
	 * @param string $method One of the CredentialProvider::METHOD_* constants.
	 */
	public function __construct( string $token, string $method = self::METHOD_TOKEN ) {
		$this->token = $token;
		$this->method = $method;
	}

	public function token(): string {
		return $this->token;
	}

	public function method(): string {
		return $this->token !== '' ? $this->method : '';
	}

	public function rejected( string $token ): void {
	}
}
