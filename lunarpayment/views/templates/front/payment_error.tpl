{extends 'page.tpl'}

{block name='page_content'}

    {if $lunar_order_error == 1}
        <div class="error alert alert-danger">
            {l s='Unfortunately, an error occurred while processing the transaction.' mod=lunarpayment }<br/><br/>
            {if !empty($lunar_error_message) }
                {l s='ERROR : "' mod=lunarpayment }{l s={$lunar_error_message} mod=lunarpayment }{l s='"' mod=lunarpayment }
                <br/>
                <br/>
            {/if}

            {l s='Your order cannot be created. If you think this is an error, feel free to contact our' mod=lunarpayment }
            <a href="{$link->getPageLink('contact', true)|escape:'htmlall':'UTF-8'}">{l s='customer support team' mod=lunarpayment }</a>
        </div>
    {/if}

{/block}
