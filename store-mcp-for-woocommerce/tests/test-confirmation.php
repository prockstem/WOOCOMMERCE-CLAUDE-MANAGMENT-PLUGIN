<?php
/**
 * Destructive tools need a preview, then an explicit confirmation with a valid single-use token.
 *
 * @package WCMCP
 */

/**
 * Confirmation tests.
 */
class Test_WCMCP_Confirmation extends WCMCP_Test_Case {

	/**
	 * Read/write key used by most tests.
	 *
	 * @var array
	 */
	private $key;

	/**
	 * Switches on the destructive packs and authenticates.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'wcmcp_pack_coupons', 'yes' );
		update_option( 'wcmcp_pack_refunds', 'yes' );
		$this->key = $this->create_key( 'read_write' );
		$this->use_key( $this->key );
	}

	/**
	 * A paid order with one line of two units.
	 *
	 * @return WC_Order
	 */
	private function paid_order() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Refundable' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 2 );
		$order->calculate_totals( false );
		$order->set_status( 'completed' );
		$order->save();
		return $order;
	}

	/**
	 * Missing confirm: only a preview, nothing deleted.
	 */
	public function test_missing_confirm_only_previews_delete() {
		$coupon = $this->create_coupon( 'previewonly' );
		$result = $this->run_ability( 'wcmcp/coupons-delete', array( 'coupon_id' => $coupon->get_id() ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['done'] );
		$this->assertTrue( $result['confirmation_required'] );
		$this->assertNotEmpty( $result['confirmation_token'] );
		$this->assertSame( 'publish', get_post_status( $coupon->get_id() ) );
	}

	/**
	 * Missing confirm: only a preview, no refund created.
	 */
	public function test_missing_confirm_only_previews_refund() {
		$order  = $this->paid_order();
		$result = $this->run_ability( 'wcmcp/refunds-create', array( 'order_id' => $order->get_id(), 'amount' => '5' ) );

		$this->assertFalse( $result['done'] );
		$this->assertSame( '5.00', $result['preview']['amount'] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * confirm: false is the same as leaving it out.
	 */
	public function test_confirm_false_only_previews() {
		$coupon = $this->create_coupon( 'confirmfalse' );
		$result = $this->run_ability( 'wcmcp/coupons-delete', array( 'coupon_id' => $coupon->get_id(), 'confirm' => false ) );
		$this->assertFalse( $result['done'] );
		$this->assertSame( 'publish', get_post_status( $coupon->get_id() ) );
	}

	/**
	 * confirm: true without a token is refused.
	 */
	public function test_confirm_without_token_is_refused() {
		$coupon = $this->create_coupon( 'notoken' );
		$result = $this->run_ability( 'wcmcp/coupons-delete', array( 'coupon_id' => $coupon->get_id(), 'confirm' => true ) );

		$this->assertRefused( $result, 'wcmcp_confirmation_token_missing' );
		$this->assertSame( 'publish', get_post_status( $coupon->get_id() ) );
	}

	/**
	 * A token for different arguments is refused.
	 */
	public function test_token_for_other_arguments_is_refused() {
		$order   = $this->paid_order();
		$preview = $this->run_ability( 'wcmcp/refunds-create', array( 'order_id' => $order->get_id(), 'amount' => '5' ) );
		$result  = $this->run_ability(
			'wcmcp/refunds-create',
			array(
				'order_id'           => $order->get_id(),
				'amount'             => '20',
				'confirm'            => true,
				'confirmation_token' => $preview['confirmation_token'],
			)
		);

		$this->assertRefused( $result, 'wcmcp_confirmation_mismatch' );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * A valid token works exactly once.
	 */
	public function test_valid_token_works_once() {
		$order   = $this->paid_order();
		$args    = array(
			'order_id' => $order->get_id(),
			'amount'   => '5',
			'reason'   => 'Test',
		);
		$preview = $this->run_ability( 'wcmcp/refunds-create', $args );
		$confirm = $args + array(
			'confirm'            => true,
			'confirmation_token' => $preview['confirmation_token'],
		);

		$result = $this->run_ability( 'wcmcp/refunds-create', $confirm );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );

		$this->assertRefused( $this->run_ability( 'wcmcp/refunds-create', $confirm ), 'wcmcp_confirmation_used' );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * A token issued to one key does not work for another.
	 */
	public function test_token_from_another_key_is_refused() {
		$coupon  = $this->create_coupon( 'otherkey' );
		$preview = $this->run_ability( 'wcmcp/coupons-delete', array( 'coupon_id' => $coupon->get_id() ) );

		$this->use_key( $this->create_key( 'read_write' ) );
		$result = $this->run_ability(
			'wcmcp/coupons-delete',
			array(
				'coupon_id'          => $coupon->get_id(),
				'confirm'            => true,
				'confirmation_token' => $preview['confirmation_token'],
			)
		);

		$this->assertRefused( $result, 'wcmcp_confirmation_mismatch' );
		$this->assertSame( 'publish', get_post_status( $coupon->get_id() ) );
	}

	/**
	 * Expired and forged tokens are refused.
	 */
	public function test_expired_and_forged_tokens_are_refused() {
		$coupon = $this->create_coupon( 'expired' );
		$args   = array(
			'coupon_id' => $coupon->get_id(),
			'confirm'   => true,
		);

		$old = ( time() - WCMCP_Confirm::TTL - 5 ) . '.' . str_repeat( 'a', 64 );
		$this->assertRefused( $this->run_ability( 'wcmcp/coupons-delete', $args + array( 'confirmation_token' => $old ) ), 'wcmcp_confirmation_token_expired' );
		$this->assertRefused( $this->run_ability( 'wcmcp/coupons-delete', $args + array( 'confirmation_token' => 'not-a-token' ) ), 'wcmcp_confirmation_token_invalid' );
		$this->assertRefused( $this->run_ability( 'wcmcp/coupons-delete', $args + array( 'confirmation_token' => time() . '.' . str_repeat( 'b', 64 ) ) ), 'wcmcp_confirmation_mismatch' );
		$this->assertSame( 'publish', get_post_status( $coupon->get_id() ) );
	}

	/**
	 * A confirmed line-item refund with restock puts stock back.
	 */
	public function test_confirmed_line_item_refund_restocks() {
		$order   = $this->paid_order();
		$items   = $order->get_items();
		$item    = reset( $items );
		$product = $item->get_product();
		$before  = (int) wc_get_product( $product->get_id() )->get_stock_quantity();

		$args    = array(
			'order_id'   => $order->get_id(),
			'line_items' => array(
				array(
					'item_id'  => $item->get_id(),
					'quantity' => 1,
				),
			),
			'restock'    => true,
		);
		$preview = $this->run_ability( 'wcmcp/refunds-create', $args );
		$this->assertSame( '10.00', $preview['preview']['amount'] );

		$result = $this->run_ability( 'wcmcp/refunds-create', $args + array( 'confirm' => true, 'confirmation_token' => $preview['confirmation_token'] ) );
		$this->assertTrue( $result['done'] );
		$this->assertSame( $before + 1, (int) wc_get_product( $product->get_id() )->get_stock_quantity() );
	}

	/**
	 * Invalid refunds fail at preview, before anyone is asked to confirm.
	 */
	public function test_invalid_refund_fails_at_preview() {
		$order = $this->paid_order();
		$this->assertRefused( $this->run_ability( 'wcmcp/refunds-create', array( 'order_id' => $order->get_id(), 'amount' => '999' ) ), 'wcmcp_amount_too_high' );
		$this->assertRefused( $this->run_ability( 'wcmcp/refunds-create', array( 'order_id' => $order->get_id(), 'amount' => '1', 'restock' => true ) ), 'wcmcp_restock_needs_items' );
	}
}
