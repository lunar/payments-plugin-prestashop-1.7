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

<div class="row">
    <div class="col-xs-12 col-md-12">
        <div class="payment_module lunar-payment clearfix"
                style=" border: 1px solid #d6d4d4; border-radius: 4px; display: block; font-size: 17px; font-weight: bold; padding: 20px 20px;">
            
            <img src="{$module_path}logo.png" style="float:left; vertical-align: middle; margin-right: 10px; width:57px; height:57px;" alt="" />
            
            <div style="float:left; width:100%">
                <span style="margin-right: 10px;">{l s={$lunar_card_title} mod=lunarpayment}</span>
                <span>
                    <ul class="cards">
                        {foreach from=$accepted_cards item=logo}
                            <li>
                                <img src="{$module_path}/views/img/{$logo}" title="{$logo}" alt="{$logo}"/>
                            </li>
                        {/foreach}
                    </ul>
                </span>
                <small style="font-size: 12px; display: block; font-weight: normal; letter-spacing: 1px; max-width:100%;">
                    {l s={$lunar_card_desc} mod=lunarpayment}
                </small>
            </div>

        </div>
    </div>
</div>
