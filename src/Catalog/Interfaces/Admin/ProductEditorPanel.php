<?php
/**
 * ProductEditorPanel: the commerce panel of the product editor, and what it is given
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Admin;

use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Support\Currency;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the product editor's commerce panel on the product editor, and nowhere else.
 *
 * Owns one fact: what the panel is given. The panel edits the `seocart` object of the product's
 * REST resource through the editor's own entity, so the block editor sends it in the same
 * request as the title and the content. Everything it shows comes from here, from the
 * declarations: each writable commerce field with its label, the base currency with its number
 * of decimal places, so an amount the merchant types becomes minor units without a float, and a
 * plain sentence for every verdict. The currency is not a field of the panel: a price is always in
 * the base currency.
 *
 * The script is enqueued on `enqueue_block_editor_assets` only when the screen edits a product,
 * so every other screen, the post editor included, loads none of its bytes. A checkout without a
 * build enqueues nothing.
 *
 * @since 0.1.0
 */
final class ProductEditorPanel {

	/**
	 * The script's handle.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HANDLE = 'seocart-product-editor';

	/**
	 * The global the panel reads what it is given from.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SETTINGS_GLOBAL = 'seocartProductEditor';

	/**
	 * The compiled script, relative to the plugin's directory.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SCRIPT = 'build/admin/product-editor.js';

	/**
	 * The build's list of the script's dependencies and version, as JSON, relative to the plugin's directory.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ASSET = 'build/admin/product-editor.asset.json';

	/**
	 * The commerce fields that hold an amount in minor units of the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const AMOUNTS = array( CommerceFields::PRICE_MINOR, CommerceFields::COMPARE_AT_MINOR );

	/**
	 * The main plugin file, which the script's address and path are resolved from.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $pluginFile;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): Currency
	 */
	private $baseCurrency;

	/**
	 * Creates the panel. Reads nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $pluginFile   The main plugin file.
	 * @param callable $baseCurrency Returns the store's base currency (a Currency).
	 *
	 * @phpstan-param callable(): Currency $baseCurrency
	 */
	public function __construct( string $pluginFile, callable $baseCurrency ) {
		$this->pluginFile   = $pluginFile;
		$this->baseCurrency = $baseCurrency;
	}

	/**
	 * Enqueues the panel when the block editor edits a product. Hooked to `enqueue_block_editor_assets`.
	 *
	 * @since 0.1.0
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || ProductCapabilities::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$directory = plugin_dir_path( $this->pluginFile );
		$asset     = is_readable( $directory . self::ASSET ) ? wp_json_file_decode( $directory . self::ASSET, array( 'associative' => true ) ) : null;

		if ( ! is_array( $asset ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( self::SCRIPT, $this->pluginFile ),
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? '' ),
			array( 'in_footer' => true )
		);
		wp_add_inline_script( self::HANDLE, sprintf( 'var %s = %s;', self::SETTINGS_GLOBAL, (string) wp_json_encode( $this->settings() ) ), 'before' );
		wp_set_script_translations( self::HANDLE, 'seocart', $directory . 'languages' );
	}

	/**
	 * Returns what the panel is given.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The post type, the property, the panel's title, the base currency, the fields and the verdicts' sentences.
	 */
	public function settings(): array {
		$base   = ( $this->baseCurrency )();
		$fields = array();

		foreach ( CommerceFields::all() as $name => $field ) {
			if ( CommerceFields::CURRENCY !== $name ) {
				$fields[] = self::field( $field );
			}
		}

		$sellability = null;

		foreach ( ProductCommerceSchema::fields() as $field ) {
			if ( ProductCommerceSchema::SELLABILITY === $field->name() ) {
				$sellability = ( $field->label() )();
			}
		}

		return array(
			'postType'    => ProductCapabilities::POST_TYPE,
			'property'    => ProductCommerceSchema::PROPERTY,
			'title'       => __( 'Commerce', 'seocart' ),
			'currency'    => array(
				'code'     => $base->code(),
				'exponent' => $base->exponent(),
			),
			'fields'      => $fields,
			'sellability' => array(
				'name'    => ProductCommerceSchema::SELLABILITY,
				'label'   => $sellability,
				'reasons' => self::reasons(),
			),
		);
	}

	/**
	 * Returns a plain sentence for every verdict, keyed by its wire value.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> One sentence per case of SellabilityReason.
	 */
	public static function reasons(): array {
		$reasons = array();

		foreach ( SellabilityReason::cases() as $reason ) {
			$reasons[ $reason->value ] = self::reason( $reason->value );
		}

		return $reasons;
	}

	/**
	 * Describes one field for the panel.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec $field The field.
	 * @return array<string, mixed> Its name, its label, the kind of control it takes, and its bounds.
	 */
	private static function field( FieldSpec $field ): array {
		if ( in_array( $field->name(), self::AMOUNTS, true ) ) {
			$kind = 'amount';
		} else {
			$kind = FieldType::Integer === $field->type() ? 'integer' : 'text';
		}

		return array(
			'name'     => $field->name(),
			'label'    => ( $field->label() )(),
			'kind'     => $kind,
			'nullable' => $field->isNullable(),
			'maxChars' => $field->maxLength(),
			'minimum'  => $field->minimum(),
		);
	}

	/**
	 * Returns the sentence of one verdict.
	 *
	 * Every case of SellabilityReason has an arm, which reasons() proves by asking for each; a case
	 * added without one fails there.
	 *
	 * @since 0.1.0
	 *
	 * @throws \UnexpectedValueException When a verdict has no sentence.
	 *
	 * @param string $reason The verdict's wire value.
	 * @return string The sentence.
	 */
	private static function reason( string $reason ): string {
		return match ( $reason ) {
			'sellable'              => __( 'For sale.', 'seocart' ),
			'incomplete'            => __( 'Not for sale: the product needs a SKU and a price.', 'seocart' ),
			'updating'              => __( 'Not for sale while it is being saved.', 'seocart' ),
			'no_binding'            => __( 'Not for sale until it is saved with a SKU and a price.', 'seocart' ),
			'no_base_price'         => __( 'Not for sale: the product has no price.', 'seocart' ),
			'not_active_generation' => __( 'Not for sale while its variants are being rebuilt.', 'seocart' ),
			'variant_disabled'      => __( 'Not for sale: the product is disabled.', 'seocart' ),
			'not_published'         => __( 'Not for sale until it is published.', 'seocart' ),
			'private'               => __( 'For sale only to people who may read private products.', 'seocart' ),
			'unknown_variant'       => __( 'Not for sale: the product has no variant.', 'seocart' ),
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A developer's message naming a verdict; it is never rendered.
			default                 => throw new \UnexpectedValueException( sprintf( 'The verdict %s has no sentence; add one here.', $reason ) ),
		};
	}
}
