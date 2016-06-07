

jQuery(function($){


    var HOST = 'https://www.bablic.com';

    var $siteId = $('#bablic_item_site_id');
    if(!$siteId.length)
        return;

    var existingSite = $siteId.val();
    bablic.channel('wp');

    $('#bablic_clear_cache').click(function(){
        var $button = $(this).attr('disabled','disabled');
        jQuery.ajax({
            url: ajaxurl,
            type: "post",
            data: {
                action:'bablicClearCache'
            },
            dataType: "json",
            async: !0
        }).always(function(){
            $button.removeAttr('disabled');
        });
    });

    $('#bablic_dont_permalink').change(function(e){
        var $button = $(this).attr('disabled','disabled');
        jQuery.ajax({
            url: ajaxurl,
            type: "post",
            data: {
                action:'bablicSettings',
                data:{
                    action:'subdir',
                    on:$button.is(':checked')
                }
            },
            dataType: "json",
            async: !0
        }).always(function(){
            $button.removeAttr('disabled');
        });
    });

    $('#bablicCreate').click(function(){
        var $button = $(this).attr('disabled','disabled');
        jQuery.ajax({
            url: ajaxurl,
            type: "post",
            data: {
                action:'bablicSettings',
                data:{
                    action:'create'
                }
            },
            dataType: "json",
            async: !0
        }).always(function(data){
            $button.removeAttr('disabled');
            $('#bablicEditor').data('url',data.editor);
            existingSiteMode();
        });
    });

    $('#bablicSet').click(function(){
        bablic.chooseSite(function(err,site){
            if(err){
                console.error(err);
                return alert('Error');
            }
            if(!site)
                return $('#bablicCreate').click();
            bablic.getSite({id:site.id},function(err,site){
                if(err || !site){
                    console.error(err);
                    return alert('Error creating site. Site is already registered in Bablic');
                }
                jQuery.ajax({
                    url: ajaxurl,
                    type: "post",
                    data: {
                        action:'bablicSettings',
                        data:{
                            action:'set',
                            site:site
                        }
                    },
                    dataType: "json",
                    async: !0
                })
                    .fail(function(error){
                        console.error(error);
                        alert('Error creating site. Site is already registered in Bablic');
                    })
                    .done(function(data){
                        if(data.error)
                            return alert('Error creating site. Site is already registered in Bablic');
                        $('#bablicEditor').data('url',data.editor);
                        existingSiteMode();
                    });
            });
        })
    });

    $('#bablicEditor').click(function(){
        window.open($(this).data('url'));
    });

    $('#bablic_delete_account').click(function(){
        if(!confirm('Deleted account cannot be recovered. Are you sure?'))
            return;
        var $button = $(this).attr('disabled','disabled');
        jQuery.ajax({
            url: ajaxurl,
            type: "post",
            data: {
                action:'bablicSettings',
                data:{
                    action:'delete'
                }
            },
            dataType: "json",
            async: !0
        }).always(function(data){
            $button.removeAttr('disabled');
            newSiteMode();
        });
    });

    function existingSiteMode(){
        $('.bablicFirstTime').hide();
        $('.bablicSecondTime').show();
    }

    function newSiteMode(){
        $('.bablicFirstTime').show();
        $('.bablicSecondTime').hide();
    }

    if(existingSite)
        existingSiteMode();
    else
        newSiteMode();

});

