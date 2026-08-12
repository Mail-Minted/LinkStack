
(function ($) {

    "use strict";

    var fullHeight = function () {

        $('.js-fullheight').css('height', $(window).height());
        $(window).resize(function () {
            $('.js-fullheight').css('height', $(window).height());
        });

    };
    fullHeight();

    $('#sidebarCollapse').on('click', function () {
        $('#sidebar').toggleClass('active');
    });

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });
    // Re-callable: the Blocks tab swaps its list region's HTML after a
    // fetch-based save, which replaces #links-table-body — it calls this
    // again to bind drag-to-reorder to the new element.
    window.mmInitBlockSortable = function () {
    var sortableTbody = document.getElementById("links-table-body");
    if (sortableTbody) {
        const sortableLinkTable = Sortable.create(sortableTbody, {
            handle: ".sortable-handle",
            animation: 150,
            swapThreshold: 0.60,
            ghostClass: 'bg-soft-secondary',
            onChange: function (event) {
            },
            store: {
                get: function (sortable) {
                    // The server renders rows in their saved order, so
                    // there's no client-side order to restore. (This used
                    // to read a page-emitted global that could go stale
                    // after an in-place list refresh and scramble rows.)
                    return [];
                },
                set: function (sortable) {
                    const linkOrders = sortable.toArray();
                    const currentPage = sortableTbody.dataset.page || 1;
                    const perPage = sortableTbody.dataset.perPage || 0;
                    const formData = {
                        'linkOrders': linkOrders,
                        'currentPage': currentPage,
                        'perPage': perPage,
                    };

                    // Endpoint emitted by the Blocks tab (route('sortLinks')).
                    // The old code derived the URL from location.pathname by
                    // stripping "/studio/links" — which 404'd silently once
                    // the blocks list moved to /studio/edit, so drags never
                    // saved.
                    var url = window.mmSortLinkUrl || '/studio/sort-link';

                    $.post(url, formData, function (response) {
                        if (!response || response.status !== 'OK') {
                            alert('Could not save the new block order. Please try again.');
                            return;
                        }
                        // Saved — refresh the live preview so it reflects the
                        // new order. Use the iframe's own location.reload()
                        // instead of reassigning src: reassigning tears the
                        // document down and paints the frame's white
                        // background for a beat (which reads as the background
                        // "clearing", worst with an image background). reload()
                        // keeps the current render up until the new one paints.
                        var frame = document.getElementById('appearance-preview-iframe');
                        if (frame) {
                            try { frame.contentWindow.location.reload(); }
                            catch (e) { frame.src += ''; } // cross-origin fallback
                        }
                    }).fail(function () {
                        alert('Could not save the new block order. Please try again.');
                    });
                }
            }
        });
    }
    };
    window.mmInitBlockSortable();



})(jQuery);
