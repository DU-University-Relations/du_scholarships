/**
 * @file
 *
 */
(function ($, Drupal, drupalSettings) {

    'use strict';

    Drupal.behaviors.scholarship = {
        attach: function (context, settings) {
            if ($('.scholarship-filter').length && !$('.scholarship-filter').hasClass('scholarship-processed')) {
                $('.scholarship-filter').once('js-scholarship').addClass('scholarship-processed');

                // Class Level Filter:
                var class_level = $("select[name='field_scholarship_class_level_value']").val();
                $('.scholarship-filter-class-level button[data-value="' + class_level + '"]').addClass('selected');
                $('.scholarship-filter-class-level button').on('click', function (e) {
                    e.preventDefault();
                    $("select[name='field_scholarship_class_level_value']").val($(this).data('value'));
                    $('.scholarship-filter-class-level button').not($(this)).removeClass('selected');
                    $(this).addClass('selected');
                    return  false;
                });

                // Kind of Scholarship Filter:
                $('.scholarship-kind-checkbox').on('change', function (e) {
                    var default_value = 'academic';
                    var $select = $("select[name='field_scholarship_kind_value']");
                    if ($(this).is(":checked")) {
                        $select.val($(this).val());
                    }
                    else {
                        $select.val(default_value);
                    }
                });

                // Search Button:
                $('.search-box button').on('click', function (e) {
                    e.preventDefault();
                    $('.scholarship-filter-submit input').click();
                    return  false;
                });

                // Major Filter:
                var $major_filter = $('select[name="field_scholarship_major_target_id"]');
                var $school_filter = $('select[name="field_scholarship_school_target_id"]');
                var set_major_filter = function () {
                    var tid = $school_filter.val();
                    var show_filter = false;
                    $('option', $major_filter).each(function () {
                        if ($(this).data('tid') == tid) {
                            show_filter = true;
                            $(this).show();
                        }
                        else {
                            $(this).hide();
                        }
                    });
                    $('option[value="All"]', $major_filter).show();
                    if (show_filter) {
                        $major_filter.prop("disabled", false);
                    }
                    else {
                        $major_filter.prop("disabled", true);
                    }
                };
                $('option', $major_filter).each(function () {
                    var option = $(this).text().split('-');
                    $(this).data('tid', option[1]);
                    $(this).text(option[0]);
                });
                set_major_filter();
                $school_filter.on('change', function () {
                    set_major_filter($(this));
                    $major_filter.val('All');
                });

                //Init Isotope -- brought over from pl_drupal/js/app.js
                if ($('.scholarship-list').length) {
                  var isotopeConf = {itemSelector: '.card-list-item', layoutMode: 'fitRows' };
                }
            }
        } // End attach function.
    };

})(jQuery, Drupal, drupalSettings);
