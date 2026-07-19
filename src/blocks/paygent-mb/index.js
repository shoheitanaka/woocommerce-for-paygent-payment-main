import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { PaymentLabel, PaymentDescription } from '../shared/components';

const settings = getSetting( 'paygent_mb_data', null );
if ( ! settings ) {
	throw new Error( 'paygent_mb_data not found' );
}

/**
 * Carrier selector rendered inside the WooCommerce Block checkout.
 *
 * Renders a <select> with the carriers enabled in the gateway admin settings.
 * The selected career_type is passed to PHP's process_payment() via
 * paymentMethodData on order placement.
 *
 * The PHP gateway reads the value via intval(), so string '04' → 4.
 *
 * @param {{ eventRegistration: object, emitResponse: object }} props
 */
const CarrierForm = ( { eventRegistration, emitResponse } ) => {
	const { carrierTypes } = settings;
	const carriers = carrierTypes || [];

	const [ selectedCarrier, setSelectedCarrier ] = useState( carriers[ 0 ]?.id ?? '' );

	useEffect( () => {
		const unsubscribe = eventRegistration.onPaymentSetup( () => {
			if ( ! selectedCarrier ) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: __( 'Please select a carrier.', 'woocommerce-for-paygent-payment-main' ),
				};
			}
			return {
				type: emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						career_type: selectedCarrier,
					},
				},
			};
		} );

		return unsubscribe;
	}, [ eventRegistration, emitResponse, selectedCarrier ] );

	if ( carriers.length === 0 ) {
		return (
			<p className="wc-paygent-select-form__empty">
				{ __( 'No carriers are currently available.', 'woocommerce-for-paygent-payment-main' ) }
			</p>
		);
	}

	return (
		<div className="wc-paygent-select-form">
			<PaymentDescription settings={ settings } />
			<div className="wc-paygent-select-form__field">
				<label className="wc-paygent-select-form__label" htmlFor="paygent-mb-carrier">
					{ __( 'Select carrier', 'woocommerce-for-paygent-payment-main' ) }
					<span className="wc-paygent-select-form__required" aria-hidden="true">*</span>
				</label>
				<select
					id="paygent-mb-carrier"
					className="wc-paygent-select-form__select"
					value={ selectedCarrier }
					onChange={ ( e ) => setSelectedCarrier( e.target.value ) }
					aria-required="true"
				>
					{ carriers.map( ( carrier ) => (
						<option key={ carrier.id } value={ carrier.id }>
							{ carrier.label }
						</option>
					) ) }
				</select>
			</div>
		</div>
	);
};

registerPaymentMethod( {
	name:    'paygent_mb',
	label:   <PaymentLabel settings={ settings } />,
	content: <CarrierForm />,
	edit:    <PaymentDescription settings={ settings } />,
	canMakePayment: () => true,
	ariaLabel: settings.title || __( 'Carrier payment', 'woocommerce-for-paygent-payment-main' ),
	supports: {
		features: settings.supports || [ 'products' ],
	},
} );
