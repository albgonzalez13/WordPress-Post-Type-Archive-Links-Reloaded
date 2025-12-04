jQuery(function($){
    $('#submit-post-type-archives').on('click', function(e){
        e.preventDefault();

        var items = [];
        $('#' + hptal_obj.metabox_list_id + ' input[type="checkbox"]:checked').each(function(){
            items.push($(this).val());
        });

        if (items.length === 0) {
            return;
        }

        var data = {
            action: hptal_obj.action,
            nonce: hptal_obj.nonce,
            post_types: items,
            menu: $('#menu').val()
        };

        $.post(hptal_obj.ajaxurl, data, function(response){
            $('#menu-to-edit').append(response);
        });
    });
});
