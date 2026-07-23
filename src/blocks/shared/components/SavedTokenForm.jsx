import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { createCvcToken } from '../utils/tokenize';

/**
 * CVC entry form for a saved Paygent card, registered as WooCommerce Blocks'
 * `savedTokenComponent`. It is rendered by WC Blocks below the selected saved
 * token radio option (the gateway's `content` component is not mounted then).
 *
 * WC Blocks sets the initial paymentMethodData (including the
 * `wc-{gateway}-payment-token` key) when the token radio is selected, but a
 * successful onPaymentSetup observer response REPLACES that data — so this
 * component must re-send the token key itself alongside the CVC token.
 *
 * @param {Object}   props
 * @param {string}   props.gatewayId         Gateway ID ('paygent_cc' | 'paygent_mccc').
 * @param {string}   props.merchantId        Paygent merchant ID.
 * @param {string}   props.tokenKey          Paygent token key.
 * @param {Array}    props.paymentOptions    { value, label } installment options ([] to hide).
 * @param {string}   props.token             Selected WC token id (injected by WC Blocks).
 * @param {Object}   props.eventRegistration Injected by WC Blocks.
 * @param {Object}   props.emitResponse      Injected by WC Blocks.
 */
const SavedTokenForm = ( {
	gatewayId,
	merchantId,
	tokenKey,
	paymentOptions = [],
	token,
	eventRegistration,
	emitResponse,
} ) => {
	const [ cvc,           setCvc           ] = useState( '' );
	const [ paymentOption, setPaymentOption ] = useState( '' );

	const showPaymentSelector = paymentOptions.length > 1;
	const defaultOption       = paymentOptions[ 0 ]?.value ?? '109';

	// Initialize the installment selection once options are available; the
	// guard keeps an explicit user choice from being overwritten.
	useEffect( () => {
		if ( ! paymentOption && paymentOptions.length ) {
			setPaymentOption( paymentOptions[ 0 ].value );
		}
	}, [ paymentOption, paymentOptions ] );

	// Re-register on every state change so the closure captures current values.
	useEffect( () => {
		const unsubscribe = eventRegistration.onPaymentSetup( async () => {
			try {
				const cvcRes = await createCvcToken( merchantId, tokenKey, cvc );

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							// Re-send the token reference: a success response
							// replaces the data set on token selection.
							token,
							payment_method:                       gatewayId,
							[ `wc-${ gatewayId }-payment-token` ]: String( token ),
							isSavedToken:                         true,
							[ `${ gatewayId }-token` ]:           '',
							[ `${ gatewayId }-cvc_token` ]:       cvcRes.tokenizedCardObject.token,
							card_type:                            '',
							paygent_save_card_info:               'no',
							paygent_cardholder_name:              '',
							number_of_payments:                   paymentOption || defaultOption,
						},
					},
				};
			} catch ( err ) {
				const msg = err?.responseDetail
					? decodeEntities( err.responseDetail )
					: __( 'Card authentication failed. Please check your input.', 'woocommerce-for-paygent-payment-main' );
				return { type: emitResponse.responseTypes.ERROR, message: msg };
			}
		} );

		return unsubscribe;
	}, [
		eventRegistration, emitResponse,
		cvc, paymentOption,
		merchantId, tokenKey, gatewayId, token, defaultOption,
	] );

	return (
		<div className="wc-paygent-cc-form wc-paygent-saved-token-form">
			<div className="wc-paygent-cc-field">
				<label className="wc-paygent-cc-label" htmlFor={ `${ gatewayId }-saved-token-cvc` }>
					{ __( 'Security code', 'woocommerce-for-paygent-payment-main' ) }
					<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
				</label>
				<input
					id={ `${ gatewayId }-saved-token-cvc` }
					className="wc-paygent-cc-input"
					type="tel"
					inputMode="numeric"
					maxLength={ 4 }
					placeholder="•••"
					value={ cvc }
					onChange={ ( e ) => setCvc( e.target.value.replace( /\D/g, '' ).slice( 0, 4 ) ) }
					autoComplete="cc-csc"
					aria-required="true"
					aria-label={ __( 'Security code (3–4 digits on card back)', 'woocommerce-for-paygent-payment-main' ) }
				/>
			</div>

			{ showPaymentSelector && (
				<div className="wc-paygent-cc-field wc-paygent-cc-field--payment-method">
					<label className="wc-paygent-cc-label" htmlFor={ `${ gatewayId }-saved-token-payment-method` }>
						{ __( 'Payment method', 'woocommerce-for-paygent-payment-main' ) }
					</label>
					<select
						id={ `${ gatewayId }-saved-token-payment-method` }
						className="wc-paygent-cc-select"
						value={ paymentOption }
						onChange={ ( e ) => setPaymentOption( e.target.value ) }
					>
						{ paymentOptions.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>
								{ opt.label }
							</option>
						) ) }
					</select>
				</div>
			) }
		</div>
	);
};

export default SavedTokenForm;
