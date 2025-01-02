define(
  [
    'underscore',
    'ko',
    'Magento_Checkout/js/model/shipping-rate-registry',
    'Magento_Checkout/js/action/select-shipping-method',
    'Magento_Checkout/js/model/quote',
    'MyParcelNL_Magento/js/model/checkout',
    'MyParcelNL_Magento/js/polyfill/array_prototype_find',
    'MyParcelNL_Magento/js/vendor/object-path',
    'myparcelDeliveryOptions',
    'leaflet',
    'jquery'
  ],
  function(
    _,
    ko,
    shippingRateRegistry,
    selectShippingMethodAction,
    quote,
    checkout,
    array_prototype_find,
    objectPath,
    myparcel,
    leaflet,
    $
  ) {
    'use strict';

    var deliveryOptions;

    deliveryOptions = {
      rendered: ko.observable(false),

      splitStreetRegex: /(.*?)\s?(\d{1,5})[/\s-]{0,2}([A-z]\d{1,3}|-\d{1,4}|\d{2}\w{1,2}|[A-z][A-z\s]{0,3})?$/,

      disableDeliveryOptionsEvent: 'myparcel_disable_delivery_options',
      hideDeliveryOptionsEvent: 'myparcel_hide_delivery_options',
      renderDeliveryOptionsEvent: 'myparcel_render_delivery_options',
      showDeliveryOptionsEvent: 'myparcel_show_delivery_options',
      updateConfigEvent: 'myparcel_update_config',
      updateDeliveryOptionsEvent: 'myparcel_update_delivery_options',

      updatedDeliveryOptionsEvent: 'myparcel_updated_delivery_options',
      updatedAddressEvent: 'myparcel_updated_address',

      disableDelivery: 'myparcel-delivery-options__delivery--deliver',
      disablePickup: 'myparcel-delivery-options__delivery--pickup',

      isUsingMyParcelMethod: true,
      deliveryOptionsAreVisible: false,

      /**
       * The selector of the field we use to get the delivery options data into the order.
       *
       * @type {string}
       */
      hiddenDataInput: '[name="myparcel_delivery_options"]',

      /**
       * Initialize the script. Render the delivery options div, request the plugin settings, then initialize listeners.
       */
      initialize: function() {
        window.MyParcelConfig.address = deliveryOptions.getAddress(quote.shippingAddress());
        deliveryOptions.setToRenderWhenVisible();
        deliveryOptions.addListeners();

        deliveryOptions.rendered.subscribe(function(bool) {
          if (bool) {
            deliveryOptions.updateAddress();
          }
        });
      },

      setToRenderWhenVisible: function() {
        var shippingMethodDiv = document.getElementById('checkout-shipping-method-load');
        /**
         * Sometimes the shipping method div doesn't exist yet. Retry in 100ms if it happens.
         */
        if (!shippingMethodDiv) {
          setTimeout(function() {
            deliveryOptions.setToRenderWhenVisible();
          }, 100);
          return;
        }

        if (!('IntersectionObserver' in window) ||
          !('IntersectionObserverEntry' in window) ||
          !('intersectionRatio' in window.IntersectionObserverEntry.prototype)
        ) {
          deliveryOptions.render();
          return;
        }

        const observer = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (entry.intersectionRatio === 0 || deliveryOptions.deliveryOptions || this.deliveryOptionsAreVisible) {
              return;
            }
            deliveryOptions.render();
            this.deliveryOptionsAreVisible = true;
          }, {
            root: null,
            rootMargin: '0px',
            threshold: .1
          });
        });
        observer.observe(shippingMethodDiv);
      },

      destroy: function() {
        document.querySelector(deliveryOptions.hiddenDataInput).value = '';
        deliveryOptions.triggerEvent(deliveryOptions.hideDeliveryOptionsEvent);
      },

      /**
       * Create the div the delivery options will be rendered in, if it doesn't exist yet.
       */
      render: function() {
        var hasUnrenderedDiv = document.querySelector('#myparcel-delivery-options');
        var hasRenderedDeliveryOptions = document.querySelector('.myparcel-delivery-options__table');
        var shippingMethodDiv = document.getElementById('checkout-shipping-method-load');
        var deliveryOptionsDiv = document.createElement('div');

        checkout.hideShippingMethods();
        deliveryOptions.rendered(false);

        if (hasUnrenderedDiv || hasRenderedDeliveryOptions) {
          deliveryOptions.triggerEvent(deliveryOptions.updateDeliveryOptionsEvent);
        } else if (!hasUnrenderedDiv) {
          deliveryOptionsDiv.setAttribute('id', 'myparcel-delivery-options');
          shippingMethodDiv.insertBefore(deliveryOptionsDiv, shippingMethodDiv.firstChild);
          deliveryOptions.triggerEvent(deliveryOptions.renderDeliveryOptionsEvent);
        }

        deliveryOptions.rendered(true);
      },

      /**
       * Add event listeners to shipping methods and address as well as the delivery options module.
       */
      addListeners: function() {
        checkout.configuration.subscribe(deliveryOptions.updateConfig);
        quote.shippingAddress.subscribe(deliveryOptions.updateAddress);
        quote.shippingMethod.subscribe(_.debounce(deliveryOptions.onShippingMethodUpdate));

        document.addEventListener(
          deliveryOptions.updatedDeliveryOptionsEvent,
          deliveryOptions.onUpdatedDeliveryOptions
        );
      },

      /**
       * Run the split street regex on the given full address to extract the house number and return it.
       *
       * @param {string} address - Full address.
       *
       * @returns {integer|null} - The house number, if found. Otherwise null.
       */
      getHouseNumber: function(address) {
        var result = deliveryOptions.splitStreetRegex.exec(address);
        var numberIndex = 2;
        return result ? parseInt(result[numberIndex]) : null;
      },

      /**
       * Trigger an event on the document body.
       *
       * @param {string} identifier - Name of the event.
       */
      triggerEvent: function(identifier) {
        //document.body.dispatchEvent(new CustomEvent(identifier, { bubbles: true, cancelable: false }));
        var event = document.createEvent('HTMLEvents');
        event.initEvent(identifier, true, false);
        document.querySelector('body').dispatchEvent(event);
      },

      /**
       * Get address data and put it in the global MyParcelConfig.
       *
       * @param {Object?} address - Quote.shippingAddress from Magento.
       */
      updateAddress: function(address) {
        if (!deliveryOptions.isUsingMyParcelMethod) {
          return;
        }

        const newAddress = deliveryOptions.getAddress(address || quote.shippingAddress());
        if (_.isEqual(newAddress, window.MyParcelConfig.address)) {
          return;
        }

        window.MyParcelConfig.address = newAddress;

        deliveryOptions.triggerEvent(deliveryOptions.showDeliveryOptionsEvent);
        deliveryOptions.triggerEvent(deliveryOptions.updateDeliveryOptionsEvent);
      },

      /**
       * Get the address entered by the user depending on if they are logged in or not.
       *
       * @returns {Object}
       * @param {Object} address - Quote.shippingAddress from Magento.
       */
      getAddress: function(address) {
        return {
          number: address.street ? deliveryOptions.getHouseNumber(address.street.join(' ')) : '',
          cc: address.countryId || '',
          postalCode: address.postcode || '',
          city: address.city || '',
          street: address.street ? [address.street[0], address.street[1]].join(' ').trim() : ''
        };
      },

      /**
       * Triggered when the delivery options have been updated. Put the received data in the created data input. Then
       * do the request that tells us which shipping method needs to be selected.
       *
       * @param {CustomEvent} event - The event that was sent.
       */
      onUpdatedDeliveryOptions: function(event) {
        var element = document.getElementsByClassName('checkout-shipping-method').item(0);
        var displayStyle = window.getComputedStyle(element, null).display;

        if ('none' === displayStyle) {
          return;
        }

        deliveryOptions.deliveryOptions = event.detail;
        document.querySelector(deliveryOptions.hiddenDataInput).value = JSON.stringify(event.detail);

        /**
         * If the delivery options were emptied, don't request a new shipping method.
         */
        if (JSON.stringify(deliveryOptions.deliveryOptions) === '{}') {
          return;
        }

        deliveryOptions.setShippingMethod(event.detail);
        deliveryOptions.disabledDeliveryPickupRadio();
      },

      /**
       * @param options
       */
      setShippingMethod: function(options) {
        $('body').trigger('processStart');
        if (options) {
          options.packageType = checkout.bestPackageType;
        }
        checkout.convertDeliveryOptionsToShippingMethod(options, {
          onSuccess: function(response) {
            $('body').trigger('processStop');
            if (!response.length) {
              return;
            }
            selectShippingMethodAction(response[0]);
          },
        });
      },

      /**
       * Note: If you only have one option, so either "delivery" or "pickup", the option will appear disabled.
       * Until there's a built in solution, there's the following workaround.
       */
      disabledDeliveryPickupRadio: function() {
        var delivery = document.getElementById(deliveryOptions.disableDelivery);
        var pickup = document.getElementById(deliveryOptions.disablePickup);

        if (delivery) {
          delivery.disabled = false;
        }

        if (pickup) {
          pickup.disabled = false;
        }
      },

      /**
       * Change the shipping method and disable the delivery options if needed.
       *
       * @param {Object} selectedShippingMethod - The shipping method that was selected.
       */
      onShippingMethodUpdate: function(selectedShippingMethod) {
        const newShippingMethod = selectedShippingMethod || {},
            available = newShippingMethod.available || false,
            carrierCode = newShippingMethod.carrier_code || '',
            myparcelCarrierCode = checkout.carrierCode;
        checkout.hideShippingMethods();

        if (!checkout.hasDeliveryOptions() || !available) {
          return;
        }

        if (carrierCode !== myparcelCarrierCode) {
            deliveryOptions.triggerEvent(deliveryOptions.disableDeliveryOptionsEvent);
            deliveryOptions.isUsingMyParcelMethod = false;
            return;
        }

        deliveryOptions.shippingMethod = newShippingMethod;
        deliveryOptions.isUsingMyParcelMethod = true;
      },

      /**
       * For use when magic decimals appear...
       *
       * @param {number} number
       * @param {number} decimals
       * @returns {number}
       *
       * @see https://stackoverflow.com/a/10474209
       */
      roundNumber: function(number, decimals) {
        var newNumber = Number(String(number)).toFixed(decimals);
        return parseFloat(newNumber);
      },

      updateConfig: function() {
        if (!window.MyParcelConfig.hasOwnProperty('address')) {
          window.MyParcelConfig.address = deliveryOptions.getAddress(quote.shippingAddress());
        }
        deliveryOptions.triggerEvent(deliveryOptions.updateConfigEvent);
      },
    };

    return deliveryOptions;
  }
);
