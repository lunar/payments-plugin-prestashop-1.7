
$(document).ready(function () {
    var html = '<a href="#" class="add-more-btn" data-toggle="modal" data-target="#logoModal"><i class="process-icon-plus" data-toggle="tooltip" title="Add your own logo"></i></a>';
    $(`select[name="LUNAR_ACCEPTED_CARDS[]"]`).parent('div').append(html);

    $('[data-toggle="tooltip"]').tooltip();

    $(`.lunar-config`).each(function (index, item) {
        if ($(item).hasClass('has-error')) {
            $(item).parents('.form-group').addClass('has-error');
        }
    });

    $(`.lunar-language`).bind('change', moduleLanguageChange);
    $('#logo_form').on('submit', ajaxSaveLogo);

    /** Hide or show TEST inputs on module configuration page */
    if ("debug" !== document.location.search.match(/debug/gi)?.toString() && "live" === $(`#LUNAR_TRANSACTION_MODE`).val()) {
        $(`#LUNAR_TRANSACTION_MODE`).closest(".form-group").hide();
        $(`#LUNAR_TEST_SECRET_KEY`).closest(".form-group").hide();
        $(`#LUNAR_TEST_PUBLIC_KEY`).closest(".form-group").hide();
    }
});

function moduleLanguageChange(e) {
    var lang_code = $(e.currentTarget).val();
    window.location = lunarpayment.admin_orders_uri + "&change_language&lang_code=" + lang_code;
}

function ajaxSaveLogo(e) {
    e.preventDefault();

    $('#save_logo').button('loading');
    $('#alert').html("").hide();

    let uploadLogoUrl = $('#logo_form').attr('action') + "&token=" + lunarpayment.tok;

    //grab all form data
    var formData = new FormData($(this)[0]);
    //formData.append("token", token);
    $.ajax({
        url: uploadLogoUrl,
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

                window.location.reload();
            }
        },
        error: function (error) {
            console.error(error);
        },
    });

    return false;
}