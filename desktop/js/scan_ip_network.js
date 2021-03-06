
//$(document).ready(function ($) {
//    $("#scan_ip_network").stupidtable();
//});

function btSaveCommentaires(nb){

    if(nb > 0){
        var commentaires = [];
        for (var i = 1; i <= nb; i++) {
                var val = $("#input_" + i).val();
                if (val) {
                    var id = $("#input_" + i).attr('data-id');
                    commentaires.push([{id: id}, {val: val}]);
            }
        }

        $.ajax({
            type: "POST",
            url: "plugins/scan_ip/core/ajax/scan_ip.ajax.php",
            data: {
                action: "recordCommentaires",
                data: commentaires,
            },
            dataType: 'json',
            error: function (request, status, error) {
                handleAjaxError(request, status, error);
            },
            success: function (data) {
                if (data.state != 'ok') {
                    $('#div_alert').showAlert({message: data.result, level: 'danger'});
                    return;
                }
            }
        });
    }
    
}