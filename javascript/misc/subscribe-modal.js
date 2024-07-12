/**
 * @package       Kurufootwear\SegmentAnalytics
 * @author        magento.dev <magento.dev@onetree.com>
 * @copyright     Copyright (©) 2021 KURU Footwear
 */
define([
    'jquery',
    'Magento_Ui/js/modal/modal',
    'mage/url',
    'arrive',
    'jquery/ui',
    'Magento_Swatches/js/swatch-renderer',
    'domReady!',
], function ($, modal, mageUrl) {
    'use strict';

    /**
     * Override to track sendSubscriptionToMagento's success responses
     */
    return function (config) {
        $(document).ready(function () {
            var swatchModal = $('.swatch-opt-modal');
            var spinner = $('#loader-spinner-html');
            setupModal();

            var sku = config.sku;
            var email = null;
            var color = null;
            var width = null;
            var size = null;
            var productUrl = null;

            var bisModal = $('#open_modal_subscribe');
            bisModal.click(function (e) {
                localStorage.setItem('open_modal_subscribe', 'true');
                loadSwatches();
                enableSubscribe(true);
                $('#popup-modal').modal('openModal');
                // Remove size info
                var sizeInfo = $('#popup-modal .size-info-wrapper');
                if (sizeInfo.length) {
                    sizeInfo.remove();
                }
                // preselect PDP color
                var colorPDP = $('.swatch-opt .color .selected');
                if (colorPDP.length) {
                    var optionID = colorPDP.attr('option-id');
                    var colorModal = $('.swatch-opt-modal .color .image[option-id="' + optionID + '"]');
                    if (colorModal.length) {
                        colorModal.click();
                    }
                }
                e.preventDefault();
            });

            if (localStorage.getItem('open_modal_subscribe') === 'true') {
                bisModal.click();
            }

            /**
             * Setup modal
             */
            function setupModal() {
                var options = {
                    type: 'popup',
                    responsive: true,
                    innerScroll: true,
                    closed: function () {
                        localStorage.removeItem('open_modal_subscribe');
                    },
                    buttons: [
                        {
                            text: $.mage.__('Submit'),
                            class: 'action primary tocart black-button subscribe-button',
                            click: function () {
                                loadSelectedValues();
                                if (validateSubscription()) {
                                    enableSubscribe(false);
                                    subscribeAjax();
                                }
                            },
                        },
                    ],
                };

                modal(options, $('#popup-modal'));

                var optionsSuccess = {
                    type: 'popup',
                    responsive: true,
                    innerScroll: true,
                };
                modal(optionsSuccess, $('#popup-modal-success'));
            }

            /**
             * Load color, width and size
             */
            function loadSelectedValues() {
                color = null;
                width = null;
                size = null;
                productUrl = window.location.href;
                var colorSelected = $('.swatch-opt-modal .color .selected');
                if (colorSelected.length) {
                    color = colorSelected.attr('option-label');
                }

                var widthSelected = $('.swatch-opt-modal .width .checked');
                if (widthSelected.length) {
                    width = widthSelected.text().trim();
                }

                var sizeSelected = $('.swatch-opt-modal .size .selected');
                if (sizeSelected.length === 0) {
                    sizeSelected = $('.swatch-opt-modal .womens_size .selected');
                }
                if (sizeSelected.length === 0) {
                    sizeSelected = $('.swatch-opt-modal .socks_size .selected');
                }
                if (sizeSelected.length) {
                    size = sizeSelected.attr('option-value');
                }
                email = $('#subscribe_email').val().trim();
            }

            /**
             * Validate email format
             */
            function validateEmail(email) {
                const re =
                    /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
                return re.test(String(email).toLowerCase());
            }

            /**
             * Show error messages
             */
            function showError(error) {
                var missing_options = $('#missing_options_modal');
                missing_options.html(error);
                missing_options.fadeIn();
                setTimeout(function () {
                    missing_options.fadeOut();
                }, 10000);
            }

            /**
             * Show required label
             */
            function showRequiredLabel(labelCode) {
                $('span.required_asterisk.' + labelCode).css('display', 'inline-block');
            }

            /**
             * Validate subscription data
             */
            function validateSubscription() {
                $('span.required_asterisk').hide();
                $('#missing_options_modal').hide();
                var error = null;

                if (!sku || sku.length === 0 || !productUrl || productUrl.length === 0) {
                    error = 'Sorry, some fields are missing or incorrect.';
                }
                if (!email || !validateEmail(email)) {
                    error = 'Invalid email address.';
                    showRequiredLabel('email');
                }
                if (!color || color.length === 0) {
                    error = 'Color is required.';
                    showRequiredLabel('color');
                }
                if (hasWidth()) {
                    if (!width || width.length === 0) {
                        error = 'Width is required.';
                        showRequiredLabel('width');
                    }
                }
                if (!size || size.length === 0) {
                    error = 'Size is required.';
                    showRequiredLabel('size');
                    showRequiredLabel('womens_size');
                }
                showError(error);

                return error === null;
            }

            /**
             * Load the swatches if necessary and hide loading spinner
             **/
            function loadSwatches() {
                // Load swatch renderer only if necessary
                if (swatchModal.children().length === 0) {
                    swatchModal.SwatchRenderer({
                        showSizeOutStock: true,
                        showRequiredItems: true,
                        titleSuffix: config.titleSuffix,
                        productId: config.productId,
                        jsonConfigAjaxUrl: config.jsonConfigAjaxUrl,
                        jsonConfig: config.jsonConfig, // This is only used as a fallback if AJAX fails
                        jsonSwatchConfig: config.jsonSwatchConfig,
                        mediaCallback: config.mediaCallback,
                        gallerySwitchStrategy: config.gallerySwitchStrategy,
                        discontinuedColorsEnabled: true,
                    });
                }
                // Hide spinner after loading product images
                swatchModal.on('swatch.load_product_media.finish', function () {
                    spinner.hide();
                });

                // Sometimes the 'swatch.load_product_media.finish' event is not triggered,
                // therefore we must watch that the swatches images are loaded in the modal to hide the spinner (KURU2-2739)
                var attempts = 0;
                var swatchColorAppear = setInterval(function () {
                    if (swatchModal.find('.swatch-attribute.color').length) {
                        clearInterval(swatchColorAppear);
                        spinner.hide();
                    }
                    if (attempts >= 20) {
                        clearInterval(swatchColorAppear);
                        spinner.hide();
                    }
                    attempts++;
                }, 500);
            }

            /**
             * Enable and disable subscribe button
             */
            function enableSubscribe(enable) {
                var button = $('.subscribe-button');
                if (enable) {
                    button.removeAttr('disabled');
                } else {
                    button.attr('disabled', true);
                }
            }

            /**
             * Ajax submit
             */
            function subscribeAjax() {
                var errorMessage = $('.error-message');
                errorMessage.html('').hide();
                if (config.isListrakEnabled) {
                    sendSubscriptionToListrak(email);
                }
                sendSubscriptionToMagento();

                // Subscribe to newsletter
                if ($('#cbox-newsletter:checked').length) {
                    sendSubscriptionNewsletter(email);
                }
            }

            /**
             * Check if the product has width
             */
            function hasWidth() {
                return $('.swatch-opt-modal .socks_size .selected').length === 0;
            }

            /**
             * Send Subscription to Magento
             */
            function sendSubscriptionToMagento() {
                var errorMessage = $('.error-message');
                const url = config.backInStockAPI;
                const colorAttrId = $('[attribute-code="color"]').attr('attribute-id');
                const colorAttrIdSelected = $('[attribute-code="color"]').attr('option-selected');
                const sizeAttrId = $('[attribute-code="size"]').eq(1).attr('attribute-id');
                const sizeAttrIdSelected = $('[attribute-code="size"]').eq(1).attr('option-selected');
                let simpleProductId = '';

                $.each(config.jsonConfig.index, function (key, attributes) {
                    if (
                        attributes[colorAttrId] === colorAttrIdSelected &&
                        attributes[sizeAttrId] === sizeAttrIdSelected
                    ) {
                        simpleProductId = key;
                        return false;
                    }
                });

                $.ajax({
                    url: url,
                    type: 'POST',
                    showLoader: true,
                    data: JSON.stringify({
                        parentSku: simpleProductId,
                        email: email,
                        sku: sku,
                        size: size,
                        color: color,
                        width: width,
                        productUrl: productUrl,
                    }),
                    dataType: 'json',
                    contentType: 'application/json; charset=utf-8',
                    success: function (response) {
                        if (!response) {
                            errorMessage.html(
                                '<span>Sorry but we were unable to add a notification at this time. Please try again later or contact support.</span>'
                            );
                            errorMessage.show();
                        } else {
                            response = JSON.parse(response);
                            if (response.success) {
                                $('#popup-modal').modal('closeModal');
                                $('#popup-modal-success').modal('openModal');
                                $('#subscribe_email').val('');
                                let simpleProductData = '';
                                if (typeof analytics !== 'undefined') {
                                    mageUrl.setBaseUrl(BASE_URL);
                                    let urlApi = mageUrl.build(
                                        'rest/V1/segment-analytics/products/' + simpleProductId
                                    );
                                    $.ajax({
                                        url: urlApi,
                                        type: 'GET',
                                        showLoader: false,
                                        success: function (response) {
                                            if (response) {
                                                simpleProductData = response;
                                                const EVENT_FRONT_STOCK_NOTIFICATION_REQUESTED =
                                                    'Stock Notification Requested';
                                                const name = $('.orig-name h1').text();
                                                const price = config.jsonConfig.prices.finalPrice.amount;
                                                const background = $(
                                                    '.swatch-attribute-options .selected'
                                                ).css('background');
                                                const image_url = background
                                                    .match(/url\((.*?)\)/)[1]
                                                    .replace(/('|")/g, '');

                                                const properties = {
                                                    email: email,
                                                    product_id: simpleProductData.id,
                                                    product_sku: simpleProductData.sku,
                                                    product_name: simpleProductData.name,
                                                    product_variant: color,
                                                    product_width: width,
                                                    product_size: size,
                                                    product_price: price,
                                                    product_url: productUrl,
                                                    product_image_url: image_url,
                                                };
                                                console.debug(
                                                    'sending ' +
                                                        EVENT_FRONT_STOCK_NOTIFICATION_REQUESTED +
                                                        ' event',
                                                    properties
                                                );

                                                analytics.track(
                                                    EVENT_FRONT_STOCK_NOTIFICATION_REQUESTED,
                                                    properties
                                                );
                                            }
                                        },
                                    });
                                }
                            } else {
                                errorMessage.html(`<span>${response.error_message}</span>`);
                                errorMessage.show();
                            }
                        }
                        enableSubscribe(true);
                    },
                });
            }

            /**
             * send Subscription To Listrak
             * @param emailAddress
             */
            function sendSubscriptionToListrak(emailAddress) {
                var selected_options = {};
                $('#popup-modal div.swatch-attribute').each(function (k, v) {
                    var attribute_id = $(v).attr('attribute-id');
                    var option_selected = $(v).attr('option-selected');
                    if (!attribute_id || !option_selected) {
                        return;
                    }
                    selected_options[attribute_id] = option_selected;
                });

                var swatchOptions = jQuery('[data-role=swatch-options]');

                var product_id_index = swatchOptions.data('mageSwatchRenderer').options.jsonConfig.index;
                var foundProductId = '';
                jQuery.each(product_id_index, function (product_id, attributes) {
                    var productIsSelected = function (attributes, selected_options) {
                        return _.isEqual(attributes, selected_options);
                    };
                    if (productIsSelected(attributes, selected_options)) {
                        foundProductId = product_id;
                    }
                });
                if (foundProductId) {
                    let prodOptionSku =
                        swatchOptions.data('mageSwatchRenderer').options.jsonConfig.optionPrices[
                            foundProductId
                        ].productSku;
                    (function () {
                        if (typeof _ltk == 'object') {
                            ltkCode();
                        } else {
                            (function (d) {
                                if (document.addEventListener)
                                    document.addEventListener('ltkAsyncListener', d);
                                else {
                                    var elem = document.documentElement;
                                    elem.ltkAsyncProperty = 0;
                                    elem.attachEvent('onpropertychange', function (e) {
                                        if (e.propertyName == 'ltkAsyncProperty') {
                                            d();
                                        }
                                    });
                                }
                            })(function () {
                                ltkCode();
                            });
                        }

                        function ltkCode() {
                            _ltk_util.ready(function () {
                                _ltk.Alerts.AddAlert(emailAddress, prodOptionSku, 'BIS');
                                _ltk.Alerts.Submit();
                            });
                        }
                    })();
                }
            }

            /**
             * send Newsletter Subscription
             */
            function sendSubscriptionNewsletter(email) {
                (function () {
                    if (typeof _ltk == 'object') {
                        ltkCode(email);
                    } else {
                        (function (d) {
                            if (document.addEventListener) {
                                document.addEventListener('ltkAsyncListener', d);
                            } else {
                                var elem = document.documentElement;
                                elem.ltkAsyncProperty = 0;
                                elem.attachEvent('onpropertychange', function (e) {
                                    if (e.propertyName == 'ltkAsyncProperty') {
                                        d();
                                    }
                                });
                            }
                        })(function () {
                            ltkCode(email);
                        });
                    }

                    function ltkCode(email) {
                        _ltk_util.ready(function () {
                            _ltk.Subscriber.List = 'Footer';
                            _ltk.Subscriber.Email = email;
                            _ltk.Subscriber.Profile.Add('CheckBox.Source.Product Registrations', 'on');
                            _ltk.Subscriber.Submit();
                        });
                    }
                })();
            }
        });
    };
});
