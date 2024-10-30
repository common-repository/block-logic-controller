<?php
/**
 * Plugin Name: Block Logic Controller
 * Description: Add block criteria or conditions for all registered blocks to show or hide or even replace them.
 * Requires at least: 6.4
 * Version: 0.1.0
 * Author: Zourbuth
 * Author URI: https://profiles.wordpress.org/zourbuth/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: block-logic-controller
 *
 * @package block-logic-controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Enqueue specific modifications for the block editor.
 *
 * @since 0.1.0
 */
function blctrl_get_user_meta_keys() {
	$cache_key = 'user_meta_keys';

	$user_meta_keys = wp_cache_get( $cache_key );

	if ( false === $user_meta_keys ) {
		global $wpdb;
		$limit = 50;

		$user_meta_keys = $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare(
				"SELECT DISTINCT meta_key
				FROM $wpdb->usermeta
				WHERE meta_key NOT BETWEEN '_' AND '_z'
				HAVING meta_key NOT LIKE %s
				ORDER BY meta_key
				LIMIT %d",
				$wpdb->esc_like( '_' ) . '%',
				$limit
			)
		);

		wp_cache_set( $cache_key, $user_meta_keys );
	}

	return $user_meta_keys;
}

/**
 * Enqueue specific modifications for the block editor.
 *
 * @since 0.1.0
 */
function blctrl_enqueue_assets() {
	$user_meta_keys = blctrl_get_user_meta_keys();

	$roles = array();
	foreach ( wp_roles()->roles as $key => $value ) {
		$roles[] = array(
			'value' => $key,
			'label' => $value['name'],
		);
	}

	$slug       = 'block-logic-controller';
	$asset_file = include plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

	wp_enqueue_style( $slug, plugin_dir_url( __FILE__ ) . 'build/index.css', array(), $asset_file['version'] );
	wp_enqueue_script( $slug, plugin_dir_url( __FILE__ ) . 'build/index.js', $asset_file['dependencies'], $asset_file['version'], true );
	wp_localize_script(
		$slug,
		'blctrl',
		array(
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'blctrl' ),
			'roles'     => $roles,
			'user_meta' => $user_meta_keys,
		)
	);
}

add_action( 'enqueue_block_editor_assets', 'blctrl_enqueue_assets' );

/**
 * Filter button blocks for possible link attributes
 *
 * @param string $key criteria key.
 * @param array  $value criteria value boolean, string or object.
 * @since 0.1.0
 */
function blectrl_criteria_met( $key, $value ) {
	switch ( $key ) {
		case 'user_logged':
			return is_user_logged_in() === $value ? true : false;

		case 'user_roles':
			// Return false if not a logged in user and no roles selected.
			if ( ! is_user_logged_in() || empty( $value['roles'] ) ) {
				return false;
			}

			// Some plugin such as WooCommerce can have multiple roles per user.
			$user       = wp_get_current_user();
			$user_roles = $user->roles;

			if ( 'roles_in' === $value['query'] ) {
				$intersect = array_intersect( $user_roles, $value['roles'] );

				if ( count( $intersect ) === count( $value['roles'] ) ) {
					return true;
				} else {
					return false;
				}
			}

			if ( 'roles_not_in' === $value['query'] ) {
				$intersect = array_intersect( $user_roles, $value['roles'] );

				if ( count( $intersect ) ) {
					return false;
				} else {
					return true;
				}
			}
			break;

		case 'user_meta':
			// Return false if not a logged in user and no meta keys selected.
			if ( ! is_user_logged_in() || empty( $value['meta'] ) ) {
				return false;
			}

			$user_meta_keys = blctrl_get_user_meta_keys();
			$user           = wp_get_current_user();
			$raw_user_meta  = get_user_meta( $user->ID );

			$user_meta = array();
			foreach ( $raw_user_meta as $k => $v ) {
				$user_meta[] = $k;
			}

			if ( 'have_meta' === $value['query'] ) {
				$intersect = array_intersect( $user_meta, $value['meta'] );
				if ( count( $intersect ) === count( $value['meta'] ) ) {
					return true;
				} else {
					return false;
				}
			}

			if ( 'not_have_meta' === $value['query'] ) {
				$intersect = array_intersect( $user_meta, $value['meta'] );

				if ( count( $intersect ) ) {
					return false;
				} else {
					return true;
				}
			}
			break;

		case 'is_mobile':
			if ( wp_is_mobile() === $value ) {
				return true;
			} else {
				return false;
			}
			break;
	}

	return false;
}

/**
 * Filter button blocks for possible link attributes
 *
 * @param string $block_content The block content about to be rendered.
 * @param array  $block The full block, including name and attributes.
 * @since 0.1.0
 */
function blctrl_do_logic( $block_content, $block ) {
	$results = array(); // this hold logic boolean value.

	// Only do logic if criteria available and not empty.
	if ( isset( $block['attrs']['logic'] ) && isset( $block['attrs']['logic']['criteria'] ) ) {

		$criteria = $block['attrs']['logic']['criteria'];

		if ( count( $criteria ) ) {
			$action = esc_attr( $block['attrs']['logic']['action'] );
			$ifs    = esc_attr( $block['attrs']['logic']['ifs'] );

			foreach ( $criteria as $key => $value ) {
				$results[] = blectrl_criteria_met( $key, $value );
			}

			if ( 'all' === $ifs ) {
				if ( 'show' === $action ) {
					return in_array( false, $results, true ) ? '' : $block_content;
				}
				if ( 'hide' === $action ) {
					return in_array( false, $results, true ) ? $block_content : '';
				}
				if ( 'replace' === $action && isset( $block['attrs']['logic']['replacer'] ) ) {
					return in_array( false, $results, true ) ? $block_content : '<blctrl>' . esc_attr( $block['attrs']['logic']['replacer'] ) . '</blctrl>';
				}
			}

			if ( 'any' === $ifs ) {
				if ( 'show' === $action ) {
					return in_array( true, $results, true ) ? $block_content : '';
				}
				if ( 'hide' === $action ) {
					return in_array( true, $results, true ) ? '' : $block_content;
				}
				if ( 'replace' === $action && isset( $block['attrs']['logic']['replacer'] ) ) {
					return in_array( true, $results, true ) ? '<blctrl>' . esc_attr( $block['attrs']['logic']['replacer'] ) . '</blctrl>' : $block_content;
				}
			}
		}
	}

	return $block_content;
}

add_filter( 'render_block', 'blctrl_do_logic', 10, 2 );

/**
 * Replace logic content with it's replacer content.
 *
 * @param string $content the post/page content.
 * @since 0.1.0
 */
function blctrl_replacer_content( $content ) {
	if ( empty( $content ) ) {
		return $content;
	}

	$tagname = 'blctrl';
	$pattern = "/<$tagname>(.*)<\/$tagname>/";

	preg_match_all( $pattern, $content, $blctrl ); // check ok.

	foreach ( $blctrl[1] as $k => $div_id ) {
		$div_pattern = '/(<.*data-blctrl="' . $div_id . '".*>.*<\/.*>)/';

		preg_match_all( $div_pattern, $content, $divs );

		if ( isset( $divs[1][0] ) ) { // replacer not exists.
			$content = str_replace( $divs[1][0], '', $content );
			$content = str_replace( $blctrl[0][ $k ], $divs[1][0], $content );
		} else { // replace <blctr/> and show message.
			$content = str_replace( $blctrl[0][ $k ], '<p>' . __( 'Replacer block does not exist! Please check the logic settings for this block.', 'block-logic-controller' ) . '</p>', $content );
		}
	}

	return $content;
}

add_filter( 'the_content', 'blctrl_replacer_content', 10, 1 );

/**
 * Register logic attributes.
 *
 * @param string $args block arguments contains attributes, supports, etc.
 * @param array  $block_type the block type.
 * @since 0.1.0
 */
function blctrl_register_block_type_args( $args, $block_type ) { // phpcs:ignore

	$args['attributes']['logic'] = array(
		'default' => '{}',
		'type'    => 'object',
	);

	$args['attributes']['logicId'] = array(
		'default' => '',
		'type'    => 'string',
	);

	return $args;
}

add_filter( 'register_block_type_args', 'blctrl_register_block_type_args', 10, 2 );
