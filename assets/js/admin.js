( function( $ ) {
	$(document.body).on( 'order-totals-recalculate-before', function( e, data ) {
		if( data && $( '#_billing_vat_number' ).length ) {
			data._billing_vat_number = $( '#_billing_vat_number' ).val();
			data._billing_country    = $( '#_billing_country' ).val();
			data._shipping_country   = $( '#_shipping_country' ).val();
			data._billing_postcode   = $( '#_billing_postcode' ).val();
		}
	});
}( jQuery ) );
