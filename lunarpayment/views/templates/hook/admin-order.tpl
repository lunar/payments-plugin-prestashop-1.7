{*
* Lunar
*
*  @author     Lunar
*  @copyright  Lunar
*  @license    MIT license: https://opensource.org/licenses/MIT
*}

{*
    Initialize the plugin code for use below.
*}
{assign var='pluginVendorCode' value='lunarpayment'}

<script>
{literal}
    /* Load php data */
    const captured = '{/literal}{$lunartransaction["captured"]}{literal}';
    const module_payment_not_captured = '{/literal}{$not_captured_text}{literal}';
    const payment_select_refund = '{/literal}{$checkbox_text}{literal}';
    const module_vendor_name = 'Lunar';
    const module_vendor_name_lower = module_vendor_name.toLowerCase();

    /* Add Checkbox */
    $(document).ready(() => {
        /* Display message if transaction is not captured */
        let messageBox = `<p id="doRefund${module_vendor_name}" class="checkbox" style="color:red">` + module_payment_not_captured + `</p>`;

        /* Make partial order refund in Order page */
        if($("#desc-order-partial_refund").length){
            /* For prestashop version < 1.7.7 */
            let appendEl = $('select[name=id_order_state]').parents('form').after($('<div/>'));
            $(`#${module_vendor_name_lower}`).appendTo(appendEl);
            $(`#${module_vendor_name_lower}_action`).bind('change', modulePaymentActionChangeHandler);
            $(`#submit_${module_vendor_name_lower}_action`).bind('click', submitModulePaymentActionClickHandler);

            $(document).bind('click', '#desc-order-partial_refund', function(){
                /* Create checkbox and insert for payment refund */
                if ($(`#doRefund${module_vendor_name}`).length == 0) {
                    let newCheckBox = `<p class="checkbox">
                                            <label for="doRefund${module_vendor_name}">
                                                <input type="checkbox" id="doRefund${module_vendor_name}" name="doRefund${module_vendor_name}" value="1">${payment_select_refund}
                                            </label>
                                        </p>`;
                    if(captured == "NO"){
                        newCheckBox = messageBox;
                    }
                    $('button[name=partialRefund]').parent('.partial_refund_fields').prepend(newCheckBox);
                }
            });
        }else{
            /* For prestashop version >= 1.7.7 */
            $(`#${module_vendor_name_lower}`).remove();
            $(document).on('click', '.partial-refund-display ,.return-product-display, .standard-refund-display', function(){
                /* Create checkbox and insert for select refund */
                if ($(`#doRefund${module_vendor_name}`).length == 0) {
                    newCheckBox = `
                            <div class="cancel-product-element form-group" style="display: block;">
                                    <div class="checkbox">
                                        <div class="md-checkbox md-checkbox-inline">
                                        <label>
                                            <input type="checkbox" id="doRefund${module_vendor_name}" name="doRefund${module_vendor_name}" material_design="material_design" checked value="1">
                                            <i class="md-checkbox-control"></i>
                                                ${payment_select_refund}
                                            </label>
                                        </div>
                                    </div>
                            </div>`;
                    /* Display message if transaction is not captured */
                    if(captured == "NO"){
                        newCheckBox = messageBox;
                    }
                    $('.refund-checkboxes-container').prepend(newCheckBox);
                    /* Init checkboxes link */
                    initLinkedCheckboxes("#cancel_product_credit_slip",`#doRefund${module_vendor_name}`);
                }
            });
        }
    });

    function initLinkedCheckboxes(slipCheckboxId, checkboxId){
        /* Skip if "Generate a credit slip" is not present */
        if(!$(slipCheckboxId).length)
            return false;

        /* Make "Refund" checkbox dependent on "Generate a credit slip" checkbox */
        $(checkboxId).change(function() {
            if(this.checked) {
                $(slipCheckboxId).prop("checked", 1);
            }
        });

        /* Make "Generate a credit slip" checkbox dependent on "Refund" checkbox */
        $(slipCheckboxId).change(function() {
            if(!this.checked) {
                $(checkboxId).prop("checked", 0);
            }
        });
    }

    function modulePaymentActionChangeHandler(e) {
        var option_value = $(`#${module_vendor_name_lower}_action option:selected`).val();
        if (option_value == 'refund') {
            $(`input[name="${module_vendor_name_lower}_amount_to_refund"]`).show();
        } else {
            $(`input[name="${module_vendor_name_lower}_amount_to_refund"]`).hide();
        }
    }

    function submitModulePaymentActionClickHandler(e) {
        e.preventDefault();
        $('#alert').hide();
        var payment_action = $(`#${module_vendor_name_lower}_action`).val();
        var errorFlag = false;
        if (payment_action == '') {
            var html = '<strong>Warning!</strong> Please select an action.';
            errorFlag = true;
        } else if (payment_action == 'refund') {
            var refund_amount = $(`input[name="${module_vendor_name_lower}_amount_to_refund"]`).val();
            var html = '';
            if (refund_amount == '') {
                var html = '<strong>Warning!</strong> Please provide the refund amount.';
                errorFlag = true;
            }
        }
        if (errorFlag) {
            $('#alert').html(html);
            $('#alert').removeClass('alert-success')
                .removeClass('alert-info')
                .removeClass('alert-warning')
                .removeClass('alert-danger')
                .addClass('alert-warning');
            $('#alert').show();
            return false;
        }
        /* Make an AJAX call for payment action */
        $(e.currentTarget).button('loading');
        var url = $(`#${module_vendor_name_lower}_form`).attr('action');
        $.ajax({
            url: url,
            type: 'POST',
            data: $(`#${module_vendor_name_lower}_form`).serializeArray(),
            dataType: 'JSON',
            success: function (response) {
                $(e.currentTarget).button('reset');
                console.log(response);
                if (response.hasOwnProperty('success') && response.hasOwnProperty('message')) {
                    var message = response.message;
                    var html = '<strong>Success!</strong> ' + message;
                    $('#alert').html(html);
                    $('#alert').removeClass('alert-success')
                        .removeClass('alert-info')
                        .removeClass('alert-warning')
                        .removeClass('alert-danger')
                        .addClass('alert-success');
                    $('#alert').show();
                    setTimeout(function () {
                        console.log('page reloaded');
                        location.reload();
                    }, 1500)
                } else if (response.hasOwnProperty('warning') && response.hasOwnProperty('message')) {
                    var message = response.message;
                    var html = '<strong>Warning!</strong> ' + message;
                    $('#alert').html(html);
                    $('#alert').removeClass('alert-success')
                        .removeClass('alert-info')
                        .removeClass('alert-warning')
                        .removeClass('alert-danger')
                        .addClass('alert-warning');
                    $('#alert').show();
                } else if (response.hasOwnProperty('error') && response.hasOwnProperty('message')) {
                    var message = response.message;
                    var html = '<strong>Error!</strong> ' + message;
                    $('#alert').html(html);
                    $('#alert').removeClass('alert-success')
                        .removeClass('alert-info')
                        .removeClass('alert-warning')
                        .removeClass('alert-danger')
                        .addClass('alert-danger');
                    $('#alert').show();
                }
            },
            error: function (response) {
                console.log(response);
            }
        });
    }
{/literal}
</script>


<div id="lunar" class="row" style="margin-top:5%;">
    <div class="panel">
        <form id="lunar_form"
                action="{$link->getAdminLink('AdminOrders', false)|escape:'htmlall':'UTF-8'}&amp;id_order={$id_order|escape:'htmlall':'UTF-8'}&amp;vieworder&amp;token={$order_token|escape:'htmlall':'UTF-8'}"
                method="post">
            <fieldset {if $ps_version < 1.5}style="width: 400px;"{/if}>
                <legend class="panel-heading">
                    <img src="../img/os/7.gif" alt=""/>{l s='Process Lunar Payment' mod={$pluginVendorCode} }
                </legend>
                <div id="alert" class="alert" style="display: none;"></div>
                <div class="form-group margin-form">
                    <select class="form-control" id="lunar_action" name="lunar_action">
                        <option value="">{l s='-- Select Lunar Action --' mod={$pluginVendorCode} }</option>
                        {if $lunartransaction['captured'] == "NO"}
                            <option value="capture">{l s='Capture' mod={$pluginVendorCode} }</option>
                        {/if}
                        <option value="refund">{l s='Refund' mod={$pluginVendorCode} }</option>
                        {if $lunartransaction['captured'] == "NO"}
                            <option value="void">{l s='Void' mod={$pluginVendorCode} }</option>
                        {/if}
                    </select>
                </div>

                <div class="form-group margin-form">
                    <div class="col-md-12">
                        <input class="form-control" name="lunar_amount_to_refund" style="display: none;"
                                placeholder="{l s='Amount to refund' mod={$pluginVendorCode} }" type="text"/>
                    </div>
                </div>

                <div class="form-group margin-form">
                    <input class="pull-right btn btn-default" name="submit_lunar_action" id="submit_lunar_action"
                            type="submit" class="btn btn-primary" value="{l s='Process Action' mod={$pluginVendorCode} }"/>
                </div>
            </fieldset>
        </form>
    </div>
</div>