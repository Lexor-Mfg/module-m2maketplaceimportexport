define([
"jquery",
'Magento_Ui/js/modal/alert',
"jquery/ui",
], function ($, alert) {
    'use strict';
    console.log('test');
    $.widget('lx.import', {
        options: {},
        _create: function () {
            var self = this;
            $(document).ready(function () {
                var infoUrl = self.options.infoUrl;
                var defaultTitle = $(".wk-mu-options-content").html();
                var options = [];
               
                $(document).on('change', '#attribute_info', function (event) {
                    showLoader();
                    var code = $(this).val();
                    if (code == "") {
                        setDefaultContent(defaultTitle);
                        hideLoader();
                    } else {
                        if (code in options) {
                            setOptions(options[code]);
                            hideLoader();
                        } else {
                            $.ajax({
                                url: infoUrl,
                                type: 'POST',
                                dataType: 'json',
                                data: { code : code },
                                success: function (data) {
                                    options[code] = data;
                                    setOptions(data);
                                    hideLoader();
                                }
                            });
                        }
                    }
                });

                $(document).on('change', '#csv_file', function (event) {
                    var fileName = $(this).val();
                    var ext = fileName.split('.').pop().toLowerCase();
                    if (ext == 'csv') {
                        validateFile(ext, 'csv', $(this));
                    } else {
                        validateFile(ext, 'xls', $(this));
                    }
                });
                $(document).on('change', '#images_zip_file', function (event) {
                    var fileName = $(this).val();
                    var ext = fileName.split('.').pop().toLowerCase();
                    validateFile(ext, 'zip', $(this));
                });
                function validateFile(ext, val, obj)
                {
                    if (ext != val) {
                        alert({
                            title: 'Warning',
                            content: "<div class='wk-warning-content'>Invalid file type.</div>",
                            actions: {
                                always: function (){}
                            }
                        });
                        obj.val('');
                    }
                }
                function setDefaultContent(defaultTitle)
                {
                    $(".wk-mu-options-content").empty();
                    $(".wk-mu-options-content").append(defaultTitle);
                }
                function setOptions(json)
                {
                    $(".wk-mu-options-content").empty();
                    $.each(json, function (key, value) {
                        $(".wk-mu-options-content").append("<div class='wk-mu-options-item'>"+value+"</div>");
                    });
                }
                function showLoader()
                {
                    $(".wk-mu-sa-overlay").removeClass("wk-display-none");
                }
                function hideLoader()
                {
                    $(".wk-mu-sa-overlay").addClass("wk-display-none");
                }
            });
        }
    });
    return $.lx.import;
});
