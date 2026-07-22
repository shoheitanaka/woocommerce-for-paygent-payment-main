import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { PaymentLabel, PaymentDescription } from '../shared/components';
import SavedTokenForm from '../shared/components/SavedTokenForm';
import { createCardToken, createCvcToken, detectCardType } from '../shared/utils/tokenize';

const settings = getSetting( 'paygent_mccc_data', null );
if ( ! settings ) {
	throw new Error( 'paygent_mccc_data not found' );
}

// ─── Input formatters ─────────────────────────────────────────────────────────

function formatCardNumber( raw ) {
	return raw
		.replace( /\D/g, '' )
		.slice( 0, 16 )
		.replace( /(.{4})(?=.)/g, '$1 ' );
}

function formatExpiry( raw, prev ) {
	const digits     = raw.replace( /\D/g, '' ).slice( 0, 4 );
	const isDeleting = raw.length < prev.length;

	if ( digits.length > 2 ) {
		return digits.slice( 0, 2 ) + ' / ' + digits.slice( 2 );
	}
	if ( digits.length === 2 && ! isDeleting ) {
		return digits + ' / ';
	}
	return digits;
}

// ─── McccCardForm component ───────────────────────────────────────────────────

/**
 * MCCC card entry form rendered inside the WooCommerce Block checkout.
 *
 * Identical to the CC form except:
 *  - Field names use the `paygent_mccc-` prefix (paygent_mccc-token, paygent_mccc-cvc_token)
 *  - No installment payment selector (MCCC always applies payment_class = 10 server-side)
 *
 * Saved cards are NOT handled here: WC Blocks renders them natively as
 * top-level payment options (showSavedCards) and SavedTokenForm collects
 * the CVC when one is selected.
 *
 * @param {{ eventRegistration: object, emitResponse: object, shouldSavePayment: boolean }} props
 */
const McccCardForm = ( { eventRegistration, emitResponse, shouldSavePayment } ) => {
	const {
		merchantId,
		tokenKey,
		isTds2,
	} = settings;

	const [ cardNumber,     setCardNumber     ] = useState( '' );
	const [ expiry,         setExpiry         ] = useState( '' );
	const [ cvc,            setCvc            ] = useState( '' );
	const [ cardholderName, setCardholderName ] = useState( '' );

	useEffect( () => {
		const unsubscribe = eventRegistration.onPaymentSetup( async () => {
			try {
				const expClean = expiry.replace( /\s/g, '' ).replace( '/', '' );
				const expMonth = expClean.slice( 0, 2 );
				const expYear  = expClean.slice( 2, 4 );

				const [ tokenRes, cvcRes ] = await Promise.all( [
					createCardToken( merchantId, tokenKey, { number: cardNumber, expMonth, expYear, cvc } ),
					createCvcToken( merchantId, tokenKey, cvc ),
				] );

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							'paygent-use-stored-payment-info': 'no',
							'paygent_mccc-token':              tokenRes.tokenizedCardObject.token,
							'paygent_mccc-cvc_token':          cvcRes.tokenizedCardObject.token,
							'card_type':                       detectCardType( cardNumber ),
							'paygent_save_card_info':          shouldSavePayment ? 'yes' : 'no',
							'paygent_cardholder_name':         isTds2 ? cardholderName : '',
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
		cardNumber, expiry, cvc, cardholderName,
		shouldSavePayment,
		merchantId, tokenKey, isTds2,
	] );

	// ── Render ─────────────────────────────────────────────────────────────────
	return (
		<div className="wc-paygent-cc-form">

			{ /* ── New card section ── */ }
			<div className="wc-paygent-new-card-section">

					{ /* Row 1: Card number */ }
					<div className="wc-paygent-cc-field">
						<label className="wc-paygent-cc-label" htmlFor="paygent-mccc-number">
							{ __( 'Card number', 'woocommerce-for-paygent-payment-main' ) }
							<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
						</label>
						<input
							id="paygent-mccc-number"
							className="wc-paygent-cc-input wc-paygent-cc-input--card-number"
							type="tel"
							inputMode="numeric"
							placeholder="0000 0000 0000 0000"
							value={ cardNumber }
							onChange={ ( e ) => setCardNumber( formatCardNumber( e.target.value ) ) }
							autoComplete="cc-number"
							aria-required="true"
						/>
					</div>

					{ /* Row 2: Expiry + CVC */ }
					<div className="wc-paygent-cc-row wc-paygent-cc-row--exp-cvc">
						<div className="wc-paygent-cc-field">
							<label className="wc-paygent-cc-label" htmlFor="paygent-mccc-expiry">
								{ __( 'Expiry date', 'woocommerce-for-paygent-payment-main' ) }
								<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
							</label>
							<input
								id="paygent-mccc-expiry"
								className="wc-paygent-cc-input"
								type="tel"
								inputMode="numeric"
								placeholder="MM / YY"
								value={ expiry }
								onChange={ ( e ) => setExpiry( formatExpiry( e.target.value, expiry ) ) }
								autoComplete="cc-exp"
								aria-required="true"
							/>
						</div>
						<div className="wc-paygent-cc-field">
							<label className="wc-paygent-cc-label" htmlFor="paygent-mccc-cvc">
								{ __( 'Security code', 'woocommerce-for-paygent-payment-main' ) }
								<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
							</label>
							<input
								id="paygent-mccc-cvc"
								className="wc-paygent-cc-input"
								type="tel"
								inputMode="numeric"
								maxLength={ 4 }
								placeholder="•••"
								value={ cvc }
								onChange={ ( e ) => setCvc( e.target.value.replace( /\D/g, '' ).slice( 0, 4 ) ) }
								autoComplete="cc-csc"
								aria-required="true"
							/>
						</div>
					</div>

					{ /* Row 3: Cardholder name (3DS2 only) */ }
					{ isTds2 && (
						<div className="wc-paygent-cc-field">
							<label className="wc-paygent-cc-label" htmlFor="paygent-mccc-cardholder">
								{ __( 'Cardholder name', 'woocommerce-for-paygent-payment-main' ) }
								<span className="wc-paygent-cc-label__hint">{ __( '(Latin alphabet only)', 'woocommerce-for-paygent-payment-main' ) }</span>
								<span className="wc-paygent-cc-label__required" aria-hidden="true">*</span>
							</label>
							<input
								id="paygent-mccc-cardholder"
								className="wc-paygent-cc-input"
								type="text"
								placeholder="TARO YAMADA"
								pattern="[a-zA-Z\s]+"
								value={ cardholderName }
								onChange={ ( e ) => setCardholderName( e.target.value.toUpperCase() ) }
								autoComplete="cc-name"
								aria-required="true"
							/>
						</div>
					) }

			</div>
		</div>
	);
};

// ─── Register ─────────────────────────────────────────────────────────────────

registerPaymentMethod( {
	name:  'paygent_mccc',
	label: <PaymentLabel settings={ settings } />,
	content: <McccCardForm />,
	edit:    <PaymentDescription settings={ settings } />,
	savedTokenComponent: (
		<SavedTokenForm
			gatewayId="paygent_mccc"
			merchantId={ settings.merchantId }
			tokenKey={ settings.tokenKey }
			paymentOptions={ [] }
		/>
	),
	canMakePayment: () => true,
	ariaLabel: settings.title || __( 'Multi-currency credit card', 'woocommerce-for-paygent-payment-main' ),
	supports: {
		features:       settings.supports || [ 'products' ],
		showSavedCards: !! settings.enableSaveCard,
		showSaveOption: !! settings.enableSaveCard,
	},
} );
