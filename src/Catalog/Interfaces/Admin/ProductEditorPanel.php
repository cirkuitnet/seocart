<?php
/**
 * ProductEditorPanel: the Product data area of the product editor, and what it is given
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Interfaces\Admin;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Catalog\Application\ProductWrite\CommerceFields;
use SEOCart\Catalog\Application\ProductWrite\VariantFields;
use SEOCart\Catalog\Domain\SellabilityReason;
use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Catalog\Interfaces\Rest\ProductCommerceSchema;
use SEOCart\Inventory\Application\InventoryOperations;
use SEOCart\Inventory\Application\LocationOperations;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Localization\PostLocales;
use SEOCart\Support\Currency;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the product editor's script and stylesheet on the product editor, and nowhere else.
 *
 * Owns one fact: what the script is given. The script registers the Product data block and edits
 * the `seocart` object of the product's REST resource through the editor's own entity, so the
 * block editor sends it in the same request as the title and the content. Everything it shows
 * comes from here, from the declarations: the option axes' and the variants' fields with their
 * labels and bounds, the currencies the store offers with their numbers of decimal places, so an
 * amount the merchant types becomes minor units without a float, where it reads the locations and
 * the stock and whether the user may, a plain sentence for every verdict, and what a new
 * translation's first save sends: the fields in which it names the post it translates and its
 * locale, which the script reads from the multilingual plugin's link, and the locale of each
 * language code such a link may name.
 *
 * The script is enqueued on `enqueue_block_editor_assets` and the stylesheet on
 * `enqueue_block_assets`, from which WordPress copies it into the editor's canvas, each only when
 * the screen edits a product, so every other screen, the post editor included, loads none of
 * their bytes. A checkout without a build enqueues nothing.
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
	 * The compiled stylesheet, relative to the plugin's directory; the build writes its right-to-left copy beside it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const STYLESHEET = 'build/admin/style-product-editor.css';

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
	 * Names the languages a new translation's link may ask for.
	 *
	 * @since 0.1.0
	 *
	 * @var PostLocales
	 */
	private PostLocales $locales;

	/**
	 * Returns the currencies the store offers, its base currency among them.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(): list<Currency>
	 */
	private $offeredCurrencies;

	/**
	 * Creates the panel. Reads nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $pluginFile   The main plugin file.
	 * @param callable    $baseCurrency Returns the store's base currency (a Currency).
	 * @param PostLocales $locales      Names the languages a new translation's link may ask for.
	 * @param callable    $offered      Returns the currencies the store offers (a list of Currency), the base among them.
	 *
	 * @phpstan-param callable(): Currency       $baseCurrency
	 * @phpstan-param callable(): list<Currency> $offered
	 */
	public function __construct( string $pluginFile, callable $baseCurrency, PostLocales $locales, callable $offered ) {
		$this->pluginFile        = $pluginFile;
		$this->baseCurrency      = $baseCurrency;
		$this->locales           = $locales;
		$this->offeredCurrencies = $offered;
	}

	/**
	 * Enqueues the script when the block editor edits a product. Hooked to `enqueue_block_editor_assets`.
	 *
	 * @since 0.1.0
	 */
	public function enqueue(): void {
		$asset = $this->buildOnTheProductEditor();

		if ( null === $asset ) {
			return;
		}

		$directory = plugin_dir_path( $this->pluginFile );

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
	 * Enqueues the stylesheet when the block editor edits a product. Hooked to `enqueue_block_assets`, which WordPress also fires to collect what the editor's canvas loads.
	 *
	 * @since 0.2.0
	 */
	public function enqueueStyles(): void {
		$asset = $this->buildOnTheProductEditor();

		if ( null === $asset ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( self::STYLESHEET, $this->pluginFile ), array(), (string) ( $asset['version'] ?? '' ) );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * Returns the build's list of the script's dependencies and version, when the screen edits a product and a build exists.
	 *
	 * @since 0.2.0
	 *
	 * @return array<mixed>|null The list, or null on any other screen and in a checkout without a build.
	 */
	private function buildOnTheProductEditor(): ?array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || ProductCapabilities::POST_TYPE !== $screen->post_type ) {
			return null;
		}

		$file  = plugin_dir_path( $this->pluginFile ) . self::ASSET;
		$asset = is_readable( $file ) ? wp_json_file_decode( $file, array( 'associative' => true ) ) : null;

		return is_array( $asset ) ? $asset : null;
	}

	/**
	 * Returns what the panel is given.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The post type, the property, the block, the base currency and every currency the store offers, the
	 *                              option axes' and the variants' lists with the fields of their items, where the stock is read and
	 *                              whether the user may, the verdicts' sentences, and the two fields of a new translation's first
	 *                              save with the locale of each language code.
	 */
	public function settings(): array {
		$base        = ( $this->baseCurrency )();
		$sellability = null;

		foreach ( ProductCommerceSchema::fields() as $field ) {
			if ( ProductCommerceSchema::SELLABILITY === $field->name() ) {
				$sellability = ( $field->label() )();
			}
		}

		return array(
			'postType'    => ProductCapabilities::POST_TYPE,
			'property'    => ProductCommerceSchema::PROPERTY,
			'block'       => ProductPostType::AREA_BLOCK,
			'currency'    => array(
				'code'     => $base->code(),
				'exponent' => $base->exponent(),
			),
			'currencies'  => array_map(
				static fn( Currency $currency ): array => array(
					'code'     => $currency->code(),
					'exponent' => $currency->exponent(),
				),
				( $this->offeredCurrencies )()
			),
			'lists'       => array_map( static fn( FieldSpec $declaration ): array => self::field( $declaration ), array_values( VariantFields::all() ) ),
			'stock'       => array(
				'canManage'     => current_user_can( InventoryOperations::CAPABILITY ),
				'locationsPath' => RestBinding::NAMESPACE . LocationOperations::ROUTE,
				'stockPath'     => RestBinding::NAMESPACE . LocationOperations::STOCK_ROUTE,
				'mostListed'    => LocationOperations::MOST_LISTED_VARIANTS,
			),
			'sellability' => array(
				'name'    => ProductCommerceSchema::SELLABILITY,
				'label'   => $sellability,
				'reasons' => self::reasons(),
			),
			'translation' => array(
				'field'     => ProductCommerceSchema::TRANSLATION_OF,
				'locale'    => ProductCommerceSchema::LOCALE,
				'languages' => array_map( static fn( $locale ): string => $locale->toString(), $this->locales->languages() ),
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
	 * Describes one field for the script.
	 *
	 * @since 0.1.0
	 *
	 * @param FieldSpec $field The field.
	 * @return array<string, mixed> Its name, its label, the kind of control it takes, and its bounds; a list also its most items and the fields of an item.
	 */
	private static function field( FieldSpec $field ): array {
		$kind = match ( true ) {
			in_array( $field->name(), self::AMOUNTS, true ) => 'amount',
			FieldType::Integer === $field->type()           => 'integer',
			FieldType::Boolean === $field->type()           => 'boolean',
			FieldType::ObjectList === $field->type()        => 'list',
			default                                         => 'text',
		};

		$described = array(
			'name'     => $field->name(),
			'label'    => ( $field->label() )(),
			'kind'     => $kind,
			'nullable' => $field->isNullable(),
			'maxChars' => $field->maxLength(),
			'minimum'  => $field->minimum(),
			'maximum'  => $field->maximum(),
		);

		if ( FieldType::ObjectList === $field->type() ) {
			$described['maxItems'] = $field->maxItems();
			$described['fields']   = array_map( static fn( FieldSpec $member ): array => self::field( $member ), $field->fields() );
		}

		return $described;
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
			'not_translated'        => __( 'Not for sale in this language: the product has no post in it.', 'seocart' ),
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
