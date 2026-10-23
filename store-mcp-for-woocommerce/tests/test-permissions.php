<?php
/**
 * Key permission levels and tool packs are enforced on every call.
 *
 * @package WCMCP
 */

/**
 * Permission and pack tests.
 */
class Test_WCMCP_Permissions extends WCMCP_Test_Case {

	/**
	 * Without an MCP key nothing is allowed, even for a logged-in administrator.
	 */
	public function test_no_key_means_no_access() {
		$this->assertRefused( WCMCP_Permissions::check( 'orders', WCMCP_Permissions::OP_READ, 'edit_shop_orders' ), 'wcmcp_not_authenticated' );
		$this->assertAbilityDenied( 'wcmcp/orders-list' );
	}

	/**
	 * A read-only key can read.
	 */
	public function test_read_key_can_read() {
		$this->use_key( $this->create_key( 'read' ) );
		$this->assertTrue( WCMCP_Permissions::check( 'orders', WCMCP_Permissions::OP_READ, 'edit_shop_orders' ) );
		$this->assertIsArray( $this->run_ability( 'wcmcp/orders-list' ) );
	}

	/**
	 * A read-only key can never create, update or delete.
	 */
	public function test_read_key_can_never_write() {
		$this->use_key( $this->create_key( 'read' ) );
		update_option( 'wcmcp_pack_coupons', 'yes' );
		update_option( 'wcmcp_pack_refunds', 'yes' );

		$this->assertRefused( WCMCP_Permissions::check( 'products', WCMCP_Permissions::OP_WRITE, 'edit_products' ), 'wcmcp_key_read_only' );
		$this->assertRefused( WCMCP_Permissions::check( 'refunds', WCMCP_Permissions::OP_DESTRUCTIVE, 'edit_shop_orders' ), 'wcmcp_key_read_only' );

		$products_before = count( wc_get_products( array( 'limit' => -1, 'status' => 'any', 'return' => 'ids' ) ) );
		$this->assertAbilityDenied( 'wcmcp/products-create', array( 'name' => 'Must not exist' ) );
		$this->assertCount( $products_before, wc_get_products( array( 'limit' => -1, 'status' => 'any', 'return' => 'ids' ) ) );

		$coupon = $this->create_coupon( 'readonlytest' );
		$this->assertAbilityDenied( 'wcmcp/coupons-update', array( 'coupon_id' => $coupon->get_id(), 'amount' => '99' ) );
		$this->assertSame( '5', ( new WC_Coupon( $coupon->get_id() ) )->get_amount() );

		$this->assertAbilityDenied( 'wcmcp/coupons-delete', array( 'coupon_id' => $coupon->get_id() ) );
		$this->assertSame( 'publish', get_post_status( $coupon->get_id() ) );
	}

	/**
	 * A write-only key cannot read (WooCommerce REST key semantics).
	 */
	public function test_write_key_cannot_read() {
		$this->use_key( $this->create_key( 'write' ) );
		$this->assertRefused( WCMCP_Permissions::check( 'orders', WCMCP_Permissions::OP_READ, 'edit_shop_orders' ), 'wcmcp_key_cannot_read' );
		$this->assertTrue( WCMCP_Permissions::check( 'orders', WCMCP_Permissions::OP_WRITE, 'edit_shop_orders' ) );
	}

	/**
	 * A read/write key can do both.
	 */
	public function test_read_write_key_can_read_and_write() {
		$this->use_key( $this->create_key( 'read_write' ) );
		$this->assertTrue( WCMCP_Permissions::check( 'orders', WCMCP_Permissions::OP_READ, 'edit_shop_orders' ) );
		$this->assertTrue( WCMCP_Permissions::check( 'orders', WCMCP_Permissions::OP_WRITE, 'edit_shop_orders' ) );

		$result = $this->run_ability( 'wcmcp/products-create', array( 'name' => 'Allowed product', 'regular_price' => '10' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['product']['status'] );
	}

	/**
	 * A disabled pack is blocked at execution, not just hidden, even for a read/write key.
	 */
	public function test_disabled_pack_is_blocked_at_execution() {
		$this->use_key( $this->create_key( 'read_write' ) );
		update_option( 'wcmcp_pack_orders', 'no' );

		$this->assertRefused( WCMCP_Permissions::check( 'orders', WCMCP_Permissions::OP_READ, 'edit_shop_orders' ), 'wcmcp_pack_disabled' );
		$this->assertAbilityDenied( 'wcmcp/orders-list' );

		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->save();
		$this->assertAbilityDenied( 'wcmcp/orders-update-status', array( 'order_id' => $order->get_id(), 'status' => 'completed' ) );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Packs that move money or delete data are off until switched on.
	 */
	public function test_refunds_and_coupons_packs_are_off_by_default() {
		$this->use_key( $this->create_key( 'read_write' ) );
		$this->assertFalse( WCMCP_Packs::is_enabled( 'refunds' ) );
		$this->assertFalse( WCMCP_Packs::is_enabled( 'coupons' ) );
		$this->assertRefused( WCMCP_Permissions::check( 'refunds', WCMCP_Permissions::OP_DESTRUCTIVE, 'edit_shop_orders' ), 'wcmcp_pack_disabled' );
		$this->assertTrue( WCMCP_Packs::is_enabled( 'core' ) );
		$this->assertFalse( WCMCP_Packs::is_enabled( 'unknown-pack' ) );
	}

	/**
	 * The tool list only shows what the key level and packs allow.
	 */
	public function test_tool_list_matches_key_level_and_packs() {
		update_option( 'wcmcp_pack_coupons', 'yes' );

		$this->use_key( $this->create_key( 'read' ) );
		$read_names = WCMCP_Abilities::enabled_names();
		$this->assertContains( 'wcmcp/orders-list', $read_names );
		$this->assertNotContains( 'wcmcp/orders-update-status', $read_names );
		$this->assertNotContains( 'wcmcp/coupons-delete', $read_names );

		$this->use_key( $this->create_key( 'write' ) );
		$write_names = WCMCP_Abilities::enabled_names();
		$this->assertContains( 'wcmcp/coupons-create', $write_names );
		$this->assertNotContains( 'wcmcp/orders-list', $write_names );

		update_option( 'wcmcp_pack_orders', 'no' );
		$this->use_key( $this->create_key( 'read_write' ) );
		$names = WCMCP_Abilities::enabled_names();
		$this->assertNotContains( 'wcmcp/orders-list', $names );
		$this->assertNotContains( 'wcmcp/refunds-create', $names );
		$this->assertContains( 'wcmcp/store-info', $names );
	}

	/**
	 * The key owner's own capabilities still apply.
	 */
	public function test_owner_capabilities_apply() {
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$this->use_key( $this->create_key( 'read_write', $manager ) );
		$this->assertTrue( WCMCP_Permissions::check( 'orders', WCMCP_Permissions::OP_WRITE, 'edit_shop_orders' ) );
		$this->assertRefused( WCMCP_Permissions::check( 'orders', WCMCP_Permissions::OP_WRITE, 'manage_options' ), 'wcmcp_insufficient_capability' );
	}

	/**
	 * Unknown operation types are refused.
	 */
	public function test_unknown_operation_refused() {
		$this->use_key( $this->create_key( 'read_write' ) );
		$this->assertRefused( WCMCP_Permissions::check( 'orders', 'admin', 'edit_shop_orders' ), 'wcmcp_unknown_operation' );
	}
}
