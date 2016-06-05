<?php

class Affiliate_WP_RCP extends Affiliate_WP_Base {

	/**
	 * Gets things started
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function init() {

		$this->context = 'rcp';

		add_action( 'rcp_form_processing', array( $this, 'add_pending_referral' ), 10, 3 );
		add_action( 'rcp_insert_payment', array( $this, 'mark_referral_complete' ), 10, 3 );
		add_action( 'rcp_delete_payment', array( $this, 'revoke_referral_on_delete' ), 10 );

		add_filter( 'affwp_referral_reference_column', array( $this, 'reference_link' ), 10, 2 );

		// Discount code tracking actions and filters
		add_action( 'rcp_add_discount_form', array( $this, 'discount_edit' ) );
		add_action( 'rcp_edit_discount_form', array( $this, 'discount_edit' ) );
		add_action( 'rcp_add_discount', array( $this, 'store_discount_affiliate' ), 10, 2 );
		add_action( 'rcp_edit_discount', array( $this, 'update_discount_affiliate' ), 10, 2 );

		add_action( 'rcp_add_subscription_form', array( $this, 'subscription_new' ) );
		add_action( 'rcp_edit_subscription_form', array( $this, 'subscription_edit' ) );
		add_action( 'rcp_add_subscription', array( $this, 'store_subscription_rate' ), 10, 2 );
		add_action( 'rcp_edit_subscription_level', array( $this, 'store_subscription_rate' ), 10, 2 );

	}

	/**
	 * Creates the pending referral during signup
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function add_pending_referral( $post_data, $user_id, $price ) {

				$affiliate_discount = false;

		if( ! empty( $_POST['rcp_discount'] ) ) {

			global $wpdb;

			$rcp_discounts = new RCP_Discounts;
			$discount_obj  = $rcp_discounts->get_by( 'code', $_POST['rcp_discount'] );
			$affiliate_id  = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM $wpdb->usermeta WHERE meta_key = %s", 'affwp_discount_rcp_' . $discount_obj->id ) );
			$user_id       = affwp_get_affiliate_user_id( $affiliate_id );
			$discount_aff  = get_user_meta( $user_id, 'affwp_discount_rcp_' . $discount_obj->id, true );
			
			$subscription_key = rcp_get_subscription_key( $user_id );
			$subscription = rcp_get_subscription( $user_id );
			$visit_id    = affiliate_wp()->tracking->get_visit_id();

			if( $discount_aff && affiliate_wp()->tracking->is_valid_affiliate( $affiliate_id ) ) {

				$affiliate_discount = true;

				$this->affiliate_id = $affiliate_id;

				$key = get_user_meta( $user_id, 'rcp_pending_subscription_key', true );
				if( empty( $key ) ) {
					$key = $subscription_key;
				}

				$amount = $this->calculate_referral_amount( $price, $key, absint( $_POST['rcp_level'] ) );

				if( 0 == $amount && affiliate_wp()->settings->get( 'ignore_zero_referrals' ) ) {
				
					if( $this->debug ) {
						$this->log( 'Referral not created due to 0.00 amount.' );
					}

					return false; // Ignore a zero amount referral
				}

				$referral_id = affiliate_wp()->referrals->add( apply_filters( 'affwp_insert_pending_referral', array(
					'amount'       => $amount,
					'reference'    => $subscription_key,
					'description'  => $subscription,
					'affiliate_id' => $this->affiliate_id,
					'context'      => $this->context,
					'campaign'     => affiliate_wp()->tracking->get_campaign(),
				), $amount, $subscription_key, $subscription, $this->affiliate_id, $visit_id, array(), $this->context ) );

			}

		}

		if( $this->was_referred() && ! $affiliate_discount ) {

			$user = get_userdata( $user_id );

			if ( $this->is_affiliate_email( $user->user_email ) ) {
			
				if( $this->debug ) {
					$this->log( 'Referral not created because affiliate\'s own account was used.' );
				}

				return; // Customers cannot refer themselves
			}

			$key = get_user_meta( $user_id, 'rcp_pending_subscription_key', true );
			if( empty( $key ) ) {
				$key = $subscription_key;
			}

			$total = $this->calculate_referral_amount( $price, $key, absint( $_POST['rcp_level'] )  );

			$this->insert_pending_referral( $total, $key, $subscription );
		}

	}

	/**
	 * Retrieves the rate and type for a specific product
	 *
	 * @access  public
	 * @since   1.7
	 * @return  float
	*/
	public function get_product_rate( $level_id = 0, $args = array() ) {

		$rate = get_option( 'affwp_rcp_level_rate_' . $level_id, true );

		if( empty( $rate ) || ! is_numeric( $rate ) ) {

			$rate = null;

		}

		return apply_filters( 'affwp_get_product_rate', $rate, $level_id, $args, $this->affiliate_id, $this->context );

	}

	/**
	 * Sets a referral to complet when a payment is inserted
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function mark_referral_complete( $payment_id, $args, $amount ) {

		$this->complete_referral( $args['subscription_key'] );

	}

	/**
	 * Revokes a referral when the payment is deleted
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function revoke_referral_on_delete( $payment_id = 0 ) {

		if( ! affiliate_wp()->settings->get( 'revoke_on_refund' ) ) {
			return;
		}

		$payments = new RCP_Payments;
		$payment  = $payments->get_payment( $payment_id );
		$this->reject_referral( $payment->subscription_key );

	}

	/**
	 * Builds the reference link for the referrals table
	 *
	 * @access  public
	 * @since   1.0
	*/
	public function reference_link( $reference = 0, $referral ) {

		if( empty( $referral->context ) || 'rcp' != $referral->context ) {

			return $reference;

		}

		$url = admin_url( 'admin.php?page=rcp-payments&s=' . $reference );

		return '<a href="' . esc_url( $url ) . '">' . $reference . '</a>';
	}

	/**
	 * Shows the affiliate drop down on the discount edit / add screens
	 *
	 * @access  public
	 * @since   1.1
	*/
	public function discount_edit( $discount_id = 0 ) {

		global $wpdb;

		add_filter( 'affwp_is_admin_page', '__return_true' );
		affwp_admin_scripts();

		$affiliate_id = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM $wpdb->usermeta WHERE meta_key = %s", 'affwp_discount_rcp_' . $discount_id ) );
		$user_id      = affwp_get_affiliate_user_id( $affiliate_id );
		$user         = get_userdata( $user_id );
		$user_name    = $user ? $user->user_login : '';

?>
		<table class="form-table">
			<tbody>
				<tr class="form-field">
					<th scope="row" valign="top">
						<label for="affiliate_id"><?php _e( 'Affiliate Discount?', 'affiliate-wp' ); ?></label>
					</th>
					<td>
						<span class="affwp-ajax-search-wrap">
							<input type="hidden" name="user_id" id="user_id" value="<?php echo esc_attr( $user_id ); ?>" />
							<input type="text" name="user_name" id="user_name" value="<?php echo esc_attr( $user_name ); ?>" class="affwp-user-search" data-affwp-status="active" autocomplete="off" style="width: 300px;" />
						</span>
						<p class="description"><?php _e( 'If you would like to connect this discount to an affiliate, enter the name of the affiliate it belongs to.', 'affiliate-wp' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
<?php
	}

	/**
	 * Stores the affiliate ID in the discounts meta if it is an affiliate's discount
	 *
	 * @access  public
	 * @since   1.1
	*/
	public function store_discount_affiliate( $args, $discount_id = 0 ) {

		if ( empty( $_POST['user_id'] ) && empty( $_POST['user_name'] ) ) {
			return;
		}

		// Get the user ID. Always retrieve the user ID from the posted user_name field
		if ( ! empty( $_POST['user_name'] ) ) {

			// get user
			$user = get_user_by( 'login', $_POST['user_name'] );

			// If user exists
			if ( $user ) {
				// Make sure they are a valid affiliate
				if ( affwp_is_affiliate( $user->ID ) ) {
					$user_id = absint( $user->ID );
				}

			} else {
				// No user ID
				$user_id = '';
			}

		} else {
			// No user ID
			$user_id = '';
		}

		$affiliate_id = affwp_get_affiliate_id( $user_id );

		update_user_meta( $user_id, 'affwp_discount_rcp_' . $discount_id, $affiliate_id );

	}

	/**
	 * Updates the affiliate ID in the discounts meta if it is an affiliate's discount
	 *
	 * @access  public
	 * @since   1.1
	*/
	public function update_discount_affiliate( $discount_id = 0, $args ) {

		global $wpdb;

		// Bail early if not admin
		if ( ! is_admin() ) {
			return;
		}

		// Get the existing user ID stored with the discount (if any)
		$meta_key              = 'affwp_discount_rcp_' . $discount_id;
		$existing_affiliate_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM wp_usermeta where meta_key = %s", $meta_key ) ) );
		$existing_user_id      = affwp_get_affiliate_user_id( $existing_affiliate_id );

		// Get the user ID. Always retrieve the user ID from the posted user_name field
		if ( ! empty( $_POST['user_name'] ) ) {

			// get user
			$user = get_user_by( 'login', $_POST['user_name'] );

			// If user exists
			if ( $user ) {
				// Make sure they are a valid affiliate
				if ( affwp_is_affiliate( $user->ID ) ) {
					$user_id = absint( $user->ID );
				}

			} else {
				// If an invalid affiliate is entered, set it back to the existing user ID
				$user_id = $existing_user_id;
			}

		} else {
			// No user ID
			$user_id = '';
		}

		// No user ID
		if ( empty( $user_id ) ) {
			// Delete the current user meta if it exists
			delete_user_meta( $existing_user_id, 'affwp_discount_rcp_' . $discount_id );
			// We're done here
			return;
		}

		// Get the affiliate ID from the user ID
		$affiliate_id = absint( affwp_get_affiliate_id( $user_id ) );

		// If an existing affiliate exists and a new affiliate is saved, delete the current meta key
		if ( $existing_affiliate_id && $existing_affiliate_id !== $affiliate_id ) {
			// delete existing meta key for the current affiliate
			delete_user_meta( $existing_user_id, 'affwp_discount_rcp_' . $discount_id );
		}

		// Update user meta with new affiliate
		update_user_meta( $user_id, 'affwp_discount_rcp_' . $discount_id, $affiliate_id );

	}

	/**
	 * Display Affiliate Rate field on add subscription screen
	 *
	 * @access  public
	 * @since   1.7
	*/
	public function subscription_new() {
?>
		<tr class="form-field">
			<th scope="row" valign="top">
				<label for="rcp-affiliate-rate"><?php _e( 'Affiliate Rate', 'affiliate-wp' ); ?></label>
			</th>
			<td>
				<input name="affwp_rcp_level_rate" id="rcp-affiliate-rate" style="width:40px" type="number" min="0"/>
				<p class="description"><?php _e( 'This rate will be used to calculate affiliate earnings when members subscribe to this level. Leave blank to use the site default referral rate.', 'affiliate-wp' ); ?></p>
			</td>
		</tr>
<?php
	}

	/**
	 * Display Affiliate Rate field on subscription edit screen
	 *
	 * @access  public
	 * @since   1.7
	*/
	public function subscription_edit( $level ) {

		$rate = get_option( 'affwp_rcp_level_rate_' . $level->id );
?>
		<tr class="form-field">
			<th scope="row" valign="top">
				<label for="rcp-affiliate-rate"><?php _e( 'Affiliate Rate', 'affiliate-wp' ); ?></label>
			</th>
			<td>
				<input name="affwp_rcp_level_rate" id="rcp-affiliate-rate" style="width:40px" type="number" min="0" value="<?php echo esc_attr( $rate ); ?>"/>
				<p class="description"><?php _e( 'This rate will be used to calculate affiliate earnings when members subscribe to this level. Leave blank to use the site default referral rate.', 'affiliate-wp' ); ?></p>
			</td>
		</tr>
<?php
	}

	/**
	 * Store the rate for the subscription level
	 *
	 * @access  public
	 * @since   1.7
	*/
	public function store_subscription_rate( $level_id = 0, $args ) {

		if( ! empty( $_POST['affwp_rcp_level_rate'] ) ) {

			update_option( 'affwp_rcp_level_rate_' . $level_id, sanitize_text_field( $_POST['affwp_rcp_level_rate'] ) );

		} else {

			delete_option( 'affwp_rcp_level_rate_' . $level_id );

		}

	}

}
new Affiliate_WP_RCP;
