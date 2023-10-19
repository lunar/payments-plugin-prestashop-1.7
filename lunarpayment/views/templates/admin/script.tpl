<script type="text/javascript">

    $(document).ready(function () {
        $(`.lunar-config`).each(function (index, item) {
            if ($(item).hasClass('has-error')) {
                $(item).parents('.form-group').addClass('has-error');
            }
        });

        // $(`.lunar-language`).on('change', (e) => {
        //     window.location = "{$request_uri}" + "&change_language&lang_code=" + $(e.currentTarget).val();
        // });
    });

</script>



