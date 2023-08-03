let pluginVendorName = 'lunar';
const PLUGIN_PAYMENT_METHOD_CREDITCARD_LOGO = 'LUNAR_PAYMENT_METHOD_CREDITCARD_LOGO';
const PLUGIN_TRANSACTION_MODE = 'LUNAR_TRANSACTION_MODE';
const PLUGIN_TEST_SECRET_KEY = 'LUNAR_TEST_SECRET_KEY';
const PLUGIN_TEST_PUBLIC_KEY = 'LUNAR_TEST_PUBLIC_KEY';
const PLUGIN_LIVE_SECRET_KEY = 'LUNAR_LIVE_SECRET_KEY';
const PLUGIN_LIVE_PUBLIC_KEY = 'LUNAR_LIVE_PUBLIC_KEY';


$(document).ready(function () {
    var html = '<a href="#" class="add-more-btn" data-toggle="modal" data-target="#logoModal"><i class="process-icon-plus" data-toggle="tooltip" title="Add your own logo"></i></a>';
    $(`select[name="${PLUGIN_PAYMENT_METHOD_CREDITCARD_LOGO}[]"]`).parent('div').append(html);

    $('[data-toggle="tooltip"]').tooltip();

    $(`.${pluginVendorName}-config`).each(function (index, item) {
        if ($(item).hasClass('has-error')) {
            $(item).parents('.form-group').addClass('has-error');
        }
    });

    $(`.${pluginVendorName}-language`).bind('change', moduleLanguageChange);
    $('#logo_form').on('submit', ajaxSaveLogo);

});

function moduleLanguageChange(e) {
    var lang_code = $(e.currentTarget).val();
    window.location = admin_orders_uri + "&change_language&lang_code=" + lang_code;
}

function ajaxSaveLogo(e) {
    e.preventDefault();
    $('#save_logo').button('loading');
    $('#alert').html("").hide();
    var url = $('#logo_form').attr('action');
    url = url + "&token=" + tok;

    //grab all form data
    var formData = new FormData($(this)[0]);
    //formData.append("token", token);
    $.ajax({
        url: url,
        type: 'POST',
        data: formData,
        dataType: 'json',
        async: false,
        cache: false,
        contentType: false,
        processData: false,
        success: function (response) {
            console.log(response);
            $('#save_logo').button('reset');
            if (response.status == 0) {
                var html = "<strong>Error!</strong> " + response.message;
                $('#alert').html(html)
                    .show()
                    .removeClass('alert-success')
                    .removeClass('alert-danger')
                    .addClass('alert-danger');
            } else if (response.status == 1) {
                var html = "<strong>Success!</strong> " + response.message;
                $('#alert').html(html)
                    .show()
                    .removeClass('alert-success')
                    .removeClass('alert-danger')
                    .addClass('alert-success');

                window.location = window.location;
            }
        },
        error: function (response) {
            console.log(response);
        },
    });

    return false;
}

$(function() {
    /** Triggers for hide/show LIVE/TEST INPUTS */
    $(document).ready(checkTransactionMode);
});

/** Function to hide or show LIVE/TEST inputs on module configuration page */
function checkTransactionMode() {
    if ("debug" !== document.location.search.match(/debug/gi)?.toString() && "live" === $(`#${PLUGIN_TRANSACTION_MODE}`).val()) {
        $(`#${PLUGIN_TRANSACTION_MODE}`).closest(".form-group").hide();
        $(`#${PLUGIN_TEST_SECRET_KEY}`).closest(".form-group").hide();
        $(`#${PLUGIN_TEST_PUBLIC_KEY}`).closest(".form-group").hide();
    }
}
