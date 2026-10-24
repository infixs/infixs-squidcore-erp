<?php

namespace Infixs\SquidCore\Integrations\WooCommerce\Products;

use Infixs\SquidCore\Integrations\CorreiosAutomatico\CorreiosAutomatico;

defined( 'ABSPATH' ) || exit;

/**
 * Where the NCM of a product lives: the only class that knows the NCM metas.
 *
 * The SquidCore meta is the one that counts, whenever it exists (even blank, when the store owner cleared it). The
 * meta of Correios Automático is a fallback for products that never had the SquidCore one. While Correios
 * Automático is active, its meta receives a copy of the SquidCore one, so its international shipments keep the same
 * NCM and turning SquidCore off leaves its field with the same value.
 *
 * A third meta (the mirror) keeps the Correios Automático NCM as SquidCore last left or accepted it. A Correios NCM
 * that no longer matches it was changed outside SquidCore (its field while SquidCore was off, a CSV import, the
 * block editor): that change is brought into the SquidCore meta instead of being overwritten. Products saved before
 * the mirror existed follow the rule of version 1.2.1, where a valid Correios NCM won.
 *
 * @package Infixs\SquidCore
 * @since   1.3.0
 */
class ProductNcmStore {

	public const META_KEY = '_infixs_squidcore_ncm';

	public const CORREIOS_META_KEY = '_infixs_correios_automatico_ncm';

	/**
	 * Digits of the Correios Automático NCM when SquidCore last wrote it or accepted it.
	 */
	public const MIRROR_META_KEY = '_infixs_squidcore_ncm_mirror';

	/**
	 * @var CorreiosAutomatico
	 */
	protected $correios;

	/**
	 * Products whose metas this request already put in step (no need to look at them again).
	 *
	 * @var array<int, true>
	 */
	private $synced = [];

	/**
	 * Correios Automático NCM of the products the editor is saving, as it was before the save: the hidden Correios
	 * input posts the value of when the form was built, which may be older.
	 *
	 * @var array<int, string|null>
	 */
	private $correiosBeforeSave = [];

	public function __construct( CorreiosAutomatico $correios ) {
		$this->correios = $correios;
	}

	/**
	 * NCM that goes on the invoice, digits only ('' when none). A variation without one uses its parent's.
	 */
	public function effective( \WC_Product $product ): string {
		$ncm = $this->state( $product )->value();

		if ( $ncm !== '' || $product->get_parent_id() <= 0 ) {
			return $ncm;
		}

		$parent = wc_get_product( $product->get_parent_id() );

		return $parent instanceof \WC_Product ? $this->state( $parent )->value() : '';
	}

	/**
	 * NCM of the product itself (no fallback to a parent) and the metas it comes from.
	 */
	public function state( \WC_Product $product ): NcmState {
		return $this->stateFrom( $this->rawMeta( $product, self::META_KEY ), $this->rawMeta( $product, self::CORREIOS_META_KEY ) );
	}

	/**
	 * Same as state(), read from the post metas, for code that goes through many products without loading them (the
	 * caller primes the meta cache).
	 */
	public function stateOf( int $productId ): NcmState {
		return $this->stateFrom( $this->postMeta( $productId, self::META_KEY ), $this->postMeta( $productId, self::CORREIOS_META_KEY ) );
	}

	/**
	 * The state given by the two metas as stored (null when one does not exist), e.g. as they were before a write.
	 */
	public function stateFrom( ?string $own, ?string $correios ): NcmState {
		if ( $own !== null ) {
			return new NcmState( NcmCode::normalize( $own ), $own, $correios, false, $this->correios->isActive() );
		}

		$correios_digits = NcmCode::normalize( (string) $correios );

		return new NcmState( $correios_digits, null, $correios, $correios_digits !== '', $this->correios->isActive() );
	}

	/**
	 * Value the NCM field shows: the SquidCore meta when it exists, otherwise the Correios Automático one as typed.
	 */
	public function displayValue( \WC_Product $product ): string {
		$own = $this->rawMeta( $product, self::META_KEY );

		return $own ?? (string) $this->rawMeta( $product, self::CORREIOS_META_KEY );
	}

	/**
	 * Sets the NCM of the product (digits only; blank clears it) and, while Correios Automático is active, its copy in
	 * the Correios meta. The caller saves the product metas (save_meta_data()).
	 */
	public function write( \WC_Product $product, string $ncm ): void {
		$digits = NcmCode::normalize( $ncm );

		$product->update_meta_data( self::META_KEY, $digits );

		if ( $this->correios->isActive() ) {
			$product->update_meta_data( self::CORREIOS_META_KEY, $digits );
			$product->update_meta_data( self::MIRROR_META_KEY, $digits );
		} else {
			$this->noteCorreiosSeen( $product );
		}

		$this->synced[ $product->get_id() ] = true;
	}

	/**
	 * Sets only the SquidCore meta (digits only; blank clears it), for the product editor: Correios Automático saves
	 * its own field in the same request, so the copy waits for syncAfterSave(). The caller saves the product.
	 */
	public function writeOwn( \WC_Product $product, string $ncm ): void {
		$product->update_meta_data( self::META_KEY, NcmCode::normalize( $ncm ) );
		$this->synced[ $product->get_id() ] = true;
	}

	/**
	 * Puts back the metas a write replaced, unless the NCM changed since then. The caller saves the product metas.
	 *
	 * @param string|null $own      SquidCore meta before the write, null when it did not exist.
	 * @param string|null $correios Correios Automático meta before the write, null when it did not exist.
	 * @param string      $after    NCM the write left (digits).
	 *
	 * @return bool False, with nothing changed, when the SquidCore meta no longer holds $after.
	 */
	public function restore( \WC_Product $product, ?string $own, ?string $correios, string $after ): bool {
		if ( $this->rawMeta( $product, self::META_KEY ) !== $after ) {
			return false;
		}

		$this->restoreMeta( $product, self::META_KEY, $own );

		if ( $this->rawMeta( $product, self::CORREIOS_META_KEY ) === $after ) {
			$this->restoreMeta( $product, self::CORREIOS_META_KEY, $correios );
			$this->restoreMeta( $product, self::MIRROR_META_KEY, $correios !== null ? NcmCode::normalize( $correios ) : null );
		}

		$this->synced[ $product->get_id() ] = true;

		return true;
	}

	/**
	 * Notes the Correios Automático NCM of a product the editor starts saving, before any field of the form is saved.
	 */
	public function beginEditorSave( int $productId ): void {
		$this->correiosBeforeSave[ $productId ] = $this->postMeta( $productId, self::CORREIOS_META_KEY );
	}

	/**
	 * Runs after the product editor saved everything (Correios Automático included), reading the metas fresh:
	 * - a product without the SquidCore meta adopts a valid Correios Automático NCM;
	 * - while Correios Automático is active, its meta gets the SquidCore NCM back, whatever its (hidden) field
	 *   posted, unless the Correios NCM had been changed outside SquidCore before this save and the store owner did
	 *   not touch the field: that change is adopted.
	 *
	 * @param bool $ownEdited The store owner changed the SquidCore field in this save.
	 */
	public function syncAfterSave( int $productId, bool $ownEdited = false ): void {
		$before = array_key_exists( $productId, $this->correiosBeforeSave ) ? [ $this->correiosBeforeSave[ $productId ] ] : null;
		unset( $this->correiosBeforeSave[ $productId ] );

		$this->sync( $productId, $ownEdited, true, $before );
	}

	/**
	 * Puts the metas of a product in step after a change made outside SquidCore (or before the mirror existed). While
	 * Correios Automático is active, a Correios NCM changed elsewhere is adopted (as is a valid one on a product without
	 * the SquidCore meta), otherwise the SquidCore NCM is copied to Correios. While it is off, the SquidCore NCM wins and
	 * the value left in the Correios meta is only noted as seen. Reads the metas fresh.
	 *
	 * @return bool Whether the SquidCore NCM changed.
	 */
	public function reconcile( int $productId ): bool {
		return $this->sync( $productId, false, false );
	}

	/**
	 * This request already put the metas of the product in step.
	 */
	public function isSynced( int $productId ): bool {
		return isset( $this->synced[ $productId ] );
	}

	/**
	 * @param bool       $ownEdited The SquidCore NCM was just set by the store owner: it wins.
	 * @param bool       $editor    The product editor saved the product: a product without the SquidCore meta adopts
	 *                              a valid Correios NCM even with Correios Automático inactive (the field showed it),
	 *                              and the Correios copy is written as the SquidCore one is stored (digits). Outside
	 *                              the editor, the Correios meta is only written when its NCM differs.
	 * @param array|null $before    [Correios meta before the editor saved], null outside the editor.
	 *
	 * @return bool Whether the SquidCore NCM changed.
	 */
	private function sync( int $productId, bool $ownEdited, bool $editor, ?array $before = null ): bool {
		$this->synced[ $productId ] = true;

		$own = $this->postMeta( $productId, self::META_KEY );
		$correios = $this->postMeta( $productId, self::CORREIOS_META_KEY );
		$mirror = $this->postMeta( $productId, self::MIRROR_META_KEY );
		// The value the store had: in the editor, the one before its hidden Correios input was saved.
		$correios_digits = NcmCode::normalize( (string) ( $before !== null ? $before[0] : $correios ) );
		$active = $this->correios->isActive();

		if ( $own === null || ( $active && ! $ownEdited && $this->changedOutside( $own, $correios_digits, $mirror ) ) ) {
			if ( ! NcmCode::isValid( $correios_digits ) || ( $own === null && ! $active && ! $editor ) ) {
				return false;
			}

			update_post_meta( $productId, self::META_KEY, $correios_digits );
			update_post_meta( $productId, self::MIRROR_META_KEY, $correios_digits );

			if ( $active ) {
				$this->copyToCorreios( $productId, $correios, $correios_digits, $editor );
			}

			return true;
		}

		if ( $active ) {
			$digits = NcmCode::normalize( $own );
			$this->copyToCorreios( $productId, $correios, $digits, $editor );

			if ( $mirror !== $digits ) {
				update_post_meta( $productId, self::MIRROR_META_KEY, $digits );
			}
		} elseif ( $correios !== null && $mirror !== $correios_digits ) {
			// Correios Automático is off: the SquidCore NCM wins over the one left in its meta, which is noted as seen.
			update_post_meta( $productId, self::MIRROR_META_KEY, $correios_digits );
		}

		return false;
	}

	/**
	 * Writes the SquidCore NCM into the Correios meta: in the editor whenever it is stored differently (its hidden
	 * input may have posted anything), elsewhere only when the code differs.
	 */
	private function copyToCorreios( int $productId, ?string $correios, string $digits, bool $editor ): void {
		$differs = $editor ? $correios !== $digits : NcmCode::normalize( (string) $correios ) !== $digits || $correios === null;

		if ( $differs ) {
			update_post_meta( $productId, self::CORREIOS_META_KEY, $digits );
		}
	}

	/**
	 * The Correios NCM holds a valid code the SquidCore one does not have, and it is not the one SquidCore last left
	 * there, while the SquidCore NCM is still that one: it was changed outside SquidCore. Without a mirror (saved before
	 * it existed), a valid Correios NCM wins, as in version 1.2.1.
	 */
	private function changedOutside( string $own, string $correiosDigits, ?string $mirror ): bool {
		if ( ! NcmCode::isValid( $correiosDigits ) || $correiosDigits === NcmCode::normalize( $own ) ) {
			return false;
		}

		return $mirror === null || ( $correiosDigits !== $mirror && NcmCode::normalize( $own ) === $mirror );
	}

	/**
	 * Correios Automático is off and the SquidCore NCM is written over what its meta holds: that value is noted as
	 * seen, so it does not count as a change made outside SquidCore when Correios Automático comes back.
	 */
	private function noteCorreiosSeen( \WC_Product $product ): void {
		$correios = $this->rawMeta( $product, self::CORREIOS_META_KEY );

		if ( $correios !== null ) {
			$product->update_meta_data( self::MIRROR_META_KEY, NcmCode::normalize( $correios ) );
		}
	}

	/**
	 * Stored value of a meta of the product object, null when it does not exist.
	 */
	private function rawMeta( \WC_Product $product, string $key ): ?string {
		if ( ! $product->meta_exists( $key ) ) {
			return null;
		}

		$value = $product->get_meta( $key, true, 'edit' );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Stored value of a post meta, null when it does not exist.
	 */
	private function postMeta( int $productId, string $key ): ?string {
		if ( ! metadata_exists( 'post', $productId, $key ) ) {
			return null;
		}

		$value = get_post_meta( $productId, $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}

	private function restoreMeta( \WC_Product $product, string $key, ?string $value ): void {
		if ( $value === null ) {
			$product->delete_meta_data( $key );
			return;
		}

		$product->update_meta_data( $key, $value );
	}
}
