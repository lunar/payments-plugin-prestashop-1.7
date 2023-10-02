<script>
{literal}
    document.addEventListener("DOMContentLoaded", function() {
        setTimeout(() => {
            $('#conditions-to-approve input[type="checkbox"]').prop('checked', false);
        }, 100)
    });
{/literal}
</script>

<style type="text/css">
    label > img[src*="mobilepay-logo.png"] {
        max-height: 20px;
        margin-left: 5px;
    }
    
    #mobilepay-logo {
        height: 50px;
    }
</style>

<div class="row">
    <div class="col-xs-12 col-md-12">
        <div class="payment_module lunar-payment clearfix"
                style=" border: 1px solid #d6d4d4; border-radius: 4px; display: block; font-size: 17px; font-weight: bold; padding: 20px 20px;">
            
            <img src="{$module_path}logo.png" style="float:left; vertical-align: middle; margin-right: 10px; width:57px; height:57px;" alt="" />
            
            <div style="float:left; width:100%">
                <span style="margin-right: 10px;">{l s={$lunar_mobilepay_title} mod=lunarpayment }</span>
                <span>
                    <img id="mobilepay-logo" src="{$module_path}views/img/mobilepay-logo.png" title="mobilepay-logo" alt="mobilepay-logo"/>
                </span>
                <small style="font-size: 12px; display: block; font-weight: normal; letter-spacing: 1px; max-width:100%;">
                    {l s={$lunar_mobilepay_desc} mod=lunarpayment }
                </small>
            </div>

        </div>
    </div>
</div>
