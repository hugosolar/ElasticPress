<?php
/**
 * Test woocommerce feature
 *
 * @package elasticpress
 */

namespace ElasticPressTest;

use ElasticPress;

/**
 * WC test class
 */
class TestWooCommerce extends BaseTestCase {

	/**
	 * Setup each test.
	 *
	 * @since 2.1
	 * @group woocommerce
	 */
	public function setUp() {
		global $wpdb;
		parent::setUp();
		$wpdb->suppress_errors();

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		ElasticPress\Elasticsearch::factory()->delete_all_indices();
		ElasticPress\Indexables::factory()->get( 'post' )->put_mapping();

		ElasticPress\Indexables::factory()->get( 'post' )->sync_manager->sync_queue = [];

		$this->setup_test_post_type();

		delete_option( 'ep_active_features' );
	}

	/**
	 * Clean up after each test. Reset our mocks
	 *
	 * @since 2.1
	 * @group woocommerce
	 */
	public function tearDown() {
		parent::tearDown();

		// make sure no one attached to this
		remove_filter( 'ep_sync_terms_allow_hierarchy', array( $this, 'ep_allow_multiple_level_terms_sync' ), 100 );
		$this->fired_actions = array();
	}

	/**
	 * Test products post type query does get integrated when the feature is not active
	 *
	 * @since 2.1
	 * @group woocommerce
	 */
	public function testProductsPostTypeQueryOn() {
		ElasticPress\Features::factory()->activate_feature( 'woocommerce' );
		ElasticPress\Features::factory()->setup_features();

		Functions\create_and_sync_post();
		Functions\create_and_sync_post(
			array(
				'post_content' => 'product 1',
				'post_type'    => 'product',
			)
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		add_action( 'ep_wp_query_search', array( $this, 'action_wp_query_search' ), 10, 0 );

		$args = array(
			'post_type' => 'product',
		);

		$query = new \WP_Query( $args );

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 1, $query->post_count );
		$this->assertEquals( 1, $query->found_posts );

		$this->assertTrue( ! empty( $this->fired_actions['ep_wp_query_search'] ) );
	}

	/**
	 * Test products post type query does get integrated when querying WC product_cat taxonomy
	 *
	 * @since 2.1
	 * @group woocommerce
	 */
	public function testProductsPostTypeQueryProductCatTax() {
		ElasticPress\Features::factory()->activate_feature( 'admin' );
		ElasticPress\Features::factory()->activate_feature( 'woocommerce' );
		ElasticPress\Features::factory()->setup_features();

		Functions\create_and_sync_post();

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		add_action( 'ep_wp_query_search', array( $this, 'action_wp_query_search' ), 10, 0 );

		$args = array(
			'tax_query' => array(
				array(
					'taxonomy' => 'product_cat',
					'terms'    => array( 'cat' ),
					'field'    => 'slug',
				),
			),
		);

		$query = new \WP_Query( $args );

		$this->assertTrue( $query->elasticsearch_success );
	}

	/**
	 * Test search integration is on for shop orders
	 *
	 * @since 2.1
	 * @group woocommerce
	 */
	public function testSearchOnShopOrderAdmin() {
		ElasticPress\Features::factory()->activate_feature( 'protected_content' );
		ElasticPress\Features::factory()->activate_feature( 'woocommerce' );
		ElasticPress\Features::factory()->setup_features();

		Functions\create_and_sync_post(
			array(
				'post_content' => 'findme',
				'post_type'    => 'shop_order',
			)
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$args = array(
			's'         => 'findme',
			'post_type' => 'shop_order',
		);

		$query = new \WP_Query( $args );

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 1, $query->post_count );
		$this->assertEquals( 1, $query->found_posts );
	}

	/**
	 * Test search for shop orders by order ID
	 *
	 * @since 4.0.0
	 * @group woocommerce
	 */
	public function testSearchShopOrderById() {
		ElasticPress\Features::factory()->activate_feature( 'protected_content' );
		ElasticPress\Features::factory()->activate_feature( 'woocommerce' );
		ElasticPress\Features::factory()->setup_features();

		$shop_order_id = Functions\create_and_sync_post(
			array(
				'post_type' => 'shop_order',
			)
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$args = array(
			's'         => $shop_order_id,
			'post_type' => 'shop_order',
		);

		$query = new \WP_Query( $args );

		// Log problems in the
		add_action(
			'ep_invalid_response',
			function( $request, $query ) {
				echo '<pre>';
				print_r( $request );
				echo '</pre>';
				echo '<pre>';
				print_r( $query );
				echo '</pre>';
			}
		);

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 1, $query->post_count );
		$this->assertEquals( 1, $query->found_posts );
	}

	/**
	 * Test search for shop orders matching field and ID.
	 *
	 * If searching for a number that is an order ID and part of another order's metadata,
	 * both should be returned.
	 *
	 * @since 4.0.0
	 * @group woocommerce
	 */
	public function testSearchShopOrderByMetaFieldAndId() {
		ElasticPress\Features::factory()->activate_feature( 'protected_content' );
		ElasticPress\Features::factory()->activate_feature( 'woocommerce' );
		ElasticPress\Features::factory()->setup_features();

		$shop_order_id_1 = Functions\create_and_sync_post(
			array(
				'post_type' => 'shop_order',
			)
		);

		Functions\create_and_sync_post(
			array(
				'post_type' => 'shop_order',
			),
			array(
				'_billing_phone' => 'Phone number that matches an order ID: ' . $shop_order_id_1,
			)
		);

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		$args = array(
			's'         => $shop_order_id_1,
			'post_type' => 'shop_order',
		);

		$query = new \WP_Query( $args );

		$this->assertTrue( $query->elasticsearch_success );
		$this->assertEquals( 2, $query->post_count );
		$this->assertEquals( 2, $query->found_posts );
	}

	/**
	 * Test search integration is on in general for product searches
	 *
	 * @since 2.1
	 * @group woocommerce
	 */
	public function testSearchOnAllFrontEnd() {
		ElasticPress\Features::factory()->activate_feature( 'woocommerce' );
		ElasticPress\Features::factory()->setup_features();

		ElasticPress\Elasticsearch::factory()->refresh_indices();

		add_action( 'ep_wp_query_search', array( $this, 'action_wp_query_search' ), 10, 0 );

		$args = array(
			's'         => 'findme',
			'post_type' => 'product',
		);

		$query = new \WP_Query( $args );

		$this->assertTrue( $query->elasticsearch_success );
	}
}
