
{if $lunar_order.valid == 1}
    <div class="conf alert alert-success">
        {l s='Congratulations, your payment has been approved' mod=lunarpayment }</div>
    </div>
{else}
    <div class="error alert alert-danger">
        {l s='Unfortunately, an error occurred while processing the transaction.' mod=lunarpayment }<br/><br/>
        {l s='We noticed a problem with your order. If you think this is an error, feel free to contact our' mod=lunarpayment }
        <a href="{$link->getPageLink('contact', true)|escape:'htmlall':'UTF-8'}">{l s='customer support team' mod=lunarpayment }</a>.
    </div>
{/if}
