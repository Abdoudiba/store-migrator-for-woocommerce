<?php
/**
 * The "product" batch processor: reads a slice of the CSV index and creates or
 * updates the matching WooCommerce products.
 *
 * Idempotency: the CSV carries no Shopify product ID, so the Handle is the
 * source key (products) and Handle + SKU (or Handle + option values) is the
 * source key for variations. A second run updates in place instead of
 * duplicating. Every created object is recorded in STWM_Migration_Map so the
 * run can be rolled back.
 *
 * @package Store_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class STWM_Product_Importer {

	/** @var string */
	private $run_id;

	/** @var array */
	private $opts;

	public function __construct( $run_id, array $opts = array() ) {
		$this->run_id = (string) $run_id;
		$this->opts   = wp_parse_args(
			$opts,
			array(
				'download_images' => true,
				'force_draft'     => false,
				'batch_size'      => 15,
			)
		);
	}

	/**
	 * Action Scheduler entry point for one "product" batch. Processes
	 * [offset, offset + batch_size) product groups, then re-enqueues the next
	 * slice until the index is exhausted.
	 *
	 * @param array $payload run_id, offset, batch_size.
	 */
	public static function run_batch( array $payload ) {
		$run_id = isset( $payload['run_id'] ) ? (string) $payload['run_id'] : '';
		$offset = isset( $payload['offset'] ) ? max( 0, (int) $payload['offset'] ) : 0;
		$batch  = isset( $payload['batch_size'] ) ? max( 1, (int) $payload['batch_size'] ) : 15;

		$run = STWM_Run::get( $run_id );
		if ( ! $run ) {
			STWM_Logger::error( sprintf( 'Run %s not found — batch aborted.', $run_id ) );
			return;
		}
		if ( 'running' !== $run['status'] ) {
			STWM_Log::info( $run_id, sprintf( 'Batch skipped — run status is "%s".', $run['status'] ) );
			return;
		}

		$dir        = stwm_run_upload_dir( $run_id );
		$csv_path   = $dir['path'] . '/products.csv';
		$index_path = $dir['path'] . '/products.index.json';
		if ( ! file_exists( $csv_path ) || ! file_exists( $index_path ) ) {
			STWM_Log::error( $run_id, 'Batch aborted — the uploaded CSV or its index is missing.' );
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a small local JSON file the plugin itself just wrote, not a URL.
		$index   = json_decode( (string) file_get_contents( $index_path ), true );
		$handles = ( isset( $index['handles'] ) && is_array( $index['handles'] ) ) ? $index['handles'] : array();
		$total   = count( $handles );
		$slice   = array_slice( $handles, $offset, $batch );

		if ( ! $slice ) {
			STWM_Run::update( $run_id, array( 'status' => 'done' ) );
			STWM_Log::info( $run_id, sprintf( 'Migration complete: %d product groups processed.', $total ) );
			return;
		}

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}
		wp_defer_term_counting( true );

		$importer = new self( $run_id, isset( $run['options'] ) ? (array) $run['options'] : array() );

		$fh = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			wp_defer_term_counting( false );
			STWM_Log::error( $run_id, 'Batch aborted — the uploaded CSV could not be opened.' );
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			return;
		}
		$header = STWM_CSV::header( $fh );

		foreach ( $slice as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) < 3 ) {
				continue;
			}
			list( $handle, $byte_offset, $row_count ) = $entry;

			$rows = array();
			fseek( $fh, (int) $byte_offset );
			for ( $i = 0; $i < (int) $row_count; $i++ ) {
				$raw = fgetcsv( $fh );
				if ( false === $raw ) {
					break;
				}
				$assoc = STWM_CSV::assoc( $header, $raw );
				if ( null !== $assoc ) {
					$rows[] = $assoc;
				}
			}

			try {
				$importer->import_group( (string) $handle, $rows );
			} catch ( \Throwable $e ) {
				STWM_Log::error( $run_id, sprintf( 'Product "%s" failed: %s', $handle, $e->getMessage() ), 'product', (string) $handle );
				STWM_Migration_Map::set(
					array(
						'run_id'      => $run_id,
						'entity_type' => 'product',
						'source_id'   => (string) $handle,
						'status'      => 'error',
						'message'     => $e->getMessage(),
					)
				);
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		wp_defer_term_counting( false );

		$next  = $offset + $batch;
		$stats = isset( $run['stats'] ) ? (array) $run['stats'] : array();
		if ( ! isset( $stats['product'] ) ) {
			$stats['product'] = array( 'total' => $total );
		}
		$stats['product']['done'] = min( $next, $total );
		STWM_Run::update( $run_id, array( 'stats' => $stats ) );

		if ( $next < $total ) {
			STWM_Queue::enqueue_batch(
				array(
					'run_id'      => $run_id,
					'entity_type' => 'product',
					'offset'      => $next,
					'batch_size'  => $batch,
				)
			);
		} else {
			STWM_Run::update( $run_id, array( 'status' => 'done' ) );
			STWM_Log::info( $run_id, sprintf( 'Migration complete: %d product groups processed.', $total ) );
		}
	}

	/**
	 * Import one Handle's worth of rows into a single WooCommerce product.
	 *
	 * @param string $handle
	 * @param array  $rows   Header-keyed rows for this Handle.
	 */
	public function import_group( $handle, array $rows ) {
		if ( ! $rows ) {
			return;
		}
		$first = $rows[0];

		// Split rows into variant rows and image references.
		$variant_rows = array();
		$image_urls   = array();
		foreach ( $rows as $row ) {
			$has_opt    = '' !== stwm_col( $row, 'Option1 Value' )
				|| '' !== stwm_col( $row, 'Option2 Value' )
				|| '' !== stwm_col( $row, 'Option3 Value' );
			$is_variant = '' !== stwm_col( $row, 'Title' )
				|| $has_opt
				|| '' !== stwm_col( $row, 'Variant Price' )
				|| '' !== stwm_col( $row, 'Variant SKU' );
			if ( $is_variant ) {
				$variant_rows[] = $row;
			}
			$img = trim( stwm_col( $row, 'Image Src' ) );
			if ( '' !== $img ) {
				$pos = (int) stwm_col( $row, 'Image Position' );
				if ( $pos <= 0 ) {
					$pos = count( $image_urls ) + 1;
				}
				while ( isset( $image_urls[ $pos ] ) ) {
					++$pos;
				}
				$image_urls[ $pos ] = $img;
			}
		}
		if ( ! $variant_rows ) {
			$variant_rows[] = $first;
		}
		ksort( $image_urls );
		$image_urls  = array_values( array_unique( $image_urls ) );
		$is_variable = count( $variant_rows ) > 1;

		// Resolve an existing product: first via the ID map, then by slug.
		$existing_id = STWM_Migration_Map::get_target( 'product', $handle );
		if ( ! $existing_id ) {
			$by_slug = get_page_by_path( $handle, OBJECT, 'product' );
			if ( $by_slug ) {
				$existing_id = (int) $by_slug->ID;
			}
		}

		$product = null;
		if ( $existing_id ) {
			$product = wc_get_product( $existing_id );
			if ( $product && ( $is_variable xor $product->is_type( 'variable' ) ) ) {
				// Type changed between runs — drop and recreate rather than juggle.
				wp_delete_post( $existing_id, true );
				$product = null;
			}
		}
		if ( ! $product ) {
			$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		}

		$title = stwm_col( $first, 'Title' );
		$product->set_name( '' !== $title ? $title : $handle );
		$product->set_slug( $handle );
		$product->set_description( stwm_col( $first, 'Body (HTML)' ) );
		$product->set_status( $this->resolve_status( $first ) );
		$product->set_catalog_visibility( 'visible' );

		$cat_id = $this->ensure_term( stwm_col( $first, 'Type' ), 'product_cat' );
		if ( $cat_id ) {
			$product->set_category_ids( array( $cat_id ) );
		}

		$tag_ids = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', stwm_col( $first, 'Tags' ) ) ) ) as $tag ) {
			$tid = $this->ensure_term( $tag, 'product_tag' );
			if ( $tid ) {
				$tag_ids[] = $tid;
			}
		}
		if ( $tag_ids ) {
			$product->set_tag_ids( $tag_ids );
		}

		$seo_title = stwm_col( $first, 'SEO Title' );
		$seo_desc  = stwm_col( $first, 'SEO Description' );
		if ( '' !== $seo_title ) {
			$product->update_meta_data( '_stwm_seo_title', $seo_title );
			if ( defined( 'WPSEO_VERSION' ) ) {
				$product->update_meta_data( '_yoast_wpseo_title', $seo_title );
			}
		}
		if ( '' !== $seo_desc ) {
			$product->update_meta_data( '_stwm_seo_description', $seo_desc );
			if ( defined( 'WPSEO_VERSION' ) ) {
				$product->update_meta_data( '_yoast_wpseo_metadesc', $seo_desc );
			}
		}

		$vendor = stwm_col( $first, 'Vendor' );
		if ( '' !== $vendor ) {
			$product->update_meta_data( '_stwm_vendor', $vendor );
		}

		if ( ! $is_variable ) {
			$this->apply_pricing_stock( $product, $variant_rows[0] );
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			throw new \RuntimeException( 'WooCommerce save() returned 0' );
		}

		// Record the mapping the moment the product exists, before images and
		// variations. If the batch dies partway (server kill, timeout), the
		// rollback still has a row to clean up and the next run updates in place
		// instead of orphaning a post.
		STWM_Migration_Map::set(
			array(
				'run_id'      => $this->run_id,
				'entity_type' => 'product',
				'source_id'   => $handle,
				'source_ref'  => mb_substr( $title, 0, 191 ),
				'target_id'   => $product_id,
				'status'      => 'ok',
				'message'     => 'created',
			)
		);

		// Brand taxonomy, if one is registered (WooCommerce core brands or a plugin).
		if ( '' !== $vendor && taxonomy_exists( 'product_brand' ) ) {
			$term = term_exists( $vendor, 'product_brand' );
			if ( ! $term ) {
				$term = wp_insert_term( $vendor, 'product_brand' );
			}
			if ( ! is_wp_error( $term ) ) {
				wp_set_object_terms( $product_id, array( (int) $term['term_id'] ), 'product_brand' );
			}
		}

		if ( ! empty( $this->opts['download_images'] ) && $image_urls ) {
			$attachment_ids = array();
			foreach ( $image_urls as $url ) {
				$att = $this->sideload( $url, $product_id );
				if ( $att ) {
					$attachment_ids[] = $att;
				}
			}
			if ( $attachment_ids ) {
				$product->set_image_id( array_shift( $attachment_ids ) );
				if ( $attachment_ids ) {
					$product->set_gallery_image_ids( $attachment_ids );
				}
				$product->save();
			}
		}

		if ( $is_variable ) {
			$this->build_variable( $product, $product_id, $handle, $first, $variant_rows );
		}

		// Final update of the same mapping row now the product is fully built.
		STWM_Migration_Map::set(
			array(
				'run_id'      => $this->run_id,
				'entity_type' => 'product',
				'source_id'   => $handle,
				'source_ref'  => mb_substr( $title, 0, 191 ),
				'target_id'   => $product_id,
				'status'      => 'ok',
				'message'     => $is_variable ? sprintf( 'variable, %d variations', count( $variant_rows ) ) : 'simple',
			)
		);
		STWM_Log::info(
			$this->run_id,
			sprintf( '%s "%s" imported (#%d).', $is_variable ? 'Variable product' : 'Product', '' !== $title ? $title : $handle, $product_id ),
			'product',
			$handle
		);
	}

	/**
	 * Build the attribute set and variations for a variable product.
	 */
	private function build_variable( WC_Product_Variable $product, $product_id, $handle, array $first, array $variant_rows ) {
		$option_cols = array( 'Option1', 'Option2', 'Option3' );
		$attributes  = array();
		$attr_names  = array();

		foreach ( $option_cols as $col ) {
			$name = trim( stwm_col( $first, $col . ' Name' ) );
			if ( '' === $name || 'title' === strtolower( $name ) ) {
				$attr_names[ $col ] = null;
				continue;
			}
			$values = array();
			foreach ( $variant_rows as $vr ) {
				$value = trim( stwm_col( $vr, $col . ' Value' ) );
				if ( '' !== $value ) {
					$values[ $value ] = true;
				}
			}
			if ( ! $values ) {
				$attr_names[ $col ] = null;
				continue;
			}
			$attr_names[ $col ] = $name;

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( array_keys( $values ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}

		$product->set_attributes( $attributes );
		$product->save();

		foreach ( $variant_rows as $vr ) {
			$key_parts = array();
			foreach ( $option_cols as $col ) {
				$value = trim( stwm_col( $vr, $col . ' Value' ) );
				if ( '' !== $value ) {
					$key_parts[] = $value;
				}
			}
			$variant_key = implode( ' / ', $key_parts );
			$sku         = stwm_col( $vr, 'Variant SKU' );
			$v_source_id = $handle . '::' . ( '' !== $sku ? 'sku:' . $sku : 'opt:' . $variant_key );

			$existing_var_id = STWM_Migration_Map::get_target( 'variation', $v_source_id );
			$variation       = $existing_var_id ? wc_get_product( $existing_var_id ) : null;
			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation();
			}
			$variation->set_parent_id( $product_id );

			$v_attrs = array();
			foreach ( $option_cols as $col ) {
				if ( empty( $attr_names[ $col ] ) ) {
					continue;
				}
				$value = trim( stwm_col( $vr, $col . ' Value' ) );
				if ( '' === $value ) {
					continue;
				}
				$v_attrs[ sanitize_title( $attr_names[ $col ] ) ] = $value;
			}
			$variation->set_attributes( $v_attrs );
			$variation->set_status( 'publish' );
			$this->apply_pricing_stock( $variation, $vr );

			if ( ! empty( $this->opts['download_images'] ) ) {
				$v_img = stwm_col( $vr, 'Variant Image' );
				if ( '' !== $v_img ) {
					$att = $this->sideload( $v_img, $product_id );
					if ( $att ) {
						$variation->set_image_id( $att );
					}
				}
			}

			$var_id = $variation->save();
			STWM_Migration_Map::set(
				array(
					'run_id'      => $this->run_id,
					'entity_type' => 'variation',
					'source_id'   => $v_source_id,
					'source_ref'  => mb_substr( $variant_key, 0, 191 ),
					'target_id'   => $var_id,
					'status'      => 'ok',
				)
			);
		}

		$reloaded = wc_get_product( $product_id );
		if ( $reloaded ) {
			$reloaded->save();
		}
		WC_Product_Variable::sync( $product_id );
		wc_delete_product_transients( $product_id );
	}

	/**
	 * Apply price, SKU, stock, weight, shipping and tax to a simple product or
	 * a variation from one variant row.
	 *
	 * @param WC_Product|WC_Product_Variation $obj
	 * @param array                           $vr
	 */
	private function apply_pricing_stock( $obj, array $vr ) {
		$price   = stwm_parse_price( stwm_col( $vr, 'Variant Price' ) );
		$compare = stwm_parse_price( stwm_col( $vr, 'Variant Compare At Price' ) );

		if ( null !== $compare && null !== $price && $compare > $price ) {
			// Shopify: Price is what the customer pays now, Compare At is the "was".
			$obj->set_regular_price( (string) $compare );
			$obj->set_sale_price( (string) $price );
		} else {
			$obj->set_regular_price( null === $price ? '' : (string) $price );
			$obj->set_sale_price( '' );
		}

		$sku = stwm_col( $vr, 'Variant SKU' );
		if ( '' !== $sku ) {
			try {
				$obj->set_sku( $sku );
			} catch ( WC_Data_Exception $e ) {
				STWM_Log::warning( $this->run_id, sprintf( 'SKU "%s" already in use — left blank on "%s".', $sku, $obj->get_name() ), 'variation', $sku );
			}
		}

		$tracker = strtolower( trim( stwm_col( $vr, 'Variant Inventory Tracker' ) ) );
		$qty_raw = trim( stwm_col( $vr, 'Variant Inventory Qty' ) );
		$policy  = strtolower( trim( stwm_col( $vr, 'Variant Inventory Policy' ) ) );
		if ( 'shopify' === $tracker || '' !== $qty_raw ) {
			$qty = (int) $qty_raw;
			$obj->set_manage_stock( true );
			$obj->set_stock_quantity( $qty );
			$obj->set_backorders( 'continue' === $policy ? 'yes' : 'no' );
			$obj->set_stock_status( ( $qty > 0 || 'continue' === $policy ) ? 'instock' : 'outofstock' );
		} else {
			$obj->set_manage_stock( false );
			$obj->set_stock_status( 'instock' );
		}

		$grams = (float) str_replace( ',', '.', stwm_col( $vr, 'Variant Grams' ) );
		if ( $grams > 0 ) {
			$obj->set_weight( (string) stwm_weight_from_grams( $grams ) );
		}

		if ( 'false' === strtolower( trim( stwm_col( $vr, 'Variant Requires Shipping' ) ) ) ) {
			$obj->set_virtual( true );
		}
		$obj->set_tax_status( 'false' === strtolower( trim( stwm_col( $vr, 'Variant Taxable' ) ) ) ? 'none' : 'taxable' );

		$barcode = stwm_col( $vr, 'Variant Barcode' );
		if ( '' !== $barcode && method_exists( $obj, 'set_global_unique_id' ) ) {
			try {
				$obj->set_global_unique_id( $barcode );
			} catch ( WC_Data_Exception $e ) {
				$obj->update_meta_data( '_stwm_barcode', $barcode );
			}
		} elseif ( '' !== $barcode ) {
			$obj->update_meta_data( '_stwm_barcode', $barcode );
		}

		$cost = stwm_col( $vr, 'Cost per item' );
		if ( '' !== $cost ) {
			$obj->update_meta_data( '_stwm_cost_per_item', wc_clean( $cost ) );
		}
	}

	/**
	 * Download a remote image into the media library once, reusing the
	 * attachment on later rows / runs via the ID map.
	 *
	 * @param string $url
	 * @param int    $post_id Product to attach to.
	 * @return int Attachment ID, or 0 on failure.
	 */
	private function sideload( $url, $post_id ) {
		$url = trim( $url );
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return 0;
		}
		$key      = 'img:' . sha1( $url );
		$existing = STWM_Migration_Map::get_target( 'image', $key );
		if ( $existing && wp_get_attachment_url( $existing ) ) {
			// Reuse the attachment, but re-stamp the mapping row with this run so
			// the run's image count and rollback set stay accurate across re-imports.
			STWM_Migration_Map::set(
				array(
					'run_id'      => $this->run_id,
					'entity_type' => 'image',
					'source_id'   => $key,
					'source_ref'  => mb_substr( $url, 0, 191 ),
					'target_id'   => (int) $existing,
					'status'      => 'ok',
				)
			);
			return (int) $existing;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$id = media_sideload_image( $url, $post_id, null, 'id' );
		if ( is_wp_error( $id ) ) {
			STWM_Log::warning( $this->run_id, sprintf( 'Image failed (%s): %s', $url, $id->get_error_message() ), 'image', $url );
			return 0;
		}

		STWM_Migration_Map::set(
			array(
				'run_id'      => $this->run_id,
				'entity_type' => 'image',
				'source_id'   => $key,
				'source_ref'  => mb_substr( $url, 0, 191 ),
				'target_id'   => (int) $id,
				'status'      => 'ok',
			)
		);
		return (int) $id;
	}

	/**
	 * Map Shopify's product status to a WordPress post status.
	 *
	 * @param array $first First row of the group.
	 * @return string publish|draft
	 */
	private function resolve_status( array $first ) {
		if ( ! empty( $this->opts['force_draft'] ) ) {
			return 'draft';
		}
		$status = strtolower( trim( stwm_col( $first, 'Status' ) ) ); // active|draft|archived (newer exports).
		if ( 'active' === $status ) {
			return 'publish';
		}
		if ( 'draft' === $status || 'archived' === $status ) {
			return 'draft';
		}
		$published = strtoupper( trim( stwm_col( $first, 'Published' ) ) );
		if ( 'TRUE' === $published ) {
			return 'publish';
		}
		if ( 'FALSE' === $published ) {
			return 'draft';
		}
		return 'publish';
	}

	/**
	 * Find or create a term, returning its ID (0 on failure / empty name).
	 *
	 * @param string $name
	 * @param string $taxonomy
	 * @return int
	 */
	private function ensure_term( $name, $taxonomy ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$existing = term_exists( $name, $taxonomy );
		if ( $existing ) {
			return (int) $existing['term_id'];
		}
		$created = wp_insert_term( $name, $taxonomy );
		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}
}
