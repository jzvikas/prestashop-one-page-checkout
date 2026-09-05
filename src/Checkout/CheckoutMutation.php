<?php

declare(strict_types=1);

namespace Jzvikas\OnePageCheckout\Checkout;

enum CheckoutMutation: string
{
    case IdentityUpdated = 'identity_updated';
    case AddressSelectionUpdated = 'address_selection_updated';
    case AddressEditorChanged = 'address_editor_changed';
    case AddressBookUpdated = 'address_book_updated';
    case DeliveryAddressUpdated = 'delivery_address_updated';
    case InvoiceAddressUpdated = 'invoice_address_updated';
    case InvoiceModeToggled = 'invoice_mode_toggled';
    case CarrierSelected = 'carrier_selected';
    case CartChanged = 'cart_changed';
    case PaymentSelected = 'payment_selected';
    case AgreementsChanged = 'agreements_changed';
    case FullRefresh = 'full_refresh';
}
