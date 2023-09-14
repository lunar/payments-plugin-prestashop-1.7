<script type="text/javascript">

    $(document).ready(function () {
        $(`.lunar-config`).each(function (index, item) {
            if ($(item).hasClass('has-error')) {
                $(item).parents('.form-group').addClass('has-error');
            }
        });

        $(`.lunar-language`).on('change', (e) => {
            window.location = "{$request_uri}" + "&change_language&lang_code=" + $(e.currentTarget).val();
        });

        /** Hide or show TEST inputs on module configuration page */
        if ("debug" !== document.location.search.match(/debug/gi)?.toString() && "live" === $(`#LUNAR_TRANSACTION_MODE`).val()) {
            $(`#LUNAR_TRANSACTION_MODE`).closest(".form-group").hide();
            $(`#LUNAR_TEST_SECRET_KEY`).closest(".form-group").hide();
            $(`#LUNAR_TEST_PUBLIC_KEY`).closest(".form-group").hide();
        }
    });

</script>



