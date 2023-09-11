
{if $this_plugin_status == 'enabled'}
    <style type="text/css">
        .cards {
            display: inline-flex;
        }
        .cards li {
            width: 25%;
            padding: 3px;
        }
        .cards li img {
            vertical-align: middle;
            max-width: 100%;
            width: 37px;
            height: 27px;
        }
    </style>
    <script>
        {literal}
        
            var lunarPayment = {
                init: function() {
        {/literal}
                    var PLUGIN_PUBLIC_KEY_OBJECT = {
                        key : "{$PLUGIN_PUBLIC_KEY|escape:'htmlall':'UTF-8'}"
                    };

                    this.active_status = "{$active_status}";
                    this.sdkClient = Paylike(PLUGIN_PUBLIC_KEY_OBJECT);
                    this.shop_name = "{$shop_name|escape:'htmlall':'UTF-8'}";
                    this.PS_SSL_ENABLED = "{$PS_SSL_ENABLED|escape:'htmlall':'UTF-8'}";
                    this.host = "{$http_host|escape:'htmlall':'UTF-8'}";
                    this.BASE_URI = "{$base_uri|escape:'htmlall':'UTF-8'}";
                    this.shop_title = "{$shop_title|escape:'htmlall':'UTF-8'}";
                    this.currency_code = "{$currency_code|escape:'htmlall':'UTF-8'}";
                    this.amount = {$amount|escape:'htmlall':'UTF-8'};
                    this.exponent = {$exponent};
                    this.products = "{$products}"; //html variable can not be escaped;
                    this.products = JSON.parse(this.products.replace(/&quot;/g, '"'));
                    this.name = "{$name|escape:'htmlall':'UTF-8'}";
                    this.email = "{$email|escape:'htmlall':'UTF-8'}";
                    this.telephone = "{$telephone|escape:'htmlall':'UTF-8'}";
                    this.address = "{$address|escape:'htmlall':'UTF-8'}";
                    this.ip = "{$ip|escape:'htmlall':'UTF-8'}";
                    this.locale = "{$locale|escape:'htmlall':'UTF-8'}";
                    this.platform_version = "{$platform_version|escape:'htmlall':'UTF-8'}";
                    this.platform = "{$platform|escape:'htmlall':'UTF-8'}";
                    this.module_version = "{$module_version|escape:'htmlall':'UTF-8'}";
                    this.url_controller = "{$redirect_url|escape:'htmlall':'UTF-8'}";
                    this.pay_text = "{l s='Pay' mod=lunarpayment js=1}";
                    this.qry_str = "{$qry_str}";

        {literal}
                    /*
                    * Integration with One Page Supercheckout
                    * Skip events if One Page Supercheckout
                    */
                    if(typeof window.supercheckoutLayout === 'undefined'){
                        lunarPayment.bindPaymentMethodsClick();
                        lunarPayment.maybeBindPaymentPopup();
                        lunarPayment.bindPayPopup();
                        lunarPayment.bindTermsCheck();
                    }
                },
                pay: function() {
                    this.sdkClient.pay({
                        test: ('live' === this.active_status) ? (false) : (true),
                        title: this.shop_title,
                        amount: {
                            currency: this.currency_code,
                            exponent: this.exponent,
                            value: this.amount
                        },
                        locale: this.locale,
                        custom: {
                            products: this.products,
                            customer: {
                                name: this.name,
                                email: this.email,
                                phoneNo: this.telephone,
                                address: this.address,
                                IP: this.ip
                            },
                            platform: {
                                name: this.platform,
                                version: this.platform_version
                            },
                            PluginVersion: this.module_version
                        }
                    },
                    function (err, r) {
                        if (typeof r !== 'undefined') {
                            var return_url = lunarPayment.url_controller + lunarPayment.qry_str + 'transactionid=' + r.transaction.id;
                            if (err) {
                                return console.warn(err);
                            }
                            location.href = lunarPayment.htmlDecode(return_url);
                        }
                    });
                    lunarPayment.ifCheckedUncheck();
                },

                htmlDecode: function(url) {
                    return String(url).replace(/&amp;/g, '&');
                },
                ////////////////////////////////////////////
                ifCheckedUncheck: function() {
                    $('#conditions-to-approve input[type="checkbox"]').not(this).prop('checked', false);
                    var $paymentConfirmation = $('#payment-confirmation');
                    $paymentConfirmation.find("div").removeClass('active').addClass('disabled');
                    $paymentConfirmation.find("button").removeClass('active').addClass('disabled');
                    /*
                    * Integration with One Page Checkout v4.0.10 - by PresTeamShop
                    * Disable preloader if is defined
                    */
                    if (typeof Fronted !== 'undefined' && Fronted !== null) {
                        Fronted.loadingBig(false);
                    }
                },

                bindTermsCheck: function() {
                    $('#conditions-to-approve input[type="checkbox"]').change(function () {
                        var $paymentConfirmation = $('#payment-confirmation');
                        if ($(this).prop("checked") == true) {
                            $paymentConfirmation.find("div").removeClass('disabled').addClass('active');
                            $paymentConfirmation.find("button").removeClass('disabled').addClass('active');
                        } else {
                            $paymentConfirmation.find("div").removeClass('active').addClass('disabled');
                            $paymentConfirmation.find("button").removeClass('active').addClass('disabled');
                        }
                    });
                },

                bindPaymentMethodsClick: function() {
                    var paymentMethodsAll = document.querySelectorAll('.payment-option');
                    if (!paymentMethodsAll) return false;

                    for (var x = 0; x < paymentMethodsAll.length; x++) {
                        paymentMethodsAll[x].addEventListener("click", function (e) {
                            lunarPayment.maybeBindPaymentPopup();
                        });
                    }
                },

                bindPayPopup: function() {
                    $(`#pay-by-lunar`).on('click', function (e) {
                        e.preventDefault();
                        if (!$('#conditions-to-approve input[type="checkbox"]:checked').length) return false;
                        lunarPayment.pay();
                    });
                },

                maybeBindPaymentPopup: function() {
                    var paymentMethod = document.querySelector('input[name="payment-option"]:checked');
                    if (!paymentMethod) return false;
                    var $payButton = $(`#pay-by-lunar`);
                    var $submitButton = $('#payment-confirmation button');
                    // uncheck terms checkbox
                    lunarPayment.ifCheckedUncheck();
                    // if payment method is not this add the buttons back
                    if (paymentMethod.dataset.moduleName !== 'lunarpayment') {
                        $submitButton.removeClass('hide-element');
                        $payButton.addClass('hide-element');
                    } else {
                        if (!$payButton.length) {
                            $submitButton.after('<div ' +
                                'style="-webkit-appearance: none; background-color: #2fb5d2;" ' +
                                `class="btn btn-primary center-block disabled " id="pay-by-lunar">` + this.pay_text + '</div>');
                            lunarPayment.bindPayPopup();
                        }
                        $submitButton.addClass('hide-element');
                        $payButton.removeClass('hide-element');

                    }
                }
                ////////////////////////////////////////////
            };

            /*
            * Integration with One Page Supercheckout
            * Init SDK
            */
            if (typeof window.supercheckoutLayout !== 'undefined' && typeof window.initialized === 'undefined') {
                $.getScript('https://sdk.paylike.io/a.js',function(){
                    initialized = true;
                });
            }

            /*
            * Integration with One Page Checkout v4.0.10 - by PresTeamShop
            * Init SDK
            */
            if (typeof OnePageCheckoutPS !== typeof undefined) {
                $(document).on('opc-load-review:completed', function() {
                    $.getScript('https://sdk.paylike.io/a.js',function(){
                        lunarPayment.init();
                    });
                });
            } else {
                /*
                * Default
                * Init SDK
                */
                document.addEventListener("DOMContentLoaded", function(event) {
                    $.getScript('https://sdk.paylike.io/a.js',function(){
                        lunarPayment.init();
                    });
                });
            }
        {/literal}
    </script>

    <style>
        .hide-element {
            display: none !important;
        }
    </style>
    <div class="row">
        <div class="col-xs-12 col-md-12">
            <div class="payment_module lunar-payment clearfix"
                 style="
                         border: 1px solid #d6d4d4;
                         border-radius: 4px;
                         color: #333333;
                         display: block;
                         font-size: 17px;
                         font-weight: bold;
                         letter-spacing: -1px;
                         line-height: 23px;
                         padding: 20px 20px;
                         position: relative;
                         cursor:pointer;
                         margin-top: 10px;
                 {*" onclick="pay();" >*}
                         ">
                <input style="float:left;" id="lunar-btn" type="image" name="submit"
                       src="{$this_plugin_path}logo.png" alt=""
                       style="vertical-align: middle; margin-right: 10px; width:57px; height:57px;"/>
                <div style="float:left; width:100%">
                    <span style="margin-right: 10px;">{l s={$payment_method_title} mod=lunarpayment }</span>
                    <span>
                        <ul class="cards">
                            {foreach from=$payment_method_creditcard_logo item=logo}
                                <li>
                                    <img src="{$this_plugin_path}/views/img/{$logo}" title="{$logo}" alt="{$logo}"/>
                                </li>
                            {/foreach}
                        </ul>
                    </span>
                    <small style="font-size: 12px; display: block; font-weight: normal; letter-spacing: 1px; max-width:100%;">{l s={$payment_method_desc} mod=lunarpayment }</small>
                </div>
            </div>
        </div>
    </div>
{/if}
