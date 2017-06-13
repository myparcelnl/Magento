define(
    [
        'mage/url',
        'uiComponent',
        'jquery',
        'myparcelnl_options_template',
        'myparcelnl_options_css',
        'myparcelnl_lib_myparcel',
        'myparcelnl_lib_moment',
        'myparcelnl_lib_webcomponents'
    ],
    function(mageUrl, uiComponent, jQuery, optionsHtml, optionsCss) {
        'use strict';

        var  originalShippingRate, optionsContainer, isLoaded, myparcel, delivery_options_input, myparcel_method_alias, myparcel_method_element;

        return {
            loadOptions: loadOptions,
            showOptions: showOptions,
            hideOptions: hideOptions
        };

        function loadOptions() {
            if (typeof window.mypa === 'undefined') {
                window.mypa = {isLoaded: false};
            }
            if (window.mypa.isLoaded === false) {
                window.mypa.isLoaded = true;
                isLoaded = setTimeout(function(){
                    window.mypa.isLoaded = false;
                    clearTimeout(isLoaded);

                    jQuery.ajax({
                        url: mageUrl.build('rest/V1/delivery_settings/get'),
                        type: "GET",
                        dataType: 'json'
                    }).done(function (response) {
                        window.mypa.data = response[0].data;
                        if ((myparcel_method_alias = window.mypa.data.general.parent_method) === null) {
                            return void 0;
                        }
                        myparcel_method_element = "input[id^='s_method_" + myparcel_method_alias + "_']";
                        _hideRadios();
                        _appendTemplate();
                        _setParameters();
                        showOptions();
                        _observeFields();
                    });

                }, 50);
            }
        }

        function showOptions() {
            originalShippingRate.hide();
            optionsContainer.show();
        }

        function hideOptions() {
            originalShippingRate.show();
            optionsContainer.hide();
        }

        function _hideRadios() {
            jQuery(myparcel_method_element).parent().parent().hide();
        }

        function _observeFields() {
            delivery_options_input = jQuery("input[name='delivery_options']");

            jQuery("input[id^='s_method']").parent().on('change', function (event) {
                setTimeout(function(){
                    if (jQuery(myparcel_method_element + ':checked').length === 0) {
                        delivery_options_input.val('');
                        myparcel.optionsHaveBeenModified();
                    }
                }, 50);
            });

            delivery_options_input.on('change', function (event) {
                _checkShippingMethod();
            });
        }

        function _setParameters() {
            var data = window.mypa.data;
            window.mypa.settings = {
                deliverydays_window: 10,
                number: '55',
                street: 'Street name',
                postal_code: '2231je',
                price: {
                    morning: data.morning.fee,
                    default: data.general.base_price,
                    night: data.evening.fee,
                    pickup: data.pickup.fee,
                    pickup_express: data.pickup_express.fee,
                    signed: data.delivery.signature_fee,
                    only_recipient: data.delivery.only_recipient_fee,
                    combi_options: data.delivery.signature_and_only_recipient_fee,
                    mailbox: data.mailbox.fee,
                    exclude_delivery_type: data.general.exclude_delivery_types
                },
                base_url: 'https://api.myparcel.nl/delivery_options',
                text:
                    {
                        signed: data.delivery.signature_title,
                        only_recipient: data.delivery.only_recipient_title
                    }
            };

            myparcel = new MyParcel();
            myparcel.updatePage();
        }

        function _appendTemplate() {
            var data = window.mypa.data;
            var baseColor = data.general.color_base;
            var selectColor = data.general.color_select;
            optionsCss = optionsCss.replace(/_base_color_/g, baseColor).replace(/_select_color_/g, selectColor);
            optionsHtml = optionsHtml.replace('<css/>', optionsCss);

            console.log(myparcel_method_alias);
            originalShippingRate = jQuery("td[id^='label_carrier_" + myparcel_method_alias + "_']").parent().find('td');
            optionsContainer = originalShippingRate.parent().parent().prepend('<tr><td colspan="4" id="myparcel_td" style="display:none;"></td></tr>').find('#myparcel_td');
            optionsContainer.html(optionsHtml);
        }

        function _checkShippingMethod() {
            var inputValue, json, type;

            inputValue = delivery_options_input.val();
            if (inputValue === '') {
                return;
            }

            json = jQuery.parseJSON(inputValue);

            if (typeof json.time[0].price_comment !== 'undefined') {
                type = json.time[0].price_comment;
            } else {
                type = json.price_comment;
            }

            switch (type) {
                case "morning":
                    if (json.options.signature) {
                        _checkMethod('#s_method_' + myparcel_method_alias + '_morning_signature');
                    } else {
                        _checkMethod('#s_method_' + myparcel_method_alias + '_morning');
                    }
                    myparcel.showDays();
                    break;
                case "standard":
                    if (json.options.signature && json.options.only_recipient) {
                        _checkMethod('#s_method_' + myparcel_method_alias + '_signature_only_recip');
                    } else {
                        if (json.options.signature) {
                            _checkMethod('#s_method_' + myparcel_method_alias + '_signature');
                        } else if (json.options.only_recipient) {
                            _checkMethod('#s_method_' + myparcel_method_alias + '_only_recipient');
                        } else {
                            _checkMethod('#s_method_flatrate_flatrate');
                        }
                    }
                    myparcel.showDays();
                    break;
                case "night":
                    if (json.options.signature) {
                        _checkMethod('#s_method_' + myparcel_method_alias + '_evening_signature');
                    } else {
                        _checkMethod('#s_method_' + myparcel_method_alias + '_evening');
                    }
                    myparcel.showDays();
                    break;
                case "retail":
                    _checkMethod('#s_method_' + myparcel_method_alias + '_pickup');
                    myparcel.hideDays();
                    break;
                case "retailexpress":
                    _checkMethod('#s_method_' + myparcel_method_alias + '_pickup_express');
                    myparcel.hideDays();
                    break;
                case "mailbox":
                    _checkMethod('#s_method_' + myparcel_method_alias + '_mailbox');
                    myparcel.hideDays();
                    break;
            }
        }

        function _checkMethod(selector) {
            jQuery("input[id^='s_method']").prop("checked", false).change();
            jQuery(selector).prop("checked", true).change().trigger('click');
        }
    }
);