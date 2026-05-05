import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { PaymentLabel, PaymentDescription } from '../shared/components';

const settings = getSetting( 'paygent_cs_data', null );
if ( ! settings ) {
	throw new Error( 'paygent_cs_data not found' );
}

/**
 * Store selector rendered inside the WooCommerce Block checkout.
 *
 * Renders a <select> with the convenience stores enabled in the gateway
 * admin settings.  The selected cvs_company_id is passed to PHP's
 * process_payment() via paymentMethodData on order placement.
 *
 * @param {{ eventRegistration: object, emitResponse: object }} props
 */
const ConvenienceStoreForm = ( { eventRegistration, emitResponse } ) => {
	const { csStores } = settings;
	const stores = csStores || [];

	const [ selectedStore, setSelectedStore ] = useState( stores[ 0 ]?.id ?? '' );

	useEffect( () => {
		const unsubscribe = eventRegistration.onPaymentSetup( () => {
			if ( ! selectedStore ) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: __( 'Please select a convenience store.', 'woocommerce-for-paygent-payment-main' ),
				};
			}
			return {
				type: emitResponse.responseTypes.SUCCESS,
				meta: {
					paymentMethodData: {
						cvs_company_id: selectedStore,
					},
				},
			};
		} );

		return unsubscribe;
	}, [ eventRegistration, emitResponse, selectedStore ] );

	if ( stores.length === 0 ) {
		return (
			<p className="wc-paygent-select-form__empty">
				{ __( 'No convenience stores are currently available.', 'woocommerce-for-paygent-payment-main' ) }
			</p>
		);
	}

	return (
		<div className="wc-paygent-select-form">
			<PaymentDescription settings={ settings } />
			<div className="wc-paygent-select-form__field">
				<label className="wc-paygent-select-form__label" htmlFor="paygent-cs-store">
					{ __( 'Select convenience store', 'woocommerce-for-paygent-payment-main' ) }
					<span className="wc-paygent-select-form__required" aria-hidden="true">*</span>
				</label>
				<select
					id="paygent-cs-store"
					className="wc-paygent-select-form__select"
					value={ selectedStore }
					onChange={ ( e ) => setSelectedStore( e.target.value ) }
					aria-required="true"
				>
					{ stores.map( ( store ) => (
						<option key={ store.id } value={ store.id }>
							{ store.label }
						</option>
					) ) }
				</select>
			</div>
		</div>
	);
};

registerPaymentMethod( {
	name:    'paygent_cs',
	label:   <PaymentLabel settings={ settings } />,
	content: <ConvenienceStoreForm />,
	edit:    <PaymentDescription settings={ settings } />,
	canMakePayment: () => true,
	ariaLabel: settings.title || __( 'Convenience store payment', 'woocommerce-for-paygent-payment-main' ),
	supports: {
		features: settings.supports || [ 'products' ],
	},
} );
