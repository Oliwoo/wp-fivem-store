function loginToFivem(){
    jQuery.post(
        fivem_service_option.url,
        {
            action: "doFivemLogin",
            _nonce:fivem_service_option.nonce,
            redirect:fivem_service_option.redirect,
        }
    ).done(function(res){
        window.location.href=res;
    });
    
}
function unlinkFivemAccount(){
    jQuery.post(
        fivem_service_option.url,
        {
            action: "unlinkFivem",
            _nonce:fivem_service_option.nonce,
            redirect:fivem_service_option.redirect,
        }
    ).done(function(res){
        window.location.href = location.protocol + '//' + location.host + location.pathname;
    });
}

jQuery(document).on("change",".fivem-shop-metabox-field-type-select",function(){
    var action = jQuery(this).attr("name");
    var action_value = jQuery(this).val();
    var metabox = jQuery(this).parents(".fivem-shop-metabox");
    var metabox_fields = metabox.find(".fivem-shop-metabox-field[verify="+action+"]");

    metabox_fields.each(function(key,field){
        var field = jQuery(field);
        if(field.attr("data-control-name") != action_value+"_value"){
            field.addClass("fivem-shop-metabox-field-hidden");
            field.find("input, select, checkbox").attr("required",false);
        }else{
            field.removeClass("fivem-shop-metabox-field-hidden");
            field.find("input, select, checkbox").attr("required",true);
        }
    })
})
jQuery(document).on("click",".fivem_fix_error",function(){
    var order = jQuery(this).attr("order");
    var item = jQuery(this).attr("item");
    jQuery.post(
        fivem_service_option.url,
        {
            action: "completeOrderItem",
            order: order,
            item: item,
            _nonce:fivem_service_option.nonce,
            redirect:fivem_service_option.redirect,
        }
    ).done(function(res){
        window.location.reload();
    });
})
